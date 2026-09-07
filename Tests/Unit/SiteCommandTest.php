<?php
declare(strict_types=1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Command\AbstractSiteBasedTypoScriptCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\{ArrayInput, InputInterface};
use Symfony\Component\Console\Output\{NullOutput, OutputInterface};
use TYPO3\CMS\Core\Site\Entity\{Site, SiteInterface};
use TYPO3\CMS\Core\Site\SiteFinder;

final class SiteCommandTest extends TestCase
{
    public function testEachConfiguredSiteGetsItsOwnSettingsAndStoragePid(): void
    {
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getAllSites')->willReturn([
            new Site('one', 1, []), new Site('two', 2, []), new Site('empty', 3, []),
        ]);
        $command = new class($finder) extends AbstractSiteBasedTypoScriptCommand {
            public array $runs = [];
            public function __construct(SiteFinder $finder) { parent::__construct('test'); $this->siteFinder = $finder; }
            protected function getSetupForSite(SiteInterface $site): array {
                return $site->getRootPageId() === 3 ? [] : ['plugin.' => ['tx_typo3forum.' => [
                    'settings.' => ['rankScore.' => ['newPost' => $site->getRootPageId()]],
                    'persistence.' => ['storagePid' => $site->getRootPageId() * 10],
                ]]];
            }
            protected function executeForSite(SiteInterface $site, InputInterface $input, OutputInterface $output): void {
                $this->runs[] = [$site->getIdentifier(), $this->storagePage, $this->settings];
            }
        };
        self::assertSame(0, $command->run(new ArrayInput([]), new NullOutput()));
        self::assertSame([
            ['one', 10, ['rankScore.' => ['newPost' => 1]]],
            ['two', 20, ['rankScore.' => ['newPost' => 2]]],
        ], $command->runs);
    }
}
