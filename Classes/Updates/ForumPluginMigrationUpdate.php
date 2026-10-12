<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Updates;

use Mittwald\Typo3Forum\Migration\MigrationContract;
use Mittwald\Typo3Forum\Migration\MigrationService;
use RuntimeException;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\AbstractListTypeToCTypeUpdate;

#[UpgradeWizard('typo3ForumVerifiedPluginMigration')]
final class ForumPluginMigrationUpdate extends AbstractListTypeToCTypeUpdate implements \TYPO3\CMS\Core\Upgrades\RepeatableInterface
{
    public function __construct(
        ConnectionPool $connectionPool,
        private readonly MigrationService $migrationService,
        private readonly MigrationContract $migrationContract,
    ) {
        parent::__construct($connectionPool);
    }

    protected function getListTypeToCTypeMapping(): array
    {
        return $this->migrationContract->standardPlugins();
    }

    public function getTitle(): string
    {
        return '[typo3_forum]: Verified v14 plugin and rich-text migration';
    }

    public function getDescription(): string
    {
        return 'Checks utf8mb4, migrates forum plugins and converts BBCode into separate HTML fields while preserving source text, FlexForms and equivalent backend permissions. Unknown legacy states block execution. Existing plans must be regenerated for rich-text migration.';
    }

    public function updateNecessary(): bool
    {
        $check = $this->migrationService->check();
        return $check['status'] !== 'ALREADY_MIGRATED';
    }

    public function executeUpdate(): bool
    {
        $plan = $this->migrationService->plan();
        if ($plan['status'] === 'ALREADY_MIGRATED') {
            return true;
        }
        if ($plan['status'] !== 'READY') {
            throw new RuntimeException('Forum migration is blocked. Run forum:migration:check for the machine-readable findings.');
        }
        return $this->migrationService->apply($plan, $plan['checksum'])['status'] === 'SUCCESS';
    }
}
