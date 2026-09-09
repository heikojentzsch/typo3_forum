<?php

namespace Mittwald\Typo3Forum\Controller;

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

use Mittwald\Typo3Forum\Domain\Exception\Authentication\NoAccessException;
use Mittwald\Typo3Forum\Domain\Exception\InvalidOperationException;
use Mittwald\Typo3Forum\Domain\Factory\Forum\PostFactory;
use Mittwald\Typo3Forum\Domain\Factory\Forum\TopicFactory;
use Mittwald\Typo3Forum\Domain\Model\Forum\Forum;
use Mittwald\Typo3Forum\Domain\Model\Forum\Post;
use Mittwald\Typo3Forum\Domain\Model\Forum\Topic;
use Mittwald\Typo3Forum\Domain\Repository\Forum\ForumRepository;
use Mittwald\Typo3Forum\Domain\Repository\Forum\PostRepository;
use Mittwald\Typo3Forum\Domain\Repository\Forum\TagRepository;
use Mittwald\Typo3Forum\Domain\Repository\Forum\TopicRepository;
use Mittwald\Typo3Forum\Domain\Validator\Forum\AttachmentPlainValidator;
use Mittwald\Typo3Forum\Domain\Validator\Forum\PostValidator;
use Mittwald\Typo3Forum\Service\AttachmentService;
use Mittwald\Typo3Forum\Service\TagService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\PageTitle\RecordTitleProvider;
use TYPO3\CMS\Extbase\Attribute\IgnoreValidation;
use TYPO3\CMS\Extbase\Attribute\Validate;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

class TopicController extends AbstractController
{
    protected AttachmentService $attachmentService;
    protected ForumRepository $forumRepository;
    protected PostFactory $postFactory;
    protected PostRepository $postRepository;
    protected TagRepository $tagRepository;
    protected TagService $tagService;
    protected TopicFactory $topicFactory;
    protected TopicRepository $topicRepository;
    protected PersistenceManager $persistenceManager;
    protected RecordTitleProvider $recordTitleProvider;

    public function __construct(
        AttachmentService $attachmentService,
        ForumRepository $forumRepository,
        PostFactory $postFactory,
        PostRepository $postRepository,
        TagRepository $tagRepository,
        TagService $tagService,
        TopicFactory $topicFactory,
        TopicRepository $topicRepository,
        PersistenceManager $persistenceManager,
        RecordTitleProvider $recordTitleProvider
    ) {
        $this->attachmentService = $attachmentService;
        $this->forumRepository = $forumRepository;
        $this->postFactory = $postFactory;
        $this->postRepository = $postRepository;
        $this->tagRepository = $tagRepository;
        $this->tagService = $tagService;
        $this->topicFactory = $topicFactory;
        $this->topicRepository = $topicRepository;
        $this->persistenceManager = $persistenceManager;
        $this->recordTitleProvider = $recordTitleProvider;
    }

    /**
     * Listing Action.
     */
    public function listAction(int $page = 1): ResponseInterface
    {
        $showPaginate = false;

        switch ($this->settings['listTopics']) {
            case '2':
                $dataset = $this->topicRepository->findQuestions(
                    $this->settings['maxItems'] ?? null,
                    true
                );
                $showPaginate = true;
                break;

            case '3':
                $dataset = $this->topicRepository->findQuestions(
                    $this->settings['maxItems'] ?? null,
                    false
                );
                $showPaginate = true;
                break;

            case '4':
                $dataset = $this->topicRepository->findPopularTopics(
                    (int)($this->settings['popularTopicTimeDiff']),
                    $this->settings['maxItems'] ?? null
                );
                break;

            default:
                $dataset = $this->topicRepository->findLatest(
                    null,
                    $this->settings['maxItems'] ?? null
                );
                $showPaginate = true;
                break;
        }

        $this->view->assign('showPaginate', $showPaginate);
        $this->view->assign('topics', $dataset);
        $this->view->assign('page', $page);

        return $this->htmlResponse();
    }

    /**
     * Show action. Displays a single topic and all posts contained in this topic.
     */
    public function showAction(Topic $topic, ?Post $quote = null, int $page = 1): ResponseInterface
    {
        $posts = $this->postRepository->findForTopic($topic);

        if ($quote !== null) {
            $this->view->assign(
                'quote',
                $this->postFactory->createPostWithQuote($quote)
            );
        }

        $this->recordTitleProvider->setTitle($topic->getTitle());


        $this->authenticationService->assertReadAuthorization($topic);
        $this->markTopicRead($topic);

        $this->view->assignMultiple([
            'posts' => $posts,
            'topic' => $topic,
            'user' => $this->getCurrentUser(),
            'page' => $page,
        ]);

        return $this->htmlResponse();
    }

