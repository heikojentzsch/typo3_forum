<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Migration;

use Closure;
use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class MigrationService
{
    private const INTERRUPTED_LOCK_MINIMUM_AGE = 3600;
    private const CONTENT_TABLE = 'tt_content';
    private const GROUP_TABLE = 'be_groups';
    private const JOURNAL_TABLE = 'tx_typo3forum_migration_journal';
    private const LOCK_TABLE = 'tx_typo3forum_migration_lock';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly MigrationContract $contract,
        private readonly FlexFormMigrator $flexFormMigrator,
        private readonly ?Closure $operationLockedHook = null,
    ) {
    }

    /** @param array<string, mixed>|null $preflight
     *  @return array<string, mixed>
     */
    public function check(?array $preflight = null): array
    {
        $this->validatePreflight($preflight);
        $schema = $this->schema();
        $findings = [];
        $resolutions = [];
        $content = [];
        $counts = ['standard' => [], 'legacy_pi1' => 0, 'migrated' => [], 'unknown' => 0];
        $hasContentTable = isset($schema[self::CONTENT_TABLE]);
        $hasCType = isset($schema[self::CONTENT_TABLE]['CType']);
        $hasListType = isset($schema[self::CONTENT_TABLE]['list_type']);

        if (!$hasContentTable || !$hasCType) {
            $findings[] = ['severity' => 'error', 'code' => 'TT_CONTENT_SCHEMA_MISSING'];
        } else {
            foreach ($this->contentRows($schema[self::CONTENT_TABLE]) as $row) {
                $cType = (string)($row['CType'] ?? '');
                $listType = (string)($row['list_type'] ?? '');
                if (!$this->isRelevantContent($cType, $listType)) {
                    continue;
                }
                $record = [
                    'uid' => (int)$row['uid'],
                    'pid' => (int)($row['pid'] ?? 0),
                    'CType' => $cType,
                    'list_type' => $listType,
                    'fingerprint' => CanonicalJson::checksum($this->normalizeProjectionRow($row, $this->contract->contentIntegerFields())),
                    'flags' => array_intersect_key($row, array_flip(['hidden', 'deleted', 'sys_language_uid', 'l18n_parent', 't3ver_oid', 't3ver_id', 't3ver_wsid', 't3ver_state'])),
                ];
                if (isset($row['pi_flexform'])) {
                    $record['pi_flexform_sha256'] = hash('sha256', (string)$row['pi_flexform']);
                }
                if ($cType === 'list' && isset($this->contract->standardPlugins()[$listType])) {
                    $record['classification'] = 'standard';
                    $record['target'] = $this->contract->standardPlugins()[$listType];
                    $counts['standard'][$listType] = ($counts['standard'][$listType] ?? 0) + 1;
                } elseif ($cType === 'list' && $listType === 'typo3forum_pi1') {
                    ++$counts['legacy_pi1'];
                    $record['classification'] = 'legacy_pi1';
                    try {
                        $flex = $this->flexFormMigrator->inspect((string)($row['pi_flexform'] ?? ''));
                        $rule = $this->contract->legacyPi1Rules()[$flex['action']] ?? null;
                        $record['legacy_action'] = $flex['action'];
                        $record['flexform_fields'] = $flex['fields'];
                        if ($rule === null) {
                            $findings[] = ['severity' => 'blocker', 'code' => 'UNKNOWN_PI1_CONFIGURATION', 'uid' => (int)$row['uid'], 'action' => $flex['action']];
                        } else {
                            $unknownFields = array_values(array_diff($flex['fields'], $rule['allowed_fields']));
                            if ($unknownFields !== []) {
                                $findings[] = ['severity' => 'blocker', 'code' => 'PI1_FIELDS_REQUIRE_DECISION', 'uid' => (int)$row['uid'], 'fields' => $unknownFields];
                            } else {
                                $record['target'] = $rule['target'];
                                $record['rule_id'] = $rule['rule_id'];
                                $record['provenance'] = $rule['provenance'];
                            }
                        }
                    } catch (Throwable $exception) {
                        $findings[] = ['severity' => 'blocker', 'code' => 'INVALID_PI1_FLEXFORM', 'uid' => (int)$row['uid'], 'message' => $exception->getMessage()];
                    }
                } elseif (in_array($cType, $this->contract->standardPlugins(), true)) {
                    $record['classification'] = 'migrated';
                    $counts['migrated'][$cType] = ($counts['migrated'][$cType] ?? 0) + 1;
                    if ($listType !== '') {
                        $findings[] = ['severity' => 'blocker', 'code' => 'CONTRADICTORY_CONTENT_TYPE', 'uid' => (int)$row['uid']];
                    }
                } else {
                    $record['classification'] = 'unknown';
                    ++$counts['unknown'];
                    $findings[] = ['severity' => 'blocker', 'code' => 'UNKNOWN_FORUM_PLUGIN', 'uid' => (int)$row['uid'], 'list_type' => $listType];
                }
                $record['source'] = $row;
                $content[] = $record;
            }
        }

        $permissions = $this->permissions($schema, $findings);
        $integrity = $this->integrity($schema);
        foreach ($integrity['tables'] as $table => $evidence) {
            if (($evidence['status'] ?? null) !== 'checked' && ($evidence['count'] ?? 0) > 0) {
                $findings[] = ['severity' => 'indeterminate', 'code' => 'INTEGRITY_PROJECTION_INCOMPLETE', 'table' => $table, 'missing_fields' => $evidence['missing_fields'] ?? []];
            }
        }
        foreach ($integrity['relations'] as $relation) {
            if (($relation['orphans'] ?? 0) > 0) {
                $findings[] = ['severity' => 'blocker', 'code' => 'ORPHAN_RELATION', 'relation' => $relation['relation'], 'count' => $relation['orphans']];
            }
        }
        $oldCount = array_sum($counts['standard']) + $counts['legacy_pi1'] + $counts['unknown'];
        if (!$hasListType) {
            $preflightOldCount = (int)array_sum($preflight['inventory']['counts']['standard'] ?? [])
                + (int)($preflight['inventory']['counts']['legacy_pi1'] ?? 0)
                + (int)($preflight['inventory']['counts']['unknown'] ?? 0);
            if ($preflightOldCount > 0) {
                $findings[] = ['severity' => 'blocker', 'code' => 'LIST_TYPE_REMOVED_BEFORE_MIGRATION', 'preflight_old_records' => $preflightOldCount];
            } elseif ($preflight === null && $this->hasForumData($integrity)) {
                $findings[] = ['severity' => 'indeterminate', 'code' => 'LIST_TYPE_MISSING_WITHOUT_SOURCE_PROOF'];
            }
        }
        if ($preflight !== null) {
            $this->comparePreflightRecords($preflight, $content, $findings);
            $this->comparePreflightPermissions($preflight, $permissions, $findings, $resolutions);
            $this->compareIntegrityEvidence($preflight['integrity'] ?? [], $integrity, $findings, 'SOURCE');
            foreach ($preflight['findings'] ?? [] as $sourceFinding) {
                if (!is_array($sourceFinding) || !isset($sourceFinding['severity'], $sourceFinding['code'])) {
                    continue;
                }
                $finding = $sourceFinding;
                $finding['code'] = 'SOURCE_' . $sourceFinding['code'];
                $finding['source_finding'] = $sourceFinding['code'];
                $findings[] = $finding;
            }
        } else {
            $findings[] = ['severity' => 'warning', 'code' => 'SOURCE_PREFLIGHT_NOT_SUPPLIED', 'assurance' => 'target_only'];
        }

        $pendingPermissions = count(array_filter($permissions, static fn (array $permission): bool => ($permission['after'] ?? null) !== null && $permission['before'] !== $permission['after']));
        $status = 'READY';
        if ($this->hasSeverity($findings, ['error'])) {
            $status = 'ERROR';
        } elseif ($this->hasSeverity($findings, ['blocker'])) {
            $status = 'BLOCKED';
        } elseif ($this->hasSeverity($findings, ['indeterminate'])) {
            $status = 'INDETERMINATE';
        } elseif ($oldCount === 0 && $pendingPermissions === 0) {
            $status = 'ALREADY_MIGRATED';
        }

        return [
            'manifest_format' => 'typo3-forum-check/2.0',
            'contract_version' => $this->contract->version(),
            'captured_at' => gmdate('c'),
            'status' => $status,
            'schema' => ['tt_content_list_type' => $hasListType ? 'present' : 'missing'],
            'inventory' => ['counts' => $counts, 'pending_permission_conversions' => $pendingPermissions, 'content_records' => $content, 'backend_permissions' => $permissions],
            'integrity' => $integrity,
            'findings' => $findings,
            'resolutions' => $resolutions,
            'preflight_checksum' => $preflight['checksum'] ?? null,
            'assurance' => $preflight === null ? 'target_only' : 'source_to_target',
        ];
    }

    /** @param array<string, mixed>|null $preflight
     *  @return array<string, mixed>
     */
    public function plan(?array $preflight = null): array
    {
        $check = $this->check($preflight);
        $operations = [];
        if (in_array($check['status'], ['READY', 'ALREADY_MIGRATED'], true)) {
            foreach ($check['inventory']['content_records'] as $record) {
                if (!in_array($record['classification'], ['standard', 'legacy_pi1'], true)) {
                    continue;
                }
                $before = $record['source'];
                $after = $before;
                $after['CType'] = $record['target'];
                $after['list_type'] = '';
                $ruleId = 'standard-list-type-to-ctype';
                $reason = 'Exact v12 list_type mapping';
                $fields = ['CType', 'list_type'];
                if ($record['classification'] === 'legacy_pi1') {
                    $rule = $this->contract->legacyPi1Rules()[$record['legacy_action']];
                    $after['pi_flexform'] = $this->flexFormMigrator->transform((string)$before['pi_flexform'], $rule['renamed_fields']);
                    $ruleId = $rule['rule_id'];
                    $reason = $rule['provenance'];
                    $fields[] = 'pi_flexform';
                }
                $operations[] = $this->operation(self::CONTENT_TABLE, (int)$record['uid'], $ruleId, $reason, $fields, $before, $after);
            }
            foreach ($check['inventory']['backend_permissions'] as $permission) {
                if (($permission['after'] ?? null) !== null && $permission['before'] !== $permission['after']) {
                    $operations[] = $this->operation(self::GROUP_TABLE, (int)$permission['uid'], 'standard-backend-permission', 'One-to-one preservation of CType restriction semantics', ['explicit_allowdeny'], ['uid' => (int)$permission['uid'], 'subgroup' => $permission['subgroup'], 'explicit_allowdeny' => $permission['before']], ['uid' => (int)$permission['uid'], 'subgroup' => $permission['subgroup'], 'explicit_allowdeny' => $permission['after']]);
                }
            }
        }
        $plan = [
            'manifest_format' => 'typo3-forum-plan/2.0',
            'contract_version' => $this->contract->version(),
            'created_at' => gmdate('c'),
            'status' => $check['status'],
            'versions' => ['source' => $preflight['source'] ?? null, 'target' => ['typo3' => '14.3', 'extension' => '14.x']],
            'scope' => ['tables' => [self::CONTENT_TABLE, self::GROUP_TABLE], 'operation' => 'validated_field_update'],
            'preflight_checksum' => $check['preflight_checksum'],
            'source_evidence' => $preflight,
            'assurance' => $check['assurance'],
            'findings' => $check['findings'],
            'resolutions' => $check['resolutions'],
            'operations' => $operations,
            'target_before' => ['integrity' => $check['integrity'], 'inventory' => $check['inventory']],
        ];
        $plan['checksum'] = CanonicalJson::checksum($plan);
        return $plan;
    }

    /** @param array<string, mixed> $plan
     *  @return array<string, mixed>
     */
    public function apply(array $plan, string $confirmation, bool $resumeInterrupted = false): array
    {
        $this->validatePlan($plan);
        if ($plan['status'] === 'ALREADY_MIGRATED') {
            $verification = $this->verify($plan);
            if ($verification['status'] !== 'NO_MIGRATION_REQUIRED') {
                throw new RuntimeException('The no-op plan no longer describes the current migration scope.');
            }
            return ['status' => 'ALREADY_MIGRATED', 'applied' => 0, 'resumed' => 0, 'verification' => $verification];
        }
        if ($plan['status'] !== 'READY') {
            throw new RuntimeException('Only a READY plan can be applied.');
        }
        if (!hash_equals((string)$plan['checksum'], $confirmation)) {
            throw new RuntimeException('Explicit confirmation must equal the plan checksum.');
        }
        $this->assertPlanCoversCurrentScope($plan);
        $this->assertMigrationTablesExist();
        $lockConnection = $this->connectionPool->getConnectionForTable(self::LOCK_TABLE);
        $journalConnection = $this->connectionPool->getConnectionForTable(self::JOURNAL_TABLE);
        if ($lockConnection !== $journalConnection) {
            throw new RuntimeException('Migration lock and journal are routed to different database connections.');
        }
        foreach ($plan['operations'] as $operation) {
            if ($this->connectionPool->getConnectionForTable($operation['table']) !== $journalConnection) {
                throw new RuntimeException('Migration scope spans different routed database connections.');
            }
        }
        $ownerToken = bin2hex(random_bytes(32));
        $this->acquireLock($lockConnection, (string)$plan['checksum'], $ownerToken, $resumeInterrupted);

        $applied = 0;
        $resumed = 0;
        try {
            foreach ($plan['operations'] as $operation) {
                $this->validateOperation($operation);
                $connection = $this->connectionPool->getConnectionForTable($operation['table']);
                $updates = array_intersect_key($operation['after'], array_flip($operation['fields']));
                $connection->beginTransaction();
                try {
                    $current = $this->fetchRow($connection, $operation['table'], (int)$operation['uid'], array_keys($operation['before']), true);
                    if ($this->operationLockedHook !== null) {
                        ($this->operationLockedHook)($operation);
                    }
                    $currentFingerprint = CanonicalJson::checksum($current);
                    if (hash_equals($operation['after_fingerprint'], $currentFingerprint)) {
                        if (!$this->journalExists($plan['checksum'], $operation)) {
                            throw new RuntimeException(sprintf('Record %s:%d has target values without matching journal.', $operation['table'], $operation['uid']));
                        }
                        $connection->commit();
                        ++$resumed;
                        continue;
                    }
                    if (!hash_equals($operation['before_fingerprint'], $currentFingerprint)) {
                        throw new RuntimeException(sprintf('Source conflict for %s:%d; no data was overwritten.', $operation['table'], $operation['uid']));
                    }
                    $affected = $connection->update($operation['table'], $updates, ['uid' => (int)$operation['uid']]);
                    if ($affected !== 1) {
                        throw new RuntimeException('The planned record update did not affect exactly one row.');
                    }
                    $connection->insert(self::JOURNAL_TABLE, [
                        'manifest_checksum' => $plan['checksum'],
                        'table_name' => $operation['table'],
                        'record_uid' => (int)$operation['uid'],
                        'rule_id' => $operation['rule_id'],
                        'rule_version' => $this->contract->version(),
                        'before_fingerprint' => $operation['before_fingerprint'],
                        'after_fingerprint' => $operation['after_fingerprint'],
                        'applied_at' => time(),
                    ]);
                    $connection->commit();
                    ++$applied;
                } catch (Throwable $exception) {
                    $connection->rollBack();
                    throw $exception;
                }
            }
            $verification = $this->verify($plan, true, $ownerToken);
            if ($verification['status'] !== 'SUCCESS') {
                throw new RuntimeException(sprintf('Migration writes committed (applied: %d, resumed: %d), but integrity verification did not succeed.', $applied, $resumed));
            }
            return ['status' => 'SUCCESS', 'applied' => $applied, 'resumed' => $resumed, 'verification' => $verification];
        } finally {
            $lockConnection->delete(self::LOCK_TABLE, ['lock_id' => 1, 'manifest_checksum' => $plan['checksum'], 'owner_token' => $ownerToken]);
        }
    }

    /** @param array<string, mixed> $plan
     *  @return array<string, mixed>
     */
    public function dryRun(array $plan): array
    {
        $this->validatePlan($plan);
        if (!in_array($plan['status'], ['READY', 'ALREADY_MIGRATED'], true)) {
            return ['status' => $plan['status'], 'checked' => 0, 'problems' => $plan['findings'] ?? []];
        }
        $this->assertPlanCoversCurrentScope($plan);
        $problems = [];
        foreach ($plan['operations'] as $operation) {
            $this->validateOperation($operation);
            try {
                $connection = $this->connectionPool->getConnectionForTable($operation['table']);
                $current = $this->fetchRow($connection, $operation['table'], (int)$operation['uid'], array_keys($operation['before']));
                if (!hash_equals($operation['before_fingerprint'], CanonicalJson::checksum($current))) {
                    $problems[] = ['code' => 'SOURCE_CONFLICT', 'table' => $operation['table'], 'uid' => $operation['uid']];
                }
            } catch (Throwable $exception) {
                $problems[] = ['code' => 'SOURCE_READ_ERROR', 'table' => $operation['table'], 'uid' => $operation['uid'], 'message' => $exception->getMessage()];
            }
        }
        return ['status' => $problems === [] ? ($plan['status'] === 'ALREADY_MIGRATED' ? 'ALREADY_MIGRATED' : 'READY') : 'BLOCKED', 'checked' => count($plan['operations']), 'problems' => $problems];
    }

    /** @param array<string, mixed> $plan
     *  @return array<string, mixed>
     */
    public function verify(array $plan, bool $requireOwnedLock = false, ?string $ownerToken = null): array
    {
        $this->validatePlan($plan);
        $problems = [];
        if (!in_array($plan['status'], ['READY', 'ALREADY_MIGRATED'], true)) {
            return ['status' => $plan['status'], 'problems' => [['code' => 'PLAN_NOT_VERIFIABLE', 'plan_status' => $plan['status']], ...($plan['findings'] ?? [])], 'checked_at' => gmdate('c'), 'assurance' => $plan['assurance']];
        }
        if ($plan['status'] === 'ALREADY_MIGRATED' && $plan['operations'] !== []) {
            throw new RuntimeException('An ALREADY_MIGRATED plan must not contain operations.');
        }
        $schema = $this->schema();
        if ($requireOwnedLock && ($ownerToken === null || !isset($schema[self::LOCK_TABLE]) || (int)$this->connectionPool->getConnectionForTable(self::LOCK_TABLE)->fetchOne('SELECT COUNT(*) FROM ' . self::LOCK_TABLE . ' WHERE lock_id = 1 AND manifest_checksum = ? AND owner_token = ?', [$plan['checksum'], $ownerToken]) !== 1)) {
            $problems[] = ['code' => 'MIGRATION_LOCK_NOT_HELD_DURING_FINAL_VERIFICATION'];
        }
        if ($plan['operations'] !== [] && !isset($schema[self::JOURNAL_TABLE])) {
            $problems[] = ['code' => 'JOURNAL_TABLE_MISSING'];
        }
        foreach ($plan['operations'] as $operation) {
            $this->validateOperation($operation);
            $connection = $this->connectionPool->getConnectionForTable($operation['table']);
            try {
                $current = $this->fetchRow($connection, $operation['table'], (int)$operation['uid'], array_keys($operation['after']));
                if (!hash_equals($operation['after_fingerprint'], CanonicalJson::checksum($current))) {
                    $problems[] = ['code' => 'TARGET_MISMATCH', 'table' => $operation['table'], 'uid' => $operation['uid']];
                }
                if (isset($schema[self::JOURNAL_TABLE]) && !$this->journalExists($plan['checksum'], $operation)) {
                    $problems[] = ['code' => 'JOURNAL_MISSING', 'table' => $operation['table'], 'uid' => $operation['uid']];
                }
            } catch (Throwable $exception) {
                $problems[] = ['code' => 'TARGET_READ_ERROR', 'table' => $operation['table'], 'uid' => $operation['uid'], 'message' => $exception->getMessage()];
            }
        }
        $currentIntegrity = $this->integrity($schema);
        $this->compareIntegrityEvidence($plan['target_before']['integrity'] ?? [], $currentIntegrity, $problems, 'TARGET');
        if (is_array($plan['source_evidence'] ?? null)) {
            $this->compareIntegrityEvidence($plan['source_evidence']['integrity'] ?? [], $currentIntegrity, $problems, 'SOURCE');
        }
        foreach ($currentIntegrity['relations'] as $relation) {
            if (($relation['orphans'] ?? 0) > 0) {
                $problems[] = ['code' => 'ORPHAN_RELATION', 'relation' => $relation['relation'], 'count' => $relation['orphans']];
            }
        }
        $currentCheck = $this->check();
        $scopeComplete = $currentCheck['status'] === 'ALREADY_MIGRATED';
        if (!$scopeComplete && is_array($plan['source_evidence'] ?? null) && $currentCheck['status'] === 'INDETERMINATE') {
            $blockingCodes = array_column(array_filter($currentCheck['findings'], static fn (array $finding): bool => in_array($finding['severity'], ['error', 'blocker', 'indeterminate'], true)), 'code');
            $scopeComplete = $blockingCodes === ['LIST_TYPE_MISSING_WITHOUT_SOURCE_PROOF'];
        }
        if (!$scopeComplete) {
            $problems[] = ['code' => 'CURRENT_SCOPE_NOT_COMPLETE', 'status' => $currentCheck['status']];
        }
        $successStatus = $plan['status'] === 'ALREADY_MIGRATED' ? 'NO_MIGRATION_REQUIRED' : 'SUCCESS';
        return ['status' => $problems === [] ? $successStatus : 'BLOCKED', 'problems' => $problems, 'checked_at' => gmdate('c'), 'assurance' => $plan['assurance']];
    }

    /** @return array<string, array<string, string>> */
    private function schema(): array
    {
        $tables = [];
        $connection = $this->connectionPool->getConnectionForTable(self::CONTENT_TABLE);
        $manager = $connection->createSchemaManager();
        foreach ($manager->listTableNames() as $table) {
            $tables[$table] = [];
            foreach ($manager->listTableColumns($table) as $column) {
                $tables[$table][$column->getName()] = $column->getType()::class;
            }
        }
        return $tables;
    }

    /** @param array<string, string> $columns
     *  @return list<array<string, mixed>>
     */
    private function contentRows(array $columns): array
    {
        $fields = array_values(array_intersect($this->contract->contentFields(), array_keys($columns)));
        $connection = $this->connectionPool->getConnectionForTable(self::CONTENT_TABLE);
        $sql = 'SELECT ' . implode(', ', array_map($connection->quoteIdentifier(...), $fields))
            . ' FROM ' . $connection->quoteIdentifier(self::CONTENT_TABLE) . ' ORDER BY ' . $connection->quoteIdentifier('uid');
        return $connection->executeQuery($sql)->fetchAllAssociative();
    }

    private function isRelevantContent(string $cType, string $listType): bool
    {
        return isset($this->contract->standardPlugins()[$listType])
            || in_array($listType, $this->contract->legacySignatures(), true)
            || in_array($cType, $this->contract->standardPlugins(), true)
            || str_starts_with($listType, 'typo3forum_');
    }

    /** @param array<string, array<string, string>> $schema
     *  @param list<array<string, mixed>> $findings
     *  @return list<array<string, mixed>>
     */
    private function permissions(array $schema, array &$findings): array
    {
        if (!isset($schema[self::GROUP_TABLE]['explicit_allowdeny'])) {
            return [];
        }
        $connection = $this->connectionPool->getConnectionForTable(self::GROUP_TABLE);
        $fields = array_values(array_intersect(['uid', 'subgroup', 'explicit_allowdeny'], array_keys($schema[self::GROUP_TABLE])));
        $rows = $connection->executeQuery('SELECT ' . implode(', ', array_map($connection->quoteIdentifier(...), $fields)) . ' FROM ' . $connection->quoteIdentifier(self::GROUP_TABLE) . ' ORDER BY uid')->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $before = (string)$row['explicit_allowdeny'];
            if (!str_contains($before, 'typo3forum_')) {
                continue;
            }
            $tokens = array_values(array_filter(array_map('trim', explode(',', $before)), static fn (string $value): bool => $value !== ''));
            $after = $tokens;
            foreach ($tokens as $index => $token) {
                if (preg_match('/^tt_content:list_type:(typo3forum_(?:pi1|widget))(?::(?:ALLOW|DENY))?$/D', $token)) {
                    $findings[] = ['severity' => 'blocker', 'code' => 'NON_EQUIVALENT_LEGACY_PERMISSION', 'uid' => (int)$row['uid'], 'token' => $token];
                    continue;
                }
                if (preg_match('/^tt_content:(?:list_type|CType):typo3forum_[^,]*:(?:ALLOW|DENY)$/D', $token)) {
                    $findings[] = ['severity' => 'blocker', 'code' => 'OBSOLETE_ACCESS_MODE_PERMISSION', 'uid' => (int)$row['uid'], 'token' => $token, 'action' => 'Run the TYPO3 v12 access-mode normalization and review effective group access before creating a new preflight.'];
                    continue;
                }
                if (!preg_match('/^tt_content:list_type:([a-z0-9_]+)$/D', $token, $matches)) {
                    if (str_contains($token, 'typo3forum_') && !preg_match('/^tt_content:CType:[a-z0-9_]+$/D', $token)) {
                        $findings[] = ['severity' => 'blocker', 'code' => 'MALFORMED_FORUM_PERMISSION', 'uid' => (int)$row['uid'], 'token' => $token];
                    }
                    continue;
                }
                $target = $this->contract->standardPlugins()[$matches[1]] ?? null;
                if ($target === null) {
                    continue;
                }
                $replacement = 'tt_content:CType:' . $target;
                $after[$index] = $replacement;
            }
            $after = array_values(array_unique($after));
            $result[] = [
                'uid' => (int)$row['uid'],
                'subgroup' => (string)($row['subgroup'] ?? ''),
                'before' => $before,
                'after' => implode(',', $after),
                'fingerprint' => CanonicalJson::checksum(['uid' => (int)$row['uid'], 'subgroup' => (string)($row['subgroup'] ?? ''), 'explicit_allowdeny' => $before]),
            ];
        }
        return $result;
    }

    /** @param array<string, array<string, string>> $schema
     *  @return array<string, mixed>
     */
    private function integrity(array $schema): array
    {
        $tables = [];
        foreach ($this->contract->integrityProjections() as $logicalTable => $projection) {
            $table = $logicalTable;
            $where = null;
            if ($logicalTable === 'fe_users_passwords') {
                $table = 'fe_users';
            } elseif ($logicalTable === 'forum_file_references') {
                $table = 'sys_file_reference';
                $where = "tablenames LIKE 'tx_typo3forum_%'";
            }
            if (!isset($schema[$table])) {
                continue;
            }
            $missing = array_values(array_diff($projection['fields'], array_keys($schema[$table])));
            if ($missing !== []) {
                $connection = $this->connectionPool->getConnectionForTable($table);
                $tables[$logicalTable] = ['status' => 'not_checked_missing_fields', 'projection_version' => $this->contract->version(), 'fields' => $projection['fields'], 'missing_fields' => $missing, 'count' => (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($table))];
                continue;
            }
            $tables[$logicalTable] = $this->fingerprintTable($table, $projection['fields'], $projection['integer_fields'], $where);
        }
        $relations = [];
        foreach ($this->contract->relations() as [$source, $sourceField, $target, $targetField, $allowZero]) {
            if (!isset($schema[$source][$sourceField], $schema[$target][$targetField])) {
                $relations[] = ['relation' => "$source.$sourceField->$target.$targetField", 'status' => 'not_checked_missing_schema'];
                continue;
            }
            $connection = $this->connectionPool->getConnectionForTable($source);
            $sql = sprintf(
                'SELECT COUNT(*) FROM %s s LEFT JOIN %s t ON s.%s = t.%s WHERE t.%s IS NULL%s',
                $connection->quoteIdentifier($source),
                $connection->quoteIdentifier($target),
                $connection->quoteIdentifier($sourceField),
                $connection->quoteIdentifier($targetField),
                $connection->quoteIdentifier($targetField),
                $allowZero ? ' AND s.' . $connection->quoteIdentifier($sourceField) . ' <> 0' : ''
            );
            $relations[] = ['relation' => "$source.$sourceField->$target.$targetField", 'orphans' => (int)$connection->fetchOne($sql)];
        }
        if (isset($schema['sys_file_reference']['uid_local'], $schema['sys_file_reference']['tablenames'], $schema['sys_file']['uid'])) {
            $connection = $this->connectionPool->getConnectionForTable('sys_file_reference');
            $relations[] = [
                'relation' => 'sys_file_reference.uid_local->sys_file.uid (forum only)',
                'orphans' => (int)$connection->fetchOne("SELECT COUNT(*) FROM sys_file_reference r LEFT JOIN sys_file f ON r.uid_local = f.uid WHERE r.tablenames LIKE 'tx_typo3forum_%' AND f.uid IS NULL"),
            ];
        } else {
            $relations[] = ['relation' => 'sys_file_reference.uid_local->sys_file.uid (forum only)', 'status' => 'not_checked_missing_schema'];
        }
        return ['projection_version' => $this->contract->version(), 'tables' => $tables, 'relations' => $relations, 'remote_storages' => 'NOT_CHECKED'];
    }

    /** @param list<string> $columns
     *  @param list<string> $integerFields
     *  @return array{count:int, fingerprint:string}
     */
    private function fingerprintTable(string $table, array $columns, array $integerFields = [], ?string $where = null): array
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        $quoted = array_map($connection->quoteIdentifier(...), $columns);
        $sql = 'SELECT ' . implode(', ', $quoted) . ' FROM ' . $connection->quoteIdentifier($table);
        if ($where !== null) {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY ' . (in_array('uid', $columns, true) ? $connection->quoteIdentifier('uid') : implode(', ', $quoted));
        $result = $connection->executeQuery($sql);
        $context = hash_init('sha256');
        $count = 0;
        while (($row = $result->fetchAssociative()) !== false) {
            hash_update($context, CanonicalJson::encode($this->normalizeProjectionRow($row, $integerFields)) . "\n");
            ++$count;
        }
        return ['status' => 'checked', 'projection_version' => $this->contract->version(), 'fields' => $columns, 'count' => $count, 'fingerprint' => hash_final($context)];
    }

    /** @param array<string, mixed> $row
     *  @param list<string> $integerFields
     *  @return array<string, mixed>
     */
    private function normalizeProjectionRow(array $row, array $integerFields): array
    {
        foreach ($integerFields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int)$row[$field];
            }
        }
        return $row;
    }

    /** @param array{tables:array<string, array{count:int, fingerprint:string}>, relations:list<array<string, mixed>>, remote_storages:string} $integrity */
    private function hasForumData(array $integrity): bool
    {
        foreach ($integrity['tables'] as $table => $value) {
            if (str_starts_with($table, 'tx_typo3forum_domain_model_') && ($value['count'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string, mixed>> $findings
     *  @param list<string> $severities
     */
    private function hasSeverity(array $findings, array $severities): bool
    {
        return array_filter($findings, static fn (array $finding): bool => in_array($finding['severity'], $severities, true)) !== [];
    }

    /** @param list<string> $fields
     *  @param array<string, mixed> $before
     *  @param array<string, mixed> $after
     *  @return array<string, mixed>
     */
    private function operation(string $table, int $uid, string $ruleId, string $reason, array $fields, array $before, array $after): array
    {
        return ['operation' => 'update_record', 'table' => $table, 'uid' => $uid, 'rule_id' => $ruleId, 'rule_version' => $this->contract->version(), 'reason' => $reason, 'fields' => $fields, 'before' => $before, 'after' => $after, 'before_fingerprint' => CanonicalJson::checksum($before), 'after_fingerprint' => CanonicalJson::checksum($after)];
    }

    /** @param array<string, mixed>|null $preflight */
    private function validatePreflight(?array $preflight): void
    {
        if ($preflight === null) {
            return;
        }
        if (($preflight['manifest_format'] ?? null) !== 'typo3-forum-preflight/2.0' || ($preflight['contract_version'] ?? null) !== $this->contract->version()) {
            throw new RuntimeException('Unsupported preflight manifest. Version 1 evidence is insufficient for the v2 integrity and permission contract; create a new source preflight.');
        }
        $allowedKeys = ['manifest_format', 'contract_version', 'captured_at', 'status', 'source', 'schema', 'inventory', 'integrity', 'findings', 'scan_limits', 'checksum'];
        if (array_diff(array_keys($preflight), $allowedKeys) !== [] || array_diff($allowedKeys, array_keys($preflight)) !== []
            || !is_array($preflight['source']) || !is_array($preflight['schema']) || !is_array($preflight['inventory'])
            || !is_array($preflight['inventory']['counts'] ?? null) || !is_array($preflight['inventory']['content_records'] ?? null)
            || !is_array($preflight['inventory']['backend_permissions'] ?? null) || !is_int($preflight['inventory']['pending_permission_conversions'] ?? null)
            || !is_array($preflight['integrity']) || ($preflight['integrity']['projection_version'] ?? null) !== $this->contract->version()
            || !is_array($preflight['integrity']['tables'] ?? null) || !is_array($preflight['integrity']['relations'] ?? null)
            || !is_array($preflight['findings']) || !is_array($preflight['scan_limits'])
            || !in_array($preflight['status'], ['READY', 'ALREADY_MIGRATED', 'BLOCKED', 'INDETERMINATE', 'ERROR'], true)) {
            throw new RuntimeException('The preflight manifest structure is invalid.');
        }
        foreach ($preflight['findings'] as $finding) {
            if (!is_array($finding) || !is_string($finding['code'] ?? null)
                || !in_array($finding['severity'] ?? null, ['warning', 'indeterminate', 'blocker', 'error'], true)) {
                throw new RuntimeException('The preflight findings are invalid.');
            }
        }
        $expectedStatus = 'READY';
        if ($this->hasSeverity($preflight['findings'], ['error'])) {
            $expectedStatus = 'ERROR';
        } elseif ($this->hasSeverity($preflight['findings'], ['blocker'])) {
            $expectedStatus = 'BLOCKED';
        } elseif ($this->hasSeverity($preflight['findings'], ['indeterminate'])) {
            $expectedStatus = 'INDETERMINATE';
        } elseif ($preflight['status'] === 'ALREADY_MIGRATED') {
            $expectedStatus = 'ALREADY_MIGRATED';
        }
        if ($preflight['status'] !== $expectedStatus) {
            throw new RuntimeException('The preflight status is inconsistent with its findings.');
        }
        foreach ($preflight['inventory']['content_records'] as $record) {
            if (!is_array($record) || !is_int($record['uid'] ?? null) || !is_string($record['fingerprint'] ?? null)) {
                throw new RuntimeException('The preflight content evidence is invalid.');
            }
        }
        foreach ($preflight['inventory']['backend_permissions'] as $permission) {
            if (!is_array($permission) || !is_int($permission['uid'] ?? null) || !is_string($permission['before'] ?? null)
                || !is_string($permission['fingerprint'] ?? null)) {
                throw new RuntimeException('The preflight permission evidence is invalid.');
            }
        }
        $checksum = $preflight['checksum'] ?? '';
        unset($preflight['checksum']);
        if (!is_string($checksum) || !hash_equals($checksum, CanonicalJson::checksum($preflight))) {
            throw new RuntimeException('The preflight manifest checksum is invalid.');
        }
    }

    /** @param array<string, mixed> $preflight
     *  @param list<array<string, mixed>> $current
     *  @param list<array<string, mixed>> $findings
     */
    private function comparePreflightRecords(array $preflight, array $current, array &$findings): void
    {
        $currentByUid = [];
        $sourceByUid = [];
        foreach ($current as $record) {
            $currentByUid[$record['uid']] = $record;
        }
        foreach ($preflight['inventory']['content_records'] ?? [] as $source) {
            $uid = (int)($source['uid'] ?? 0);
            $sourceByUid[$uid] = true;
            if (!isset($currentByUid[$uid])) {
                $findings[] = ['severity' => 'blocker', 'code' => 'SOURCE_RECORD_MISSING_AFTER_PREFLIGHT', 'uid' => $uid];
            } elseif (isset($source['fingerprint']) && !hash_equals((string)$source['fingerprint'], (string)$currentByUid[$uid]['fingerprint'])) {
                $findings[] = ['severity' => 'blocker', 'code' => 'SOURCE_CHANGED_AFTER_PREFLIGHT', 'uid' => $uid];
            }
        }
        foreach ($current as $record) {
            if (in_array($record['classification'], ['standard', 'legacy_pi1'], true) && !isset($sourceByUid[$record['uid']])) {
                $findings[] = ['severity' => 'blocker', 'code' => 'SOURCE_RECORD_ADDED_AFTER_PREFLIGHT', 'uid' => $record['uid']];
            }
        }
    }

    /** @param array<string, mixed> $preflight
     *  @param list<array<string, mixed>> $current
     *  @param list<array<string, mixed>> $findings
     *  @param list<array<string, mixed>> $resolutions
     */
    private function comparePreflightPermissions(array $preflight, array $current, array &$findings, array &$resolutions): void
    {
        $currentByUid = [];
        foreach ($current as $permission) {
            $currentByUid[(int)$permission['uid']] = $permission;
        }
        foreach ($preflight['inventory']['backend_permissions'] ?? [] as $source) {
            $uid = (int)($source['uid'] ?? 0);
            if (!isset($currentByUid[$uid])) {
                $findings[] = ['severity' => 'blocker', 'code' => 'SOURCE_PERMISSION_MISSING_AFTER_PREFLIGHT', 'uid' => $uid];
            } elseif (!isset($source['fingerprint']) || !hash_equals((string)$source['fingerprint'], (string)$currentByUid[$uid]['fingerprint'])) {
                try {
                    $expected = $this->expectedPermission((string)($source['before'] ?? ''));
                } catch (Throwable) {
                    $expected = null;
                }
                if ($expected !== null && hash_equals($expected, (string)$currentByUid[$uid]['before'])) {
                    $resolutions[] = ['code' => 'SOURCE_PERMISSION_PREREQUISITE_RESOLVED', 'uid' => $uid, 'rule_id' => 'typo3-v12-plain-list-type-to-ctype'];
                } else {
                    $findings[] = ['severity' => 'blocker', 'code' => 'SOURCE_PERMISSION_CHANGED_AFTER_PREFLIGHT', 'uid' => $uid];
                }
            }
        }
    }

    /** @param array<string, mixed> $expected
     *  @param array<string, mixed> $current
     *  @param list<array<string, mixed>> $findings
     */
    private function compareIntegrityEvidence(array $expected, array $current, array &$findings, string $prefix): void
    {
        if (($expected['projection_version'] ?? null) !== $this->contract->version()) {
            $findings[] = ['severity' => 'indeterminate', 'code' => $prefix . '_INTEGRITY_PROJECTION_UNSUPPORTED'];
            return;
        }
        foreach ($expected['tables'] ?? [] as $table => $before) {
            $after = $current['tables'][$table] ?? null;
            if (($before['status'] ?? null) !== 'checked') {
                $findings[] = ['severity' => 'indeterminate', 'code' => $prefix . '_INTEGRITY_NOT_COMPARABLE', 'table' => $table];
            } elseif ($after === null || ($after['status'] ?? null) !== 'checked') {
                $findings[] = ['severity' => 'blocker', 'code' => $prefix . '_INTEGRITY_TARGET_MISSING', 'table' => $table];
            } elseif (($before['fields'] ?? null) !== ($after['fields'] ?? null) || ($before['count'] ?? null) !== ($after['count'] ?? null) || !hash_equals((string)($before['fingerprint'] ?? ''), (string)($after['fingerprint'] ?? ''))) {
                $findings[] = ['severity' => 'blocker', 'code' => $prefix . '_INTEGRITY_MISMATCH', 'table' => $table];
            }
        }
    }

    /** @param array<string, mixed> $plan */
    private function validatePlan(array $plan): void
    {
        if (($plan['manifest_format'] ?? null) !== 'typo3-forum-plan/2.0' || ($plan['contract_version'] ?? null) !== $this->contract->version()) {
            throw new RuntimeException('Unsupported migration plan. Version 1 plans and approvals must be regenerated.');
        }
        $allowedKeys = ['manifest_format', 'contract_version', 'created_at', 'status', 'versions', 'scope', 'preflight_checksum', 'source_evidence', 'assurance', 'findings', 'resolutions', 'operations', 'target_before', 'checksum'];
        if (array_diff(array_keys($plan), $allowedKeys) !== [] || array_diff($allowedKeys, array_keys($plan)) !== []) {
            throw new RuntimeException('The migration plan contains unexpected top-level fields.');
        }
        $checksum = $plan['checksum'] ?? '';
        unset($plan['checksum']);
        if (!is_string($checksum) || !hash_equals($checksum, CanonicalJson::checksum($plan))) {
            throw new RuntimeException('The migration plan checksum is invalid.');
        }
        if (!in_array($plan['status'] ?? null, ['READY', 'ALREADY_MIGRATED', 'BLOCKED', 'INDETERMINATE', 'ERROR'], true)
            || !is_array($plan['operations'] ?? null) || !is_array($plan['target_before'] ?? null)
            || !is_array($plan['target_before']['inventory'] ?? null) || !is_array($plan['target_before']['integrity'] ?? null)
            || ($plan['target_before']['integrity']['projection_version'] ?? null) !== $this->contract->version()
            || !is_array($plan['target_before']['integrity']['tables'] ?? null) || !is_array($plan['target_before']['integrity']['relations'] ?? null)
            || !is_array($plan['findings'] ?? null) || !is_array($plan['resolutions'] ?? null)
            || !in_array($plan['assurance'] ?? null, ['target_only', 'source_to_target'], true)) {
            throw new RuntimeException('The migration plan structure is invalid.');
        }
        foreach ($plan['findings'] as $finding) {
            if (!is_array($finding) || !is_string($finding['code'] ?? null)
                || !in_array($finding['severity'] ?? null, ['warning', 'indeterminate', 'blocker', 'error'], true)) {
                throw new RuntimeException('The migration plan findings are invalid.');
            }
        }
        if (($plan['assurance'] === 'source_to_target') !== is_array($plan['source_evidence'] ?? null)) {
            throw new RuntimeException('The plan source-evidence assurance is inconsistent.');
        }
        if ($plan['assurance'] === 'source_to_target') {
            $this->validatePreflight($plan['source_evidence']);
            if (!hash_equals((string)$plan['preflight_checksum'], (string)$plan['source_evidence']['checksum'])) {
                throw new RuntimeException('The plan preflight reference is inconsistent.');
            }
        } elseif (($plan['source_evidence'] ?? null) !== null || ($plan['preflight_checksum'] ?? null) !== null) {
            throw new RuntimeException('A target-only plan must not claim source evidence.');
        }
        if ($plan['status'] === 'ALREADY_MIGRATED' && $plan['operations'] !== []) {
            throw new RuntimeException('An ALREADY_MIGRATED plan must not contain operations.');
        }
        if ($plan['status'] === 'READY' && $plan['operations'] === []) {
            throw new RuntimeException('A READY plan must contain pending operations.');
        }
        if (in_array($plan['status'], ['BLOCKED', 'INDETERMINATE', 'ERROR'], true) && $plan['operations'] !== []) {
            throw new RuntimeException('A non-executable plan must not contain operations.');
        }
        if (in_array($plan['status'], ['READY', 'ALREADY_MIGRATED'], true)
            && $this->hasSeverity($plan['findings'] ?? [], ['error', 'blocker', 'indeterminate'])) {
            throw new RuntimeException('An executable or no-op plan must not contain unresolved blocking findings.');
        }
        foreach ($plan['operations'] as $operation) {
            if (!is_array($operation)) {
                throw new RuntimeException('The migration plan contains a non-object operation.');
            }
            $this->validateOperation($operation);
        }
    }

    /** @param array<string, mixed> $operation */
    private function validateOperation(array $operation): void
    {
        $operationKeys = ['operation', 'table', 'uid', 'rule_id', 'rule_version', 'reason', 'fields', 'before', 'after', 'before_fingerprint', 'after_fingerprint'];
        $allowedFields = [self::CONTENT_TABLE => ['CType', 'list_type', 'pi_flexform'], self::GROUP_TABLE => ['explicit_allowdeny']];
        if (array_diff(array_keys($operation), $operationKeys) !== [] || ($operation['operation'] ?? null) !== 'update_record' || !isset($allowedFields[$operation['table'] ?? '']) || !is_int($operation['uid'] ?? null)
            || !is_array($operation['fields'] ?? null) || array_diff($operation['fields'], $allowedFields[$operation['table']]) !== [] || ($operation['rule_version'] ?? null) !== $this->contract->version()) {
            throw new RuntimeException('The plan contains an unsupported operation.');
        }
        if (!is_array($operation['before'] ?? null) || !is_array($operation['after'] ?? null)
            || !hash_equals((string)($operation['before_fingerprint'] ?? ''), CanonicalJson::checksum($operation['before']))
            || !hash_equals((string)($operation['after_fingerprint'] ?? ''), CanonicalJson::checksum($operation['after']))
            || (int)($operation['before']['uid'] ?? 0) !== $operation['uid'] || (int)($operation['after']['uid'] ?? 0) !== $operation['uid']) {
            throw new RuntimeException('The plan operation fingerprints or record identity are invalid.');
        }
        foreach ($operation['before'] as $field => $value) {
            if (!in_array($field, $operation['fields'], true) && ($operation['after'][$field] ?? null) !== $value) {
                throw new RuntimeException('The plan changes a field outside its declared scope.');
            }
        }
        if ($operation['table'] === self::CONTENT_TABLE && array_diff(array_unique([...array_keys($operation['before']), ...array_keys($operation['after'])]), $this->contract->contentFields()) !== []) {
            throw new RuntimeException('The plan contains unsupported content fields.');
        }
        if ($operation['table'] === self::GROUP_TABLE && array_diff(array_unique([...array_keys($operation['before']), ...array_keys($operation['after'])]), ['uid', 'subgroup', 'explicit_allowdeny']) !== []) {
            throw new RuntimeException('The plan contains unsupported backend-group fields.');
        }
        if ($operation['table'] === self::CONTENT_TABLE && (!in_array($operation['after']['CType'] ?? '', $this->contract->standardPlugins(), true) || ($operation['after']['list_type'] ?? null) !== '')) {
            throw new RuntimeException('The plan contains an unsupported target content type.');
        }
        if ($operation['table'] === self::CONTENT_TABLE) {
            $listType = (string)($operation['before']['list_type'] ?? '');
            if (($operation['before']['CType'] ?? null) !== 'list') {
                throw new RuntimeException('A content migration source must have CType=list.');
            }
            if (isset($this->contract->standardPlugins()[$listType])) {
                if ($operation['rule_id'] !== 'standard-list-type-to-ctype' || $operation['after']['CType'] !== $this->contract->standardPlugins()[$listType]
                    || ($operation['after']['pi_flexform'] ?? '') !== ($operation['before']['pi_flexform'] ?? '')) {
                    throw new RuntimeException('The standard content migration does not match the contract.');
                }
            } elseif ($listType === 'typo3forum_pi1') {
                $flex = $this->flexFormMigrator->inspect((string)($operation['before']['pi_flexform'] ?? ''));
                $rule = $this->contract->legacyPi1Rules()[$flex['action']] ?? null;
                if ($rule === null || $operation['rule_id'] !== $rule['rule_id'] || $operation['after']['CType'] !== $rule['target']
                    || ($operation['after']['pi_flexform'] ?? '') !== $this->flexFormMigrator->transform((string)$operation['before']['pi_flexform'], $rule['renamed_fields'])) {
                    throw new RuntimeException('The pi1 content migration does not match the contract.');
                }
            } else {
                throw new RuntimeException('The plan contains an unknown content source.');
            }
        } elseif (($operation['rule_id'] ?? null) !== 'standard-backend-permission'
            || ($operation['after']['explicit_allowdeny'] ?? null) !== $this->expectedPermission((string)($operation['before']['explicit_allowdeny'] ?? ''))) {
            throw new RuntimeException('The backend permission migration does not match the contract.');
        }
    }

    private function expectedPermission(string $before): string
    {
        $tokens = array_values(array_filter(array_map('trim', explode(',', $before)), static fn (string $value): bool => $value !== ''));
        foreach ($tokens as $index => $token) {
            if (preg_match('/^tt_content:(?:list_type|CType):typo3forum_[^,]*:(?:ALLOW|DENY)$/D', $token)) {
                throw new RuntimeException('Obsolete ALLOW/DENY permission tuples require Core normalization and access review.');
            }
            if (!preg_match('/^tt_content:list_type:([a-z0-9_]+)$/D', $token, $matches)) {
                continue;
            }
            if (in_array($matches[1], $this->contract->legacySignatures(), true)) {
                throw new RuntimeException('Legacy aggregate permissions are not automatically equivalent.');
            }
            $target = $this->contract->standardPlugins()[$matches[1]] ?? null;
            if ($target !== null) {
                $tokens[$index] = 'tt_content:CType:' . $target;
            }
        }
        return implode(',', array_values(array_unique($tokens)));
    }

    /** @param array<string, mixed> $approved */
    private function assertPlanCoversCurrentScope(array $approved): void
    {
        $current = $this->plan();
        if (!in_array($current['status'], ['READY', 'ALREADY_MIGRATED'], true)) {
            throw new RuntimeException('Current migration scope is blocked or indeterminate; the approved plan cannot be applied.');
        }
        $approvedByRecord = [];
        foreach ($approved['operations'] as $operation) {
            $approvedByRecord[$operation['table'] . ':' . $operation['uid']] = $operation;
        }
        foreach ($current['operations'] as $operation) {
            $key = $operation['table'] . ':' . $operation['uid'];
            if (!isset($approvedByRecord[$key]) || CanonicalJson::checksum($approvedByRecord[$key]) !== CanonicalJson::checksum($operation)) {
                throw new RuntimeException('Source conflict: the current migration scope differs from the approved plan.');
            }
        }
    }

    private function assertMigrationTablesExist(): void
    {
        $schema = $this->schema();
        if (!isset($schema[self::JOURNAL_TABLE], $schema[self::LOCK_TABLE])) {
            throw new RuntimeException('Run additive TYPO3 database schema updates before applying the migration.');
        }
    }

    private function acquireLock(DoctrineConnection $connection, string $checksum, string $ownerToken, bool $resumeInterrupted): void
    {
        try {
            $connection->insert(self::LOCK_TABLE, ['lock_id' => 1, 'manifest_checksum' => $checksum, 'owner_token' => $ownerToken, 'started_at' => time()]);
            return;
        } catch (Throwable $exception) {
            if (!$resumeInterrupted) {
                throw new RuntimeException('Another or interrupted forum migration holds the migration lock.', 0, $exception);
            }
        }
        $lock = $connection->fetchAssociative('SELECT manifest_checksum, owner_token, started_at FROM ' . self::LOCK_TABLE . ' WHERE lock_id = 1');
        if ($lock === false || !hash_equals($checksum, (string)$lock['manifest_checksum'])) {
            throw new RuntimeException('The existing migration lock belongs to another plan.');
        }
        if ((int)$lock['started_at'] > time() - self::INTERRUPTED_LOCK_MINIMUM_AGE) {
            throw new RuntimeException('The existing migration lock is still recent and cannot be recovered as interrupted.');
        }
        $affected = $connection->update(self::LOCK_TABLE, ['owner_token' => $ownerToken, 'started_at' => time()], ['lock_id' => 1, 'manifest_checksum' => $checksum, 'owner_token' => (string)$lock['owner_token'], 'started_at' => (int)$lock['started_at']]);
        if ($affected !== 1) {
            throw new RuntimeException('The migration lock changed while interrupted-run recovery was requested.');
        }
    }

    /** @param list<string> $columns
     *  @return array<string, mixed>
     */
    private function fetchRow(DoctrineConnection $connection, string $table, int $uid, array $columns, bool $forUpdate = false): array
    {
        $sql = 'SELECT ' . implode(', ', array_map($connection->quoteIdentifier(...), $columns)) . ' FROM ' . $connection->quoteIdentifier($table) . ' WHERE uid = ?';
        if ($forUpdate && $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $sql .= ' FOR UPDATE';
        }
        $row = $connection->fetchAssociative($sql, [$uid], [Connection::PARAM_INT]);
        if ($row === false) {
            throw new RuntimeException(sprintf('Planned record %s:%d does not exist.', $table, $uid));
        }
        return $row;
    }

    /** @param array<string, mixed> $operation */
    private function journalExists(string $checksum, array $operation): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::JOURNAL_TABLE);
        return (int)$connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::JOURNAL_TABLE . ' WHERE manifest_checksum = ? AND table_name = ? AND record_uid = ? AND rule_id = ? AND rule_version = ? AND before_fingerprint = ? AND after_fingerprint = ?',
            [$checksum, $operation['table'], $operation['uid'], $operation['rule_id'], $operation['rule_version'], $operation['before_fingerprint'], $operation['after_fingerprint']]
        ) === 1;
    }
}
