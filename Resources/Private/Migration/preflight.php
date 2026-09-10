#!/usr/bin/env php
<?php

declare(strict_types = 1);

/*
 * Standalone, read-only source inventory. It intentionally does not load the
 * TYPO3 or extension autoloader and supports PHP 8.1+ with PDO MySQL/SQLite.
 */

const EXIT_READY = 0;
const EXIT_ALREADY_MIGRATED = 10;
const EXIT_BLOCKED = 20;
const EXIT_INDETERMINATE = 30;
const EXIT_ERROR = 40;

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: $message\n");
    exit(EXIT_ERROR);
}

function canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as &$item) {
        $item = canonicalize($item);
    }
    return $value;
}

function canonicalJson(array $value): string
{
    return json_encode(canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function identifier(string $name, string $driver): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
        fail('Unsafe database identifier encountered.');
    }
    return $driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
}

/** @return array<string, array<string, string>> */
function schema(PDO $pdo, string $driver): array
{
    $result = [];
    if ($driver === 'mysql') {
        $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
        if ($tables === false) {
            throw new RuntimeException('Cannot enumerate tables.');
        }
        foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $statement = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
            $statement->execute([$table]);
            $result[(string)$table] = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $result[(string)$table][(string)$column['COLUMN_NAME']] = (string)$column['COLUMN_TYPE'];
            }
        }
    } else {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        if ($tables === false) {
            throw new RuntimeException('Cannot enumerate tables.');
        }
        foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $result[(string)$table] = [];
            $statement = $pdo->query('PRAGMA table_info(' . identifier((string)$table, $driver) . ')');
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $result[(string)$table][(string)$column['name']] = (string)$column['type'];
            }
        }
    }
    return $result;
}

/** @return array{count:int, fingerprint:string} */
function fingerprintTable(PDO $pdo, string $driver, string $table, array $columns): array
{
    $hash = hash_init('sha256');
    $count = 0;
    $quotedColumns = implode(', ', array_map(static fn (string $column): string => identifier($column, $driver), $columns));
    $order = in_array('uid', $columns, true) ? identifier('uid', $driver) : $quotedColumns;
    $statement = $pdo->query('SELECT ' . $quotedColumns . ' FROM ' . identifier($table, $driver) . ' ORDER BY ' . $order);
    if ($statement === false) {
        throw new RuntimeException('Cannot fingerprint ' . $table . '.');
    }
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        hash_update($hash, canonicalJson($row) . "\n");
        ++$count;
    }
    return ['count' => $count, 'fingerprint' => hash_final($hash)];
}

/** @return array<string, string> */
function flexValues(string $xml): array
{
    if ($xml === '') {
        return [];
    }
    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        throw new RuntimeException('DOCTYPE or ENTITY is forbidden in pi_flexform.');
    }
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        throw new RuntimeException('Malformed pi_flexform XML.');
    }
    $values = [];
    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//field[@index]') ?: [] as $field) {
        if (!$field instanceof DOMElement) {
            continue;
        }
        foreach ($xpath->query('.//value[@index]', $field) ?: [] as $value) {
            if ($value instanceof DOMElement && trim($value->textContent) !== '') {
                $key = $field->getAttribute('index') . '@' . $value->getAttribute('index');
                $values[$key] = trim($value->textContent);
            }
        }
    }
    ksort($values, SORT_STRING);
    return $values;
}

/** @return list<array<string, mixed>> */
function scanProject(string $root): array
{
    $findings = [];
    if ($root === '' || !is_dir($root)) {
        return $findings;
    }
    $allowed = ['php', 'typoscript', 'tsconfig', 'yaml', 'yml', 'xml', 'html', 'json'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink() || !in_array(strtolower($file->getExtension()), $allowed, true) || $file->getSize() > 2_000_000) {
            continue;
        }
        $path = $file->getPathname();
        if (preg_match('~/(?:vendor|var|typo3temp|\.git|node_modules)/~', str_replace('\\', '/', $path))) {
            continue;
        }
        $contents = file_get_contents($path);
        if ($contents !== false && preg_match('/typo3forum_(?:pi1|widget)|tt_content\.(?:list_type|CType)|tx_typo3forum_pi1/', $contents)) {
            $findings[] = ['path' => substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1), 'kind' => 'legacy_or_plugin_reference'];
        }
    }
    return $findings;
}