    /**
     * New action. Displays a form for creating a new topic.
     */
    public function newAction(
        Forum $forum,
        #[IgnoreValidation]
        ?Post $post = null,
        string $subject = ''
    ): ResponseInterface {
        $this->authenticationService->assertNewTopicAuthorization($forum);

        $this->view->assignMultiple([
            'currentUser' => $this->frontendUserRepository->findCurrent(),
            'forum' => $forum,
            'post' => $post,
            'subject' => $subject,
            'availableTags' => $this->tagRepository->findAll(),
        ]);

        return $this->htmlResponse();
    }

    /**
     * Creates a new topic.
     * @phpstan-param list<\Psr\Http\Message\UploadedFileInterface> $newAttachments
     * @phpstan-param list<int|string> $tags
     */
    public function createAction(
        Forum $forum,
        #[Validate(validator: PostValidator::class)]
        Post $post,
        #[Validate(validator: 'NotEmpty')]
        string $subject,
        array $tags = [],
        #[Validate(validator: AttachmentPlainValidator::class)]
        array $newAttachments = [],
        bool $question = false,
        bool $subscribe = false
    ): ResponseInterface {
        $this->authenticationService->assertNewTopicAuthorization($forum);

        $this->postFactory->assignUserToPost($post);

        if (count($newAttachments) > 0) {
            $attachments = $this->attachmentService->initAttachments($newAttachments);
            $post->setAttachments($attachments);
        }

        $tags = $this->tagService->hydrateTags($tags);

        $topic = $this->topicFactory->createTopic(
            $forum,
            $post,
            $subject,
            $question,
            $tags,
            $subscribe
        );

        $this->persistenceManager->persistAll();

        $this->eventDispatcher->dispatch($topic);
        $this->clearCacheForCurrentPage();

        if ($this->settings['purgeCache']) {
            $uriBuilder = $this->uriBuilder;
            $uri = $uriBuilder
                ->setTargetPageUid($this->settings['pids']['Forum'])
                ->setArguments([
                    'tx_typo3forum_forum[forum]' => $forum->getUid(),
                    'tx_typo3forum_forum[controller]' => 'Forum',
                    'tx_typo3forum_forum[action]' => 'show',
                ])
                ->build();

            $this->purgeUrl('http://' . $_SERVER['HTTP_HOST'] . '/' . $uri);
        }

        $uri = $this->uriBuilder->uriFor(
            'show',
            ['topic' => $topic],
            'Topic'
        );

        return $this->responseFactory->createResponse(307)
            ->withHeader('Location', $uri);
    }

    /**
     * Sets a post as solution.
     *
     * @throws NoAccessException
     * @throws InvalidOperationException
     */
    public function solutionAction(Post $post): ResponseInterface
    {
        if (!$post->getTopic()->checkSolutionAccess($this->getCurrentUser())) {
            throw new NoAccessException(
                'Not allowed to set solution by current user.'
            );
        }

        if ($post->isFirstPost()) {
            throw new InvalidOperationException(
                'The first post of a topic cannot be its solution.'
            );
        }

        $this->topicFactory->setPostAsSolution(
            $post->getTopic(),
            $post
        );

        $this->clearCacheForCurrentPage();

        $uri = $this->uriBuilder->uriFor(
            'show',
            ['topic' => $post->getTopic()],
            'Topic'
        );

        return $this->responseFactory->createResponse(307)
            ->withHeader('Location', $uri);
    }

    /**
     * Removes the topic's solution.
     *
     * @throws NoAccessException
     * @throws InvalidOperationException
     */
    public function removeSolutionAction(Topic $topic): ResponseInterface
    {
        if (!$topic->checkSolutionAccess($this->getCurrentUser())) {
            throw new NoAccessException(
                'Not allowed to remove solution by current user.'
            );
        }

        $this->topicFactory->setPostAsSolution($topic, null);

        $this->clearCacheForCurrentPage();

        $uri = $this->uriBuilder->uriFor(
            'show',
            ['topic' => $topic],
            'Topic'
        );

        return $this->responseFactory->createResponse(307)
            ->withHeader('Location', $uri);
    }

    /**
     * Marks a topic as read by the current user.
     */
    protected function markTopicRead(Topic $topic): void
    {
        $currentUser = $this->getCurrentUser();

        if ($currentUser === null || $currentUser->isAnonymous()) {
            return;
        }

        if (false === $topic->hasBeenReadByUser($currentUser)) {
            $currentUser->addReadObject($topic);
            $this->frontendUserRepository->update($currentUser);
        }
    }
}
