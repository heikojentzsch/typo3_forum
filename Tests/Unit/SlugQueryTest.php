<?php

declare(strict_types=1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Domain\Exception\TextParser\Exception;
use Mittwald\Typo3Forum\Utility\Slug;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SlugQueryTest extends DatabaseTestCase
{
    public function testMissingRecordRunsTheRealDbalQueryBeforeReportingTheDomainError(): void
    {
        $this->connection->executeStatement('CREATE TABLE slug_fixture (uid INTEGER PRIMARY KEY)');
        $query = $this->pool->getQueryBuilderForTable('slug_fixture');
        $connection = $this->createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($query);
        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => $id === ConnectionPool::class);
        $container->method('get')->willReturn($pool);
        $containerProperty = new \ReflectionProperty(GeneralUtility::class, 'container');
        $previousContainer = $containerProperty->getValue();
        GeneralUtility::setContainer($container);

        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('Record [#42] in table "slug_fixture" could not be found.');
            Slug::generateUniqueSlug(42, 'slug_fixture', 'slug');
        } finally {
            $containerProperty->setValue(null, $previousContainer);
        }
    }
}
