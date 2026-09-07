<?php

namespace Mittwald\Typo3Forum\TextParser\Service;

use Mittwald\Typo3Forum\Domain\Model\Forum\Post;
use Mittwald\Typo3Forum\Domain\Repository\Forum\PostRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;

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

class QuoteParserService extends AbstractTextParserService
{
    public function __construct(
        protected PostRepository $postRepository,
        protected ViewFactoryInterface $viewFactory
    ) {
    }

    public function getParsedText(
        string $text,
        ?Post $post = null
    ): string {
        do {
            $text = preg_replace_callback(
                '/\[quote](.*?)\[\/quote\]\w*/is',
                [$this, 'replaceSingleCallback'],
                $text,
                -1,
                $count
            );
        } while ($count > 0);

        do {
            $text = preg_replace_callback(
                '/\[quote=([0-9]+)\](.*?)\[\/quote\]\w*/is',
                [$this, 'replaceCallback'],
                $text,
                -1,
                $count
            );
        } while ($count > 0);

        return $text;
    }

    protected function replaceSingleCallback(array $matches): string
    {
        $view = $this->createQuoteView();

        $view->assignMultiple([
            'post' => null,
            'quote' => trim($matches[1]),
        ]);

        return $view->render();
    }

    protected function replaceCallback(array $matches): string
    {
        $view = $this->createQuoteView();

        $post = $this->postRepository->findByUid(
            (int)$matches[1]
        );

        $view->assignMultiple([
            'post' => $post,
            'quote' => trim($matches[2]),
        ]);

        return $view->render();
    }

    private function createQuoteView(): ViewInterface
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        if (!$request instanceof ServerRequestInterface) {
            throw new \RuntimeException(
                'A frontend request is required to render forum quotes.',
                1788751001
            );
        }

        return $this->viewFactory->create(
            new ViewFactoryData(
                templatePathAndFilename: 'EXT:typo3_forum/Resources/Private/Partials/Bootstrap/Format/Quote.html',
                request: $request,
                format: 'html'
            )
        );
    }
}