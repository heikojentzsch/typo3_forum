<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrationApplyCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->setDescription('Apply an approved migration plan after rechecking every source fingerprint.')
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Path to the protected migration plan.')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Exact checksum printed by forum:migration:plan.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate the plan and current source fingerprints without any write.')
            ->addOption('resume-interrupted', null, InputOption::VALUE_NONE, 'Recover the same plan lock after independently proving no migration process is active.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Optional explicit path for a protected result report.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string)$input->getOption('plan');
        if ($path === '') {
            throw new \InvalidArgumentException('--plan is required.');
        }
        $plan = $this->migrationFile->read($path, 'typo3-forum-plan/2.0');
        if ((bool)$input->getOption('dry-run')) {
            $result = $this->migrationService->dryRun($plan);
            $result['applied'] = 0;
            $result['resumed'] = 0;
        } else {
            if ((string)$input->getOption('confirm') === '') {
                throw new \InvalidArgumentException('--confirm is required unless --dry-run is used.');
            }
            $result = $this->migrationService->apply($plan, (string)$input->getOption('confirm'), (bool)$input->getOption('resume-interrupted'));
        }
        if ((string)$input->getOption('output') !== '') {
            $this->migrationFile->write((string)$input->getOption('output'), $result);
        }
        $output->writeln(sprintf('Status: %s; applied: %d; resumed: %d', $result['status'], $result['applied'], $result['resumed']));
        return $this->exitCode($result['status']);
    }
}
