<?php

declare(strict_types=1);

namespace Pottkinder\Typo3ForumDev\Command;

use Pottkinder\Typo3ForumDev\FixtureVerifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class CheckCommand extends Command
{
    public function __construct(private readonly FixtureVerifier $verifier)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        foreach ($this->verifier->verify() as $check) {
            $io->writeln('[OK] ' . $check);
        }
        $io->success('Database and configuration checks passed.');
        return Command::SUCCESS;
    }
}