try {
    if (PHP_VERSION_ID < 80100) {
        fail('PHP 8.1 or newer is required.');
    }
    $options = getopt('', ['dsn::', 'user-env::', 'password-env::', 'output:', 'project-root::', 'typo3-version::', 'extension-version::', 'expected-legacy-uids::']);
    $output = isset($options['output']) ? (string)$options['output'] : '';
    if ($output === '') {
        fail('Usage: preflight.php --output=/protected/path/manifest.json [--project-root=/path]');
    }
    $dsn = (string)($options['dsn'] ?? getenv('FORUM_MIGRATION_DSN') ?: '');
    $userVariable = (string)($options['user-env'] ?? 'FORUM_MIGRATION_DB_USER');
    $passwordVariable = (string)($options['password-env'] ?? 'FORUM_MIGRATION_DB_PASSWORD');
    if ($dsn === '') {
        fail('Set FORUM_MIGRATION_DSN or pass a password-free --dsn.');
    }
    if (preg_match('/(?:password|passwd|pwd)=/i', $dsn)) {
        fail('Credentials are forbidden in the DSN; use environment variables.');
    }
    $pdo = new PDO($dsn, getenv($userVariable) ?: null, getenv($passwordVariable) ?: null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['mysql', 'sqlite'], true)) {
        fail('Only PDO MySQL/MariaDB and SQLite are supported by this preflight.');
    }
    $contract = json_decode((string)file_get_contents(__DIR__ . '/contract-v1.json'), true, 512, JSON_THROW_ON_ERROR);
    $databaseSchema = schema($pdo, $driver);
    $expectedLegacyUids = [];
    foreach (array_filter(explode(',', (string)($options['expected-legacy-uids'] ?? ''))) as $uid) {
        if (!ctype_digit($uid) || (int)$uid < 1) {
            fail('--expected-legacy-uids must be a comma-separated list of positive integers.');
        }
        $expectedLegacyUids[] = (int)$uid;
    }
    $expectedLegacyUids = array_values(array_unique($expectedLegacyUids));
    $findings = [];
    $records = [];
    $counts = ['standard' => [], 'legacy_pi1' => 0, 'migrated' => [], 'unknown' => 0];
    $listTypeState = 'missing';
    if (isset($databaseSchema['tt_content'])) {
        $columns = $databaseSchema['tt_content'];
        $hasCType = isset($columns['CType']);
        $hasListType = isset($columns['list_type']);
        $listTypeState = $hasListType ? 'present' : 'missing';
        if (!$hasListType) {
            $renamedCandidates = array_values(array_filter(array_keys($columns), static fn (string $column): bool => $column !== 'list_type' && (bool)preg_match('/(?:^|_)list_type$/i', $column)));
            if ($renamedCandidates !== []) {
                $listTypeState = 'possible_renamed';
                $findings[] = ['severity' => 'indeterminate', 'code' => 'POSSIBLE_RENAMED_LIST_TYPE_SOURCE', 'columns' => $renamedCandidates];
            }
            if ($expectedLegacyUids !== []) {
                $findings[] = ['severity' => 'blocker', 'code' => 'LIST_TYPE_MISSING_WITH_EXPECTED_LEGACY_RECORDS', 'uids' => $expectedLegacyUids];
            }
        }
        if (!$hasCType) {
            $findings[] = ['severity' => 'error', 'code' => 'TT_CONTENT_CTYPE_MISSING'];
        } else {
            $selected = array_values(array_intersect($contract['content_fields'], array_keys($columns)));
            $sql = 'SELECT ' . implode(', ', array_map(static fn (string $column): string => identifier($column, $driver), $selected))
                . ' FROM ' . identifier('tt_content', $driver) . ' ORDER BY ' . identifier('uid', $driver);
            foreach ($pdo->query($sql) ?: [] as $row) {
                $cType = (string)($row['CType'] ?? '');
                $listType = (string)($row['list_type'] ?? '');
                $relevant = isset($contract['standard_plugins'][$listType]) || in_array($listType, $contract['legacy_signatures'], true)
                    || in_array($cType, $contract['standard_plugins'], true) || str_starts_with($listType, 'typo3forum_');
                if (!$relevant) {
                    continue;
                }
                $record = $row;
                $record['fingerprint'] = hash('sha256', canonicalJson($row));
                if (isset($record['pi_flexform'])) {
                    try {
                        $record['flexform_values'] = flexValues((string)$record['pi_flexform']);
                        $record['pi_flexform_sha256'] = hash('sha256', (string)$record['pi_flexform']);
                    } catch (Throwable $exception) {
                        $record['flexform_error'] = $exception->getMessage();
                        $findings[] = ['severity' => 'blocker', 'code' => 'INVALID_FLEXFORM', 'uid' => (int)$row['uid']];
                    }
                    unset($record['pi_flexform']);
                }
                $records[] = $record;
                if ($cType === 'list' && isset($contract['standard_plugins'][$listType])) {
                    $counts['standard'][$listType] = ($counts['standard'][$listType] ?? 0) + 1;
                } elseif ($cType === 'list' && $listType === 'typo3forum_pi1') {
                    ++$counts['legacy_pi1'];
                    if (!isset($record['flexform_error'])) {
                        $actionValues = [];
                        $fieldNames = [];
                        foreach ($record['flexform_values'] ?? [] as $key => $value) {
                            [$fieldName] = explode('@', $key, 2);
                            $fieldNames[$fieldName] = true;
                            if ($fieldName === 'switchableControllerActions') {
                                $actionValues[preg_replace('/\s+/', '', $value) ?? ''] = true;
                            }
                        }
                        $actions = array_keys(array_filter($actionValues, static fn (bool $present, string $action): bool => $present && $action !== '', ARRAY_FILTER_USE_BOTH));
                        if (count($actions) !== 1 || !isset($contract['legacy_pi1'][$actions[0]])) {
                            $findings[] = ['severity' => 'blocker', 'code' => 'UNKNOWN_PI1_CONFIGURATION', 'uid' => (int)$row['uid'], 'actions' => $actions];
                        } else {
                            $rule = $contract['legacy_pi1'][$actions[0]];
                            $unknownFields = array_values(array_diff(array_keys($fieldNames), $rule['allowed_fields']));
                            if ($unknownFields !== []) {
                                $findings[] = ['severity' => 'blocker', 'code' => 'PI1_FIELDS_REQUIRE_DECISION', 'uid' => (int)$row['uid'], 'fields' => $unknownFields];
                            }
                            $record['pi1_resolution'] = ['action' => $actions[0], 'target' => $rule['target'], 'rule_id' => $rule['rule_id'], 'provenance' => $rule['provenance']];
                        }
                    }
                } elseif (in_array($cType, $contract['standard_plugins'], true)) {
                    $counts['migrated'][$cType] = ($counts['migrated'][$cType] ?? 0) + 1;
                    if ($listType !== '') {
                        $findings[] = ['severity' => 'blocker', 'code' => 'CONTRADICTORY_CONTENT_TYPE', 'uid' => (int)$row['uid']];
                    }
                } else {
                    ++$counts['unknown'];
                    $findings[] = ['severity' => 'blocker', 'code' => 'UNKNOWN_FORUM_PLUGIN', 'uid' => (int)$row['uid'], 'list_type' => $listType];
                }
            }
        }
    } else {
        $findings[] = ['severity' => 'indeterminate', 'code' => 'TT_CONTENT_MISSING'];
    }

    $permissions = [];
    if (isset($databaseSchema['be_groups']['explicit_allowdeny'])) {
        $selected = array_values(array_intersect(['uid', 'title', 'subgroup', 'explicit_allowdeny'], array_keys($databaseSchema['be_groups'])));
        $sql = 'SELECT ' . implode(', ', array_map(static fn (string $column): string => identifier($column, $driver), $selected))
            . ' FROM ' . identifier('be_groups', $driver) . ' ORDER BY ' . identifier('uid', $driver);
        foreach ($pdo->query($sql) ?: [] as $group) {
            $value = (string)($group['explicit_allowdeny'] ?? '');
            if (str_contains($value, 'typo3forum_')) {
                $permissions[] = ['uid' => (int)$group['uid'], 'subgroup' => (string)($group['subgroup'] ?? ''), 'fingerprint' => hash('sha256', canonicalJson($group)), 'tokens' => array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $token): bool => str_contains($token, 'typo3forum_')))];
                if (str_contains($value, 'typo3forum_pi1') || str_contains($value, 'typo3forum_widget')) {
                    $findings[] = ['severity' => 'blocker', 'code' => 'NON_EQUIVALENT_LEGACY_PERMISSION', 'uid' => (int)$group['uid']];
                }
            }
        }
    }

    $integrity = [];
    foreach ($contract['integrity_tables'] as $table) {
        if (isset($databaseSchema[$table])) {
            $integrity[$table] = fingerprintTable($pdo, $driver, $table, array_keys($databaseSchema[$table]));
        }
    }
    if (isset($databaseSchema['fe_users'])) {
        $columns = array_values(array_intersect(['uid', 'password'], array_keys($databaseSchema['fe_users'])));
        if ($columns === ['uid', 'password']) {
            $integrity['fe_users_passwords'] = fingerprintTable($pdo, $driver, 'fe_users', $columns);
        }
    }
    if (isset($databaseSchema['sys_file_storage'])) {
        $integrity['file_storages'] = fingerprintTable($pdo, $driver, 'sys_file_storage', array_keys($databaseSchema['sys_file_storage']));
    }

    $relations = [];
    foreach ($contract['relations'] as [$source, $sourceField, $target, $targetField, $allowZero]) {
        if (!isset($databaseSchema[$source][$sourceField], $databaseSchema[$target][$targetField])) {
            $relations[] = ['relation' => "$source.$sourceField->$target.$targetField", 'status' => 'not_checked_missing_schema'];
            continue;
        }
        $sql = 'SELECT COUNT(*) FROM ' . identifier($source, $driver) . ' s LEFT JOIN ' . identifier($target, $driver) . ' t ON s.' . identifier($sourceField, $driver) . ' = t.' . identifier($targetField, $driver)
            . ' WHERE t.' . identifier($targetField, $driver) . ' IS NULL' . ($allowZero ? ' AND s.' . identifier($sourceField, $driver) . ' <> 0' : '');
        $relations[] = ['relation' => "$source.$sourceField->$target.$targetField", 'orphans' => (int)$pdo->query($sql)->fetchColumn()];
    }
    if (isset($databaseSchema['sys_file_reference']['uid_local'], $databaseSchema['sys_file_reference']['tablenames'], $databaseSchema['sys_file']['uid'])) {
        $sql = 'SELECT COUNT(*) FROM ' . identifier('sys_file_reference', $driver) . ' r LEFT JOIN ' . identifier('sys_file', $driver) . ' f ON r.' . identifier('uid_local', $driver) . ' = f.' . identifier('uid', $driver)
            . ' WHERE r.' . identifier('tablenames', $driver) . " LIKE 'tx_typo3forum_%' AND f." . identifier('uid', $driver) . ' IS NULL';
        $relations[] = ['relation' => 'sys_file_reference.uid_local->sys_file.uid (forum only)', 'orphans' => (int)$pdo->query($sql)->fetchColumn()];
    } else {
        $relations[] = ['relation' => 'sys_file_reference.uid_local->sys_file.uid (forum only)', 'status' => 'not_checked_missing_schema'];
    }
    foreach ($relations as $relation) {
        if (($relation['orphans'] ?? 0) > 0) {
            $findings[] = ['severity' => 'blocker', 'code' => 'ORPHAN_RELATION', 'relation' => $relation['relation'], 'count' => $relation['orphans']];
        }
    }

    $projectFindings = scanProject((string)($options['project-root'] ?? ''));
    if ($projectFindings !== []) {
        $findings[] = ['severity' => 'indeterminate', 'code' => 'PROJECT_CONFIGURATION_REVIEW_REQUIRED', 'count' => count($projectFindings)];
    }
    $oldCount = array_sum($counts['standard']) + $counts['legacy_pi1'] + $counts['unknown'];
    $hasForumData = array_sum(array_column($integrity, 'count')) > 0;
    $status = 'READY';
    if (array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'error')) {
        $status = 'ERROR';
    } elseif (array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'blocker')) {
        $status = 'BLOCKED';
    } elseif (array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'indeterminate')) {
        $status = 'INDETERMINATE';
    } elseif ($oldCount === 0) {
        $status = $listTypeState === 'present' || !$hasForumData ? 'ALREADY_MIGRATED' : 'INDETERMINATE';
    }
    $manifest = [
        'manifest_format' => 'typo3-forum-preflight/1.0',
        'contract_version' => $contract['rule_version'],
        'captured_at' => gmdate('c'),
        'status' => $status,
        'source' => ['typo3_version' => $options['typo3-version'] ?? null, 'extension_version' => $options['extension-version'] ?? null, 'database_driver' => $driver, 'database_version' => (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION)],
        'schema' => ['tt_content_list_type' => $listTypeState, 'tables' => $databaseSchema],
        'inventory' => ['counts' => $counts, 'content_records' => $records, 'backend_permissions' => $permissions, 'project_findings' => $projectFindings],
        'integrity' => ['tables' => $integrity, 'relations' => $relations],
        'findings' => $findings,
        'scan_limits' => ['project_scan' => 'Static files up to 2 MB; excludes vendor, var, typo3temp, .git and node_modules. Dynamic/database/external configuration requires review.', 'remote_files' => 'Remote FAL object availability is not tested.'],
    ];
    $manifest['checksum'] = hash('sha256', canonicalJson($manifest));
    $directory = dirname($output);
    if (!is_dir($directory) || !is_writable($directory)) {
        fail('The explicitly selected output directory must already exist and be writable.');
    }
    $temporary = tempnam($directory, '.forum-preflight-');
    if ($temporary === false || chmod($temporary, 0600) === false || file_put_contents($temporary, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false || !rename($temporary, $output)) {
        fail('Cannot write the protected report.');
    }
    chmod($output, 0600);
    printf("Status: %s\nContent records: %d\nFindings: %d\nManifest: %s\nChecksum: %s\n", $status, count($records), count($findings), $output, $manifest['checksum']);
    exit(match ($status) {
        'READY' => EXIT_READY,
        'ALREADY_MIGRATED' => EXIT_ALREADY_MIGRATED,
        'BLOCKED' => EXIT_BLOCKED,
        'INDETERMINATE' => EXIT_INDETERMINATE,
        default => EXIT_ERROR,
    });
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
