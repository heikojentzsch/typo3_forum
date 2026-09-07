<?php
namespace Mittwald\Typo3Forum\Controller;

use Mittwald\Typo3Forum\Domain\Exception\Authentication\NotLoggedInException;

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

use Mittwald\Typo3Forum\Domain\Model\Forum\Forum;
use Mittwald\Typo3Forum\Domain\Model\Forum\RootForum;
use Mittwald\Typo3Forum\Domain\Model\Forum\Topic;
use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Repository\Forum\ForumRepository;
use Mittwald\Typo3Forum\Domain\Repository\Forum\TopicRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

class ForumController extends AbstractController
{
    protected ForumRepository $forumRepository;
    protected TopicRepository $topicRepository;
    protected RootForum $rootForum;

    public function __construct(
        ForumRepository $forumRepository,
        TopicRepository $topicRepository,
        RootForum $rootForum
    ){
        $this->forumRepository = $forumRepository;
        $this->topicRepository = $topicRepository;
        $this->rootForum = $rootForum;
    }

    /**
     * Index action. Displays the first two levels of the forum tree.
     */
    public function indexAction(int $page = 1):ResponseInterface
    {
        if (($forum = $this->forumRepository->findFirstRootForum())) {
            return (new ForwardResponse('show'))->withArguments(['forum' => $forum])->withControllerName('Forum');
        }
        $this->view->assign('page', $page);
        return $this->htmlResponse();
    }

    /**
     * Show action. Displays a single forum, all subforums of this forum and the
     * topics contained in this forum.
     *
     *
     */
    public function showAction(Forum $forum, int $page = 1): ResponseInterface
    {
        $topics = $this->topicRepository->findForIndex($forum);
        $this->authenticationService->assertReadAuthorization($forum);
        $this->view->assignMultiple([
            'forum' => $forum,
            'topics' => $topics,
            'page' => $page,
        ]);
        return $this->htmlResponse();
    }

    /**
    * Mark a whole forum as read
    *
    * @throws NotLoggedInException
    */
    public function markReadAction(Forum $forum): ResponseInterface
    {
        $user = $this->getCurrentUser();
        if (!$user instanceof FrontendUser || $user->isAnonymous()) {
            throw new NotLoggedInException('You need to be logged in.', 1288084981);
        }
        $forumsToMarkAsRead = new ObjectStorage();
        $forumsToMarkAsRead->attach($forum);
        foreach ($forum->getChildren() as $child) {
            $forumsToMarkAsRead->attach($child);
        }

        foreach ($forumsToMarkAsRead as $checkForum) {
            /** @var Forum $checkForum */
            foreach ($checkForum->getTopics() as $topic) {
                /** @var Topic $topic */
                $topic->addReader($user);
            }
            $checkForum->addReader($user);
            $this->forumRepository->update($checkForum);
        }

        $uri = $this->uriBuilder->uriFor('show', ['forum' => $forum], 'Forum');
        return $this->responseFactory->createResponse(307)
            ->withHeader('Location', $uri);
    }
}
