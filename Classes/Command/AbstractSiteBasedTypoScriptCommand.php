<?php

namespace Mittwald\Typo3Forum\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Configuration\BackendConfigurationManager;
use TYPO3\CMS\Core\Http\ServerRequest;

abstract class AbstractSiteBasedTypoScriptCommand extends Command
{
    protected SiteFinder $siteFinder;
    protected BackendConfigurationManager $typoScriptConfiguration;

    /** @var array<string, mixed> */
    protected array $settings = [];
    protected int $storagePage = 0;

    public function injectTyposcriptHelpers(
        SiteFinder $siteFinder,
        BackendConfigurationManager $typoScriptConfiguration
    ): void {
        $this->siteFinder = $siteFinder;
        $this->typoScriptConfiguration = $typoScriptConfiguration;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            $this->settings = [];

            $setup = $this->getSetupForSite($site);
            $rawTyposcript = $setup['plugin.']['tx_typo3forum.'] ?? [];
            $this->settings = $rawTyposcript['settings.'] ?? [];

            if (count($this->settings) > 0) {
                $storagePage = (int)($rawTyposcript['persistence.']['storagePid'] ?? 0);
                $this->setStoragePage($storagePage);
                $this->storagePage = $storagePage;

                $this->executeForSite($site, $input, $output);
            }
        }

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    protected function getSetupForSite(SiteInterface $site): array
    {
        // The core's backend setup reader also supports evaluation without a frontend
        // lifecycle: supply the site and root page explicitly for this CLI invocation.
        $request = (new ServerRequest((string)$site->getBase()))
            ->withQueryParams(['id' => $site->getRootPageId()])
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage());
        return $this->typoScriptConfiguration->getTypoScriptSetup($request);
    }

    /**
     * Override to set the storage page for repositories.
     */
    protected function setStoragePage(int $storagePid): void
    {}

    /**
     * Execute this command for a site. Automatically called for every
     * site whose root page contains typo3_forum TypoScript configuration.
     */
    abstract protected function executeForSite(
        SiteInterface $site,
        InputInterface $input,
        OutputInterface $output
    ): void;
}
