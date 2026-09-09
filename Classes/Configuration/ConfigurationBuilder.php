<?php

namespace Mittwald\Typo3Forum\Configuration;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;

/***************************************************************
 *  Copyright (C) 2017 punkt.de GmbH
 *  Authors: el_equipo <el_equipo@punkt.de>
 *
 *  This script is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Lesser General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Lesser General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

class ConfigurationBuilder implements SingletonInterface
{
    /** @var array<string, mixed> */
    protected array $settings = [];
    /** @var array<string, mixed> */
    protected array $persistenceSettings = [];

    public function __construct()
    {
    }

    /**
     * @throws InvalidConfigurationException
     * @phpstan-return array<string, mixed>
     */
    public function getSettings(): array
    {
        if (empty($this->settings)) {
            $this->loadTypoScript();
        }

        return $this->settings;
    }

    /**
     * @throws InvalidConfigurationException
     * @phpstan-return array<string, mixed>
     */
    public function getPersistenceSettings(): array
    {
        if (empty($this->persistenceSettings)) {
            $this->loadTypoScript();
        }

        return $this->persistenceSettings;
    }

    /**
     * @throws InvalidConfigurationException
     */
    protected function loadTypoScript(): void
    {
        $typoScript = $this->getFrontendTypoScript()
            ->getSetupArray()['plugin.']['tx_typo3forum.'] ?? [];

        if ($typoScript === []) {
            throw new InvalidConfigurationException(
                'The TypoScript configuration for typo3_forum is missing. Include it via a template or a TypoScript file.',
                1561441468
            );
        }

        $this->settings = $typoScript['settings.'] ?? [];
        $this->persistenceSettings = $typoScript['persistence.'] ?? [];
    }

    /**
     * @throws InvalidConfigurationException
     */
    protected function getFrontendTypoScript(): FrontendTypoScript
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        if (!$request instanceof ServerRequestInterface) {
            throw new InvalidConfigurationException(
                'No frontend request is available to read the typo3_forum TypoScript configuration.',
                1788746401
            );
        }

        $frontendTypoScript = $request->getAttribute('frontend.typoscript');

        if (!$frontendTypoScript instanceof FrontendTypoScript) {
            throw new InvalidConfigurationException(
                'The frontend TypoScript request attribute is not available.',
                1788746402
            );
        }

        return $frontendTypoScript;
    }
}
