<?php

namespace Mittwald\Typo3Forum\ViewHelpers\Format;

use Mittwald\Typo3Forum\Domain\Model\Forum\Post;
use Mittwald\Typo3Forum\TextParser\TextParserService;

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

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper that performs text parsing operations on text input.
 */
class TextParserViewHelper extends AbstractViewHelper
{
    protected TextParserService $textParserService;

    public function __construct(
        TextParserService $textParserService
    ) {
        $this->textParserService = $textParserService;
    }

    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('html', 'string', 'Explicit HTML v1 content; never auto-detected.', false, null);

        $this->registerArgument(
            'post',
            Post::class,
            '',
            false,
            null
        );

        $this->registerArgument(
            'content',
            'string',
            'The content to be rendered. If NULL, the node content will be rendered instead.',
            false,
            null
        );
    }

    public function render(): string
    {
        if ($this->arguments['html'] !== null) {
            return $this->renderQuotes($this->arguments['html']);
        }
        /** @var ?Post $post */
        $post = $this->arguments['post'];

        if ($post !== null) {
            return $this->renderQuotes($this->textParserService->parseText(
                $post->getText(),
                $post
            ));
        }

        return $this->renderQuotes($this->textParserService->parseText(
            $this->arguments['content']
                ?? trim((string)$this->renderChildren())
        ));
    }

    private function renderQuotes(string $html): string
    {
        $html = (new \Mittwald\Typo3Forum\RichText\Html())->sanitize($html);
        if (!str_contains($html, '<blockquote data-post="')) {
            return $html;
        }
        $settings = $this->renderingContext->getVariableProvider()->get('settings');
        $pageUid = (int)($settings['pids']['Forum'] ?? 0);
        $label = \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('quote.originalPost', 'Typo3Forum') ?? 'Original post';
        return (new \Mittwald\Typo3Forum\RichText\QuoteRenderer())->render($html, function (int $uid) use ($pageUid): string {
            return (string)$this->renderingContext->getViewHelperInvoker()->invoke(
                \TYPO3\CMS\Fluid\ViewHelpers\Uri\ActionViewHelper::class,
                ['pageUid' => $pageUid, 'extensionName' => 'Typo3Forum', 'pluginName' => 'Forum',
                    'controller' => 'Post', 'action' => 'show', 'arguments' => ['post' => $uid]],
                $this->renderingContext,
                static fn (): string => ''
            );
        }, $label);
    }
}
