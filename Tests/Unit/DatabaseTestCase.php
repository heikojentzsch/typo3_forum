<?php
declare(strict_types=1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DefaultRestrictionContainer;

abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;
    protected ConnectionPool $pool;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated database tests require pdo_sqlite.');
        }
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => IsolatedConnection::class,
        ]);
        $this->pool = $this->createMock(ConnectionPool::class);
        $this->pool->method('getConnectionForTable')->willReturn($this->connection);
        $this->pool->method('getQueryBuilderForTable')->willReturnCallback(
            fn() => new QueryBuilder($this->connection, new class extends DefaultRestrictionContainer { public function __construct() {} }, null, [])
        );
    }
}

/** The fixtures provide their own schema and types, without TYPO3's package cache. */
class IsolatedConnection extends Connection
{
    protected function ensureDatabaseValueTypes(string $tableName, array &$data, array &$types): void
    {}
}
