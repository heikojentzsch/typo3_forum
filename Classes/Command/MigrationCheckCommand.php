<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrationCheckCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->setDescription('Read-only inventory and migration readiness check.')
            ->addOption('preflight', null, InputOption::VALUE_REQUIRED, 'Protected source-preflight manifest to validate.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Optional explicit path for a protected JSON report.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->migrationService->check($this->preflight($input));
        if ((string)$input->getOption('output') !== '') {
            $this->migrationFile->write((string)$input->getOption('output'), $report);
        }
        $output->writeln(sprintf('Status: %s; content records: %d; findings: %d', $report['status'], count($report['inventory']['content_records']), count($report['findings'])));
        return $this->exitCode($report['status']);
    }
}
