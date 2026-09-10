<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

final class PreflightToolTest extends TestCase
{
    public function testStandalonePreflightIsReadOnlyAndWritesOnlyTheSelectedProtectedReport(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The standalone preflight test requires pdo_sqlite.');
        }
        $directory = sys_get_temp_dir() . '/forum-preflight-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory . '/source.sqlite';
        $report = $directory . '/report.json';
        try {
            $pdo = new PDO('sqlite:' . $database);
            $pdo->exec('CREATE TABLE tt_content (uid INTEGER PRIMARY KEY, pid INTEGER, CType TEXT, list_type TEXT, pi_flexform TEXT, hidden INTEGER, deleted INTEGER, sys_language_uid INTEGER, l18n_parent INTEGER, t3ver_oid INTEGER, t3ver_wsid INTEGER)');
            $pdo->exec("INSERT INTO tt_content VALUES (1, 10, 'list', 'typo3forum_forum', '<unchanged/>', 1, 1, 1, 0, 1, 2)");
            unset($pdo);
            $before = hash_file('sha256', $database);
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Resources/Private/Migration/preflight.php', '--dsn=sqlite:' . $database, '--output=' . $report, '--typo3-version=12.4', '--extension-version=12.0.0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stdout . $stderr);
            self::assertSame($before, hash_file('sha256', $database));
            self::assertSame(0, fileperms($report) & 0077);
            $manifest = json_decode((string)file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('READY', $manifest['status']);
            self::assertSame('present', $manifest['schema']['tt_content_list_type']);
            self::assertSame(1, $manifest['inventory']['counts']['standard']['typo3forum_forum']);
            self::assertSame(1, $manifest['inventory']['content_records'][0]['flags']['hidden'] ?? 1);
        } finally {
            @unlink($report);
            @unlink($database);
            @rmdir($directory);
        }
    }

    public function testPreflightContainsNoMutationStatementsOrCredentialValues(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/Resources/Private/Migration/preflight.php');
        self::assertDoesNotMatchRegularExpression('/(?:->exec|->prepare|->query)\s*\(\s*[\'\"](?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE)\b/i', $source);
        self::assertStringContainsString('FORUM_MIGRATION_DB_PASSWORD', $source);
        self::assertStringNotContainsString('password=', $source);
    }

    public function testMissingAndPossiblyRenamedSourceColumnsRemainDistinguishable(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The standalone preflight test requires pdo_sqlite.');
        }
        $directory = sys_get_temp_dir() . '/forum-preflight-columns-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory . '/source.sqlite';
        $report = $directory . '/report.json';
        try {
            $pdo = new PDO('sqlite:' . $database);
            $pdo->exec('CREATE TABLE tt_content (uid INTEGER PRIMARY KEY, CType TEXT, zzz_deleted_list_type TEXT)');
            $pdo->exec("INSERT INTO tt_content VALUES (41, 'list', 'typo3forum_forum')");
            unset($pdo);
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Resources/Private/Migration/preflight.php', '--dsn=sqlite:' . $database, '--output=' . $report, '--expected-legacy-uids=41'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(20, proc_close($process), $stdout . $stderr);
            $manifest = json_decode((string)file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('BLOCKED', $manifest['status']);
            self::assertSame('possible_renamed', $manifest['schema']['tt_content_list_type']);
            self::assertSame(
                ['POSSIBLE_RENAMED_LIST_TYPE_SOURCE', 'LIST_TYPE_MISSING_WITH_EXPECTED_LEGACY_RECORDS'],
                array_values(array_unique(array_column($manifest['findings'], 'code')))
            );
        } finally {
            @unlink($report);
            @unlink($database);
            @rmdir($directory);
        }
    }
}
