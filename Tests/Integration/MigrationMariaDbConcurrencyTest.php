<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Mittwald\Typo3Forum\Migration\FlexFormMigrator;
use Mittwald\Typo3Forum\Migration\MigrationContract;
use Mittwald\Typo3Forum\Migration\MigrationService;
use Mittwald\Typo3Forum\Tests\Unit\IsolatedConnection;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class MigrationMariaDbConcurrencyTest extends TestCase
{
    private ?Connection $migrationConnection = null;
    private ?Connection $competingConnection = null;

    protected function setUp(): void
    {
        if (getenv('FORUM_MIGRATION_MARIADB_TEST_CONFIRM') !== 'disposable') {
            self::markTestSkipped('Set FORUM_MIGRATION_MARIADB_TEST_CONFIRM=disposable only for a dedicated disposable MariaDB/MySQL database.');
        }
        $dsn = getenv('FORUM_MIGRATION_MARIADB_TEST_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('FORUM_MIGRATION_MARIADB_TEST_DSN is not configured.');
        }
        $parameters = ['url' => $dsn, 'wrapperClass' => IsolatedConnection::class];
        $this->migrationConnection = DriverManager::getConnection($parameters);
        $this->competingConnection = DriverManager::getConnection($parameters);
        $this->dropTables();
        $this->migrationConnection->executeStatement('CREATE TABLE tt_content (uid INT PRIMARY KEY, pid INT NOT NULL, CType VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL, list_type VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL, pi_flexform TEXT, pages TEXT, recursive INT, sys_language_uid INT, l18n_parent INT, t3ver_oid INT, t3ver_id INT, t3ver_wsid INT, t3ver_state INT, hidden INT, deleted INT, starttime INT, endtime INT, sorting INT, colPos INT, header VARCHAR(255)) ENGINE=InnoDB');
        $this->migrationConnection->executeStatement('CREATE TABLE be_groups (uid INT PRIMARY KEY, subgroup VARCHAR(255), explicit_allowdeny TEXT) ENGINE=InnoDB');
        $this->migrationConnection->executeStatement('CREATE TABLE tx_typo3forum_migration_journal (uid INT AUTO_INCREMENT PRIMARY KEY, manifest_checksum CHAR(64), table_name VARCHAR(64), record_uid INT, rule_id VARCHAR(64), rule_version VARCHAR(32), before_fingerprint CHAR(64), after_fingerprint CHAR(64), applied_at INT, UNIQUE KEY migration_step (manifest_checksum, table_name, record_uid)) ENGINE=InnoDB');
        $this->migrationConnection->executeStatement('CREATE TABLE tx_typo3forum_migration_lock (lock_id INT PRIMARY KEY, manifest_checksum CHAR(64), owner_token CHAR(64), started_at INT) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        if ($this->migrationConnection !== null) {
            $this->dropTables();
            $this->migrationConnection->close();
        }
        $this->competingConnection?->close();
    }

    public function testRowLockRejectsCompetingCaseOnlyWriteAndMigrationUsesExactSource(): void
    {
        $this->migrationConnection?->insert('tt_content', $this->contentRow());
        $pool = $this->pool($this->migrationConnection);
        $plan = (new MigrationService($pool, new MigrationContract(), new FlexFormMigrator()))->plan();

        $this->competingConnection?->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        $competingWriteWasBlocked = false;
        $service = new MigrationService(
            $pool,
            new MigrationContract(),
            new FlexFormMigrator(),
            function () use (&$competingWriteWasBlocked): void {
                try {
                    $this->competingConnection?->update('tt_content', ['header' => 'concurrent'], ['uid' => 1]);
                } catch (\Throwable) {
                    $competingWriteWasBlocked = true;
                }
            },
        );
        self::assertSame('SUCCESS', $service->apply($plan, $plan['checksum'])['status']);
        self::assertTrue($competingWriteWasBlocked, 'The competing connection must not overwrite a row after its protected read.');
        self::assertSame('Original', $this->migrationConnection?->fetchOne('SELECT header FROM tt_content WHERE uid = 1'));

        $this->migrationConnection?->executeStatement('DELETE FROM tx_typo3forum_migration_journal');
        $this->migrationConnection?->update('tt_content', ['CType' => 'list', 'list_type' => 'typo3forum_forum'], ['uid' => 1]);
        $stalePlan = (new MigrationService($pool, new MigrationContract(), new FlexFormMigrator()))->plan();
        $this->competingConnection?->update('tt_content', ['header' => 'original'], ['uid' => 1]);
        $this->expectExceptionMessage('Source conflict');
        (new MigrationService($pool, new MigrationContract(), new FlexFormMigrator()))->apply($stalePlan, $stalePlan['checksum']);
    }

    private function pool(?Connection $connection): ConnectionPool
    {
        self::assertNotNull($connection);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        return $pool;
    }

    /** @return array<string, int|string> */
    private function contentRow(): array
    {
        return [
            'uid' => 1, 'pid' => 1, 'CType' => 'list', 'list_type' => 'typo3forum_forum', 'pi_flexform' => '',
            'pages' => '', 'recursive' => 0, 'sys_language_uid' => 0, 'l18n_parent' => 0, 't3ver_oid' => 0,
            't3ver_id' => 0, 't3ver_wsid' => 0, 't3ver_state' => 0, 'hidden' => 0, 'deleted' => 0,
            'starttime' => 0, 'endtime' => 0, 'sorting' => 0, 'colPos' => 0, 'header' => 'Original',
        ];
    }

    private function dropTables(): void
    {
        foreach (['tx_typo3forum_migration_journal', 'tx_typo3forum_migration_lock', 'be_groups', 'tt_content'] as $table) {
            $this->migrationConnection?->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
