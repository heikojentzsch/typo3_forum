<?php

namespace Mittwald\Typo3Forum\TextParser;

use Mittwald\Typo3Forum\Domain\Model\Forum\Post;
use Mittwald\Typo3Forum\Service\AbstractService;
use Mittwald\Typo3Forum\TextParser\Service\AbstractTextParserService;
use Mittwald\Typo3Forum\Utility\TypoScript;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/*                                                                    - *
 *  COPYRIGHT NOTICE                                                    *
 *                                                                      *
 *  (c) 2015 Mittwald CM Service GmbH & Co KG                           *
 *           All rights reserved                                        *
 *                                                                      *
 *  This script is part of the TYPO3 project. The TYPO3 project is      *
 *  free software; you can redistribute it and/or modify                *
 *  it under the terms of the GNU General public License as published   *
 *  by the Free Software Foundation; either version 2 of the License,   *
 *  or (at your option) any later version.                              *
 *                                                                      *
 *  The GNU General public License can be found at                      *
 *  http://www.gnu.org/copyleft/gpl.html.                               *
 *                                                                      *
 *  This script is distributed in the hope that it will be useful,      *
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of      *
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the       *
 *  GNU General public License for more details.                        *
 *                                                                      *
 *  This copyright notice MUST APPEAR in all copies of the script!      *
 *                                                                      */

class TextParserService extends AbstractService
{
    /** @var array<string, mixed> */
    protected array $settings = [];

    /**
     * @var AbstractTextParserService[]
     */
    protected array $parsingServices = [];

    protected TypoScript $typoscriptReader;

    public function __construct(
        TypoScript $typoScriptReader
    ) {
        $this->typoscriptReader = $typoScriptReader;
    }

    public function loadConfiguration(
        string $configurationPath = 'plugin.tx_typo3forum.settings.textParsing'
    ): void {
        if (count($this->settings) > 0) {
            return;
        }

        $this->settings = $this->typoscriptReader
            ->loadTyposcriptFromPath($configurationPath);

        foreach ($this->settings['enabledServices.'] as $key => $className) {
            if (substr($key, -1, 1) === '.') {
                continue;
            }

            /** @var class-string $className TypoScript names the service; its interface is checked below. */
            $newService = GeneralUtility::makeInstance($className);

            if (!$newService instanceof AbstractTextParserService) {
                throw new \Mittwald\Typo3Forum\Domain\Exception\TextParser\Exception(
                    'Invalid class; expected an instance of '
                    . AbstractTextParserService::class
                    . '!',
                    1315916625
                );
            }

            $newService->setSettings(
                (array)($this->settings['enabledServices.'][$key . '.'] ?? [])
            );

            $this->parsingServices[] = $newService;
        }
    }

    public function parseText(
        string $text,
        ?Post $post = null
    ): string {
        foreach ($this->parsingServices as $parsingService) {
            $text = $parsingService->getParsedText($text, $post);
        }

        return $text;
    }
}
