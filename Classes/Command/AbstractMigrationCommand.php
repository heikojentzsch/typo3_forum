<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Command;

use Mittwald\Typo3Forum\Migration\MigrationFile;
use Mittwald\Typo3Forum\Migration\MigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

abstract class AbstractMigrationCommand extends Command
{
    public function __construct(
        protected readonly MigrationService $migrationService,
        protected readonly MigrationFile $migrationFile,
    ) {
        parent::__construct();
    }

    /** @return array<string, mixed>|null */
    protected function preflight(InputInterface $input): ?array
    {
        $path = (string)$input->getOption('preflight');
        return $path === '' ? null : $this->migrationFile->read($path, 'typo3-forum-preflight/2.0');
    }

    protected function exitCode(string $status): int
    {
        return match ($status) {
            'READY', 'SUCCESS' => 0,
            'ALREADY_MIGRATED', 'NO_MIGRATION_REQUIRED' => 10,
            'BLOCKED' => 20,
            'INDETERMINATE' => 30,
            default => 40,
        };
    }
}
