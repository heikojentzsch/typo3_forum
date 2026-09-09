<?php

namespace Mittwald\Typo3Forum\ViewHelpers\Forum;

use Mittwald\Typo3Forum\Domain\Model\Forum\ShadowTopic;

/* *
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

use Mittwald\Typo3Forum\Domain\Model\Forum\Topic;
use Mittwald\Typo3Forum\Domain\Repository\User\FrontendUserRepository;
use Mittwald\Typo3Forum\Service\TypoScriptRenderingService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper that renders a topic icon.
 */
class TopicIconViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    protected FrontendUserRepository $frontendUserRepository;

    public function __construct(
        FrontendUserRepository $frontendUserRepository
    ) {
        $this->frontendUserRepository = $frontendUserRepository;
    }

    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $this->registerArgument(
            'important',
            'integer',
            'Amount of posts required for a topic to contain in order to be marked as important',
            false,
            15
        );

        $this->registerArgument(
            'topic',
            Topic::class,
            'Current topic',
            true
        );

        $this->registerArgument(
            'width',
            'int',
            'Width',
            false
        );
    }

    public function render(): string
    {
        $topic = $this->arguments['topic'];
        $data = $this->getDataArray($topic);

        $typoScriptObjectPath = $data['new'] ?? false
            ? 'plugin.tx_typo3forum.renderer.icons.topic_new'
            : 'plugin.tx_typo3forum.renderer.icons.topic';

        $renderingService = GeneralUtility::makeInstance(
            TypoScriptRenderingService::class
        );

        return $renderingService->render(
            $this->getRequest(),
            $typoScriptObjectPath,
            $data,
            '',
            'tt_content'
        );
    }

    /** @return array<string, mixed> */
    protected function getDataArray(?Topic $topic = null): array
    {
        if ($topic === null) {
            return [];
        }

        if ($topic instanceof ShadowTopic) {
            return [
                'moved' => true,
            ];
        }

        $isImportant = $topic->getPostCount()
            >= $this->arguments['important'];

        return [
            'important' => $isImportant,
            'new' => !$topic->hasBeenReadByUser(
                $this->frontendUserRepository->findCurrent()
            ),
            'closed' => $topic->isClosed(),
            'sticky' => $topic->isSticky(),
            'solved' => $topic->isSolved(),
            'question' => $topic->isQuestion(),
        ];
    }

    private function getRequest(): ServerRequestInterface
    {
        if (
            !$this->renderingContext->hasAttribute(
                ServerRequestInterface::class
            )
        ) {
            throw new \RuntimeException(
                'Required request not found in Fluid rendering context.',
                1788750007
            );
        }

        $request = $this->renderingContext->getAttribute(
            ServerRequestInterface::class
        );

        if (!$request instanceof ServerRequestInterface) {
            throw new \RuntimeException(
                'Invalid request in Fluid rendering context.',
                1788750008
            );
        }

        return $request;
    }
}
