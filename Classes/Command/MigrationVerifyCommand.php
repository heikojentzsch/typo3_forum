<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrationVerifyCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->setDescription('Verify migrated records and forum data integrity against an approved plan.')
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Path to the protected migration plan.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Optional explicit path for a protected result report.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string)$input->getOption('plan');
        if ($path === '') {
            throw new \InvalidArgumentException('--plan is required.');
        }
        $result = $this->migrationService->verify($this->migrationFile->read($path, 'typo3-forum-plan/1.0'));
        if ((string)$input->getOption('output') !== '') {
            $this->migrationFile->write((string)$input->getOption('output'), $result);
        }
        $output->writeln(sprintf('Status: %s; problems: %d', $result['status'], count($result['problems'])));
        return $this->exitCode($result['status']);
    }
}
