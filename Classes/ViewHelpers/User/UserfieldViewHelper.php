<?php

namespace Mittwald\Typo3Forum\ViewHelpers\User;

/*                                                                      *
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

use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Model\User\Userfield\AbstractUserfield;
use Mittwald\Typo3Forum\Domain\Model\User\Userfield\TyposcriptUserfield;
use TYPO3\CMS\Core\Country\CountryProvider;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Fluid\ViewHelpers\CObjectViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper that renders the value of a specific userfield for a user.
 */
class UserfieldViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('user', FrontendUser::class, 'Frontend user object', true);
        $this->registerArgument('userfield', AbstractUserfield::class, 'User field', true);
    }

    public function render(): string
    {
        $user = $this->arguments['user'];
        $userfield = $this->arguments['userfield'];

        if (!$userfield instanceof TyposcriptUserfield) {
            throw new \InvalidArgumentException(
                'Only userfields of type TyposcriptUserField are supported',
                1435048481
            );
        }

        return implode(
            ', ',
            array_filter(
                array_map(
                    function (string $propertyName) use ($userfield, $user): string {
                        if ($propertyName === 'country') {
                            return self::renderCountry($user->getCountry());
                        }

                        return CObjectViewHelper::renderStatic(
                            [
                                'typoscriptObjectPath' => $userfield->getTyposcriptPath() . '.output',
                                'currentValueKey' => $propertyName,
                                'table' => 'fe_users',
                            ],
                            function () use ($user) {
                                return $user;
                            },
                            $this->renderingContext
                        );
                    },
                    explode('|', $userfield->getUserObjectPropertyName())
                ),
                static function (string $renderedItem): bool {
                    return $renderedItem !== '';
                }
            )
        );
    }

    private static function renderCountry(string $alpha3IsoCode): string
    {
        if ($alpha3IsoCode === '') {
            return '';
        }

        $countryProvider = GeneralUtility::makeInstance(CountryProvider::class);
        $country = $countryProvider->getByAlpha3IsoCode($alpha3IsoCode);

        if ($country === null) {
            return $alpha3IsoCode;
        }

        return (string)LocalizationUtility::translate(
            $country->getLocalizedNameLabel()
        );
    }
}