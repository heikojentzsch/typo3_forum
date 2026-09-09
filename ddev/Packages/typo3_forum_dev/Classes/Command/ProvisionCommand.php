<?php

declare(strict_types=1);

namespace Pottkinder\Typo3ForumDev\Command;

use Pottkinder\Typo3ForumDev\FixtureProvisioner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ProvisionCommand extends Command
{
    public function __construct(private readonly FixtureProvisioner $provisioner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $records = $this->provisioner->provision();
        $io->success(sprintf('Development fixture is provisioned (root page UID %d).', $records['root']));
        return Command::SUCCESS;
    }
}
