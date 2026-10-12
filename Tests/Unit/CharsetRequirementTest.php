<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Mittwald\Typo3Forum\RichText\CharsetRequirement;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class CharsetRequirementTest extends TestCase
{
    public function testThreeByteConnectionAndColumnsBlockWrites(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('fetchAssociative')->willReturn(['client' => 'utf8mb3', 'connection' => 'utf8mb3', 'results' => 'utf8mb3']);
        $connection->method('fetchAllAssociative')->willReturn([['COLUMN_NAME' => 'text', 'CHARACTER_SET_NAME' => 'utf8mb3']]);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        $requirement = new CharsetRequirement($pool);
        self::assertContains('UTF8MB4_CONNECTION_REQUIRED', array_column($requirement->findings(), 'code'));
        self::assertContains('UTF8MB4_COLUMN_REQUIRED', array_column($requirement->findings(), 'code'));
        $this->expectException(\RuntimeException::class);
        $requirement->assertReady();
    }

    public function testFourByteConnectionsAndColumnsPass(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('fetchAssociative')->willReturn(['client' => 'utf8mb4', 'connection' => 'utf8mb4', 'results' => 'utf8mb4']);
        $connection->method('fetchAllAssociative')->willReturn([['COLUMN_NAME' => 'text', 'CHARACTER_SET_NAME' => 'utf8mb4']]);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        self::assertSame([], (new CharsetRequirement($pool))->findings());
    }
}
