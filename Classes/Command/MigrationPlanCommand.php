<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrationPlanCommand extends AbstractMigrationCommand
{
    protected function configure(): void
    {
        $this->setDescription('Create a deterministic, validated forum migration plan without database writes.')
            ->addOption('preflight', null, InputOption::VALUE_REQUIRED, 'Protected source-preflight manifest to validate.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Required explicit path for the protected plan.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string)$input->getOption('output');
        if ($path === '') {
            throw new \InvalidArgumentException('--output is required.');
        }
        $plan = $this->migrationService->plan($this->preflight($input));
        $this->migrationFile->write($path, $plan);
        $output->writeln(sprintf('Status: %s; operations: %d; checksum: %s', $plan['status'], count($plan['operations']), $plan['checksum']));
        return $this->exitCode($plan['status']);
    }
}
