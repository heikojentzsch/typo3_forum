<?php

namespace Mittwald\Typo3Forum\ViewHelpers\Form;

/*                                                                    - *
 *  COPYRIGHT NOTICE                                                    *
 *                                                                      *
 *  (c) 2015 Mittwald CM Service GmbH & Co KG                           *
 *           All rights reserved                                        *
 *                                                                      *
 *  This script is part of the TYPO3 project. The TYPO3 project is      *
 *  free software; you can redistribute it and/or modify                *
 *  it under the terms of the GNU General Public License as published   *
 *  by the Free Software Foundation; either version 2 of the License,   *
 *  or (at your option) any later version.                              *
 *                                                                      *
 *  The GNU General Public License can be found at                      *
 *  http://www.gnu.org/copyleft/gpl.html.                               *
 *                                                                      *
 *  This script is distributed in the hope that it will be useful,      *
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of      *
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the       *
 *  GNU General Public License for more details.                        *
 *                                                                      *
 *  This copyright notice MUST APPEAR in all copies of the script!      *
 *                                                                      */

use Mittwald\Typo3Forum\TextParser\Panel\AbstractPanel;
use Mittwald\Typo3Forum\TextParser\Panel\PanelInterface;
use Mittwald\Typo3Forum\Utility\TypoScript;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Persistence\Generic\Exception\InvalidClassException;
use TYPO3\CMS\Fluid\ViewHelpers\Form\AbstractFormFieldViewHelper;
use TYPO3\CMS\Fluid\ViewHelpers\Form\TextareaViewHelper;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * ViewHelper that renders a textarea with additional bb code buttons.
 */
class BbCodeEditorViewHelper extends AbstractFormFieldViewHelper
{
    protected FrontendInterface $cache;
    protected TypoScript $typoscriptReader;
    protected UriBuilder $uriBuilder;

    public function __construct(
        FrontendInterface $cache,
        TypoScript $typoscriptReader,
        UriBuilder $uriBuilder
    ) {
        parent::__construct();

        $this->cache = $cache;
        $this->typoscriptReader = $typoscriptReader;
        $this->uriBuilder = $uriBuilder;
    }

    /**
     * Configuration array. This array is read from the typoscript setup by
     * the typoscript reader instance.
     * @phpstan-var array<string, mixed>
     */
    protected array $configuration = [];

    /**
     * Panels that contain bb code buttons.
     *
     * @var PanelInterface[]
     */
    protected array $panels = [];

    protected string $javascriptSetup;

    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $this->registerArgument(
            'configuration',
            'string',
            'Path to TS configuration',
            false,
            'plugin.tx_typo3forum.settings.textParsing.editorPanel'
        );

        $this->registerArgument(
            'id',
            'string',
            'id',
            false
        );
    }

    /**
     * @throws InvalidClassException
     */
    protected function initializeJavascriptSetupFromConfiguration(
        string $configurationPath
    ): string {
        // TODO reenable cache of bbcodeeditor
        // @phpstan-ignore booleanAnd.leftAlwaysFalse (The existing cache bypass remains intentional.)
        if (false && $this->cache->has('bbcodeeditor-jsonconfig')) {
            $this->javascriptSetup = $this->cache->get('bbcodeeditor-jsonconfig');

            return $this->javascriptSetup;
        }

        $this->configuration = $this->typoscriptReader
            ->loadTyposcriptFromPath($configurationPath);

        $this->panels = [];
        foreach ($this->configuration['panels.'] as $panelConfiguration) {
            /** @var class-string $panelClass TypoScript names the panel; its interface is checked below. */
            $panelClass = $panelConfiguration['className'];
            $panel = GeneralUtility::makeInstance($panelClass);

            if (!$panel instanceof PanelInterface) {
                throw new InvalidClassException(
                    'Expected an implementation of the '
                    . PanelInterface::class
                    . ' interface!',
                    1315835842
                );
            }

            $panel->setSettings($panelConfiguration);
            $this->panels[] = $panel;
        }

        $this->javascriptSetup = '<script>'
            . 'var bbcodeSettings = '
            . json_encode($this->getPanelSettings())
            . ';'
            . 'window.setTimeout(function(){$(document).ready(function() {'
            . '$(document.getElementById('
            . json_encode($this->arguments['id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . ')).markItUp(bbcodeSettings);'
            . '});}, 500);</script>';

        $this->cache->set(
            'bbcodeeditor-jsonconfig',
            $this->javascriptSetup
        );

        return $this->javascriptSetup;
    }

    public function render(): string
    {
        $this->initializeJavascriptSetupFromConfiguration(
            $this->arguments['configuration']
        );

        $arguments = array_merge($this->arguments, $this->additionalArguments);
        unset($arguments['configuration']);
        return $this->javascriptSetup . $this->renderingContext->getViewHelperInvoker()->invoke(
            TextareaViewHelper::class,
            $arguments,
            $this->renderingContext
        );
    }

    /** @return array<string, mixed> */
    protected function getPanelSettings(): array
    {
        $settings = [];

        foreach ($this->panels as $panel) {
            $items = $panel->getItems();

            if ($items !== null && count($items) > 0) {
                $settings = array_merge($settings, $items);
                $settings[] = [
                    'separator' => '---------------',
                ];
            }
        }

        $settings[] = [
            'name' => 'Preview',
            'className' => 'preview',
            'call' => 'preview',
        ];

        if (
            !$this->renderingContext->hasAttribute(
                ServerRequestInterface::class
            )
        ) {
            throw new \RuntimeException(
                'No frontend request is available in the Fluid rendering context.',
                1788746403
            );
        }

        $request = $this->renderingContext->getAttribute(
            ServerRequestInterface::class
        );

        if (!$request instanceof ServerRequestInterface) {
            throw new \RuntimeException(
                'Invalid request object in the Fluid rendering context.',
                1788746405
            );
        }

        $pageInformation = $request->getAttribute(
            'frontend.page.information'
        );

        if (!$pageInformation instanceof PageInformation) {
            throw new \RuntimeException(
                'Current frontend page information is not available.',
                1788746406
            );
        }

        $uri = $this->uriBuilder
            ->reset()
            ->setRequest($this->getRequest())
            ->setTargetPageUid($pageInformation->getId())
            ->setArguments([
                'type' => 43568275,
            ])
            ->uriFor(
                'preview',
                [],
                'Ajax',
                'Typo3Forum',
                'Ajax'
            );

        $editorSettings = [
            'previewParserPath' => $uri,
            'previewParserVar' => 'tx_typo3forum_ajax[text]',
            'markupSet' => $settings,
        ];

        if (
            isset($this->configuration['editorSettings.'])
            && is_array($this->configuration['editorSettings.'])
        ) {
            $editorSettings = array_merge(
                $editorSettings,
                $this->configuration['editorSettings.']
            );
        }

        return $editorSettings;
    }
}
