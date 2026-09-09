<?php

declare(strict_types=1);

namespace Pottkinder\Typo3ForumDev;

use Doctrine\DBAL\ParameterType;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class OwnershipStore
{
    private const OWNED_TABLE = 'tx_typo3forumdevbootstrap_owned';
    private const PHASE_TABLE = 'tx_typo3forumdevbootstrap_phase';

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function getOrCreate(string $logicalKey, string $table, array $data): int
    {
        $ownedConnection = $this->connectionPool->getConnectionForTable(self::OWNED_TABLE);
        $owned = $ownedConnection->fetchAssociative(
            'SELECT table_name, record_uid FROM ' . self::OWNED_TABLE . ' WHERE logical_key = ?',
            [$logicalKey]
        );
        if ($owned !== false) {
            if ($owned['table_name'] !== $table) {
                throw new RuntimeException(sprintf('Ownership collision for %s.', $logicalKey));
            }
            $recordUid = (int)$owned['record_uid'];
            $connection = $this->connectionPool->getConnectionForTable($table);
            $exists = (int)$connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($table) . ' WHERE uid = ?',
                [$recordUid],
                [ParameterType::INTEGER]
            );
            if ($exists !== 1) {
                throw new RuntimeException(sprintf('Managed record %s is missing; refusing to create a duplicate.', $logicalKey));
            }
            return $recordUid;
        }

        $connection = $this->connectionPool->getConnectionForTable($table);
        $ownedConnection->beginTransaction();
        try {
            $connection->insert($table, $data);
            $recordUid = (int)$connection->lastInsertId();
            $ownedConnection->insert(self::OWNED_TABLE, [
                'logical_key' => $logicalKey,
                'table_name' => $table,
                'record_uid' => $recordUid,
                'fixture_version' => 1,
                'created_at' => time(),
            ]);
            $ownedConnection->commit();
            return $recordUid;
        } catch (\Throwable $throwable) {
            $ownedConnection->rollBack();
            throw $throwable;
        }
    }

    public function uid(string $logicalKey): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::OWNED_TABLE);
        $uid = $connection->fetchOne('SELECT record_uid FROM ' . self::OWNED_TABLE . ' WHERE logical_key = ?', [$logicalKey]);
        if ($uid === false) {
            throw new RuntimeException(sprintf('Managed fixture record %s is missing.', $logicalKey));
        }
        return (int)$uid;
    }

    public function has(string $logicalKey): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::OWNED_TABLE);
        return (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . self::OWNED_TABLE . ' WHERE logical_key = ?', [$logicalKey]) === 1;
    }

    public function completePhase(string $phase): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::PHASE_TABLE);
        $exists = (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . self::PHASE_TABLE . ' WHERE phase = ?', [$phase]);
        if ($exists === 0) {
            $connection->insert(self::PHASE_TABLE, ['phase' => $phase, 'fixture_version' => 1, 'completed_at' => time()]);
        }
    }

    public function phaseComplete(string $phase): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::PHASE_TABLE);
        return (int)$connection->fetchOne('SELECT COUNT(*) FROM ' . self::PHASE_TABLE . ' WHERE phase = ?', [$phase]) === 1;
    }
}
