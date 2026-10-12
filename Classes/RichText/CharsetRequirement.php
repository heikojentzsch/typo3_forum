<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\RichText;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class CharsetRequirement
{
    /** @var list<array<string, mixed>>|null */
    private ?array $cachedFindings = null;
    public function __construct(private readonly ConnectionPool $pool)
    {
    }

    /** @return list<array<string, mixed>> */
    public function findings(): array
    {
        if ($this->cachedFindings !== null) {
            return $this->cachedFindings;
        }
        $findings = [];
        foreach ([...(new \Mittwald\Typo3Forum\Migration\MigrationContract())->integrityTables(), 'fe_users'] as $table) {
            $connection = $this->pool->getConnectionForTable($table);
            if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                continue; // PostgreSQL/SQLite have no MySQL utf8mb3 limitation.
            }
            $charset = $connection->fetchAssociative('SELECT @@character_set_client AS client, @@character_set_connection AS connection, @@character_set_results AS results');
            foreach ($charset ?: [] as $name => $value) {
                if ($value !== 'utf8mb4') {
                    $findings[] = ['severity' => 'blocker', 'code' => 'UTF8MB4_CONNECTION_REQUIRED', 'table' => $table, 'setting' => $name];
                }
            }
            $columns = $connection->fetchAllAssociative('SELECT COLUMN_NAME, CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CHARACTER_SET_NAME IS NOT NULL', [$table]);
            foreach ($columns as $column) {
                if ($column['CHARACTER_SET_NAME'] !== 'utf8mb4') {
                    $findings[] = ['severity' => 'blocker', 'code' => 'UTF8MB4_COLUMN_REQUIRED', 'table' => $table, 'column' => $column['COLUMN_NAME']];
                }
            }
        }
        return $this->cachedFindings = $findings;
    }

    public function assertReady(): void
    {
        if ($this->findings() !== []) {
            throw new \RuntimeException('typo3_forum v14 requires utf8mb4 for forum/user text columns and database connections. Run forum:migration:check and follow Documentation/Editors.', 1791800001);
        }
    }
}
