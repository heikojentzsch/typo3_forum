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

use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractTagBasedViewHelper;

/**
 * ViewHelper that renders a form row.
 */
class RowViewHelper extends AbstractTagBasedViewHelper
{
    protected $tagName = 'div';

    public function initialize(): void
    {
        parent::initialize();
    }

    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $this->registerArgument(
            'llLabel',
            'string',
            'Locallang key for label.',
            false,
            ''
        );

        $this->registerArgument(
            'label',
            'string',
            'Hardcoded label (better to use llLabel instead).',
            false,
            ''
        );

        $this->registerArgument(
            'labelFor',
            'string',
            'ID of the input to be used in label-for attribute',
            false,
            ''
        );

        $this->registerArgument(
            'error',
            'string',
            'Error property path.',
            false
        );

        $this->registerArgument(
            'errorLLPrefix',
            'string',
            'Error label locallang prefix.',
            false
        );
    }

    public function render(): string
    {
        $class = 'control-group';
        $errorContent = '';

        if ($this->arguments['llLabel']) {
            $label = LocalizationUtility::translate(
                $this->arguments['llLabel'],
                'typo3_forum'
            );
        } else {
            $label = $this->arguments['label'];
        }

        if ($this->arguments['error']) {
            $request = $this->renderingContext->getAttribute(\Psr\Http\Message\ServerRequestInterface::class);
            $results = $request->getAttribute('extbase')?->getOriginalRequestMappingResults();
            $errors = $results?->forProperty($this->arguments['error'])->getErrors() ?? [];

            if (!empty($errors)) {
                $class .= ' error';

                foreach ($errors as $error) {
                    $errorText = LocalizationUtility::translate(
                        $this->arguments['errorLLPrefix'] . '_' . $error->getCode(),
                        'typo3_forum'
                    );

                    if (!$errorText) {
                        $errorText = 'TRANSLATE: '
                            . $this->arguments['errorLLPrefix']
                            . '_'
                            . $error->getCode();
                    }

                    $errorContent .= '<p class="invalid-feedback help-block">'
                        . htmlspecialchars($errorText)
                        . '</p>';
                }
            }
        }

        $label = '<label'
            . ($this->arguments['labelFor']
                ? ' for="' . htmlspecialchars($this->arguments['labelFor']) . '"'
                : '')
            . '>'
            . htmlspecialchars((string)$label)
            . '</label>';

        $content = '<div>'
            . $this->renderChildren()
            . $errorContent
            . '</div>';

        $this->tag->addAttribute('class', $class);
        $this->tag->setContent($label . $content);

        return $this->tag->render();
    }

    /**
     * Find errors for a specific property in the given errors array.
     * @phpstan-param array<string, \TYPO3\CMS\Extbase\Error\Result> $errors
     * @phpstan-return list<\TYPO3\CMS\Extbase\Error\Error>
     */
    protected function getErrorsForProperty(string $propertyName, array $errors): array
    {
        foreach ($errors as $name => $error) {
            if ($name === $propertyName) {
                return array_unique($error->getErrors());
            }
        }

        return [];
    }
}
