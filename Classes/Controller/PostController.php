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

use Mittwald\Typo3Forum\Domain\Factory\Forum\PostFactory;
use Mittwald\Typo3Forum\Domain\Factory\Forum\TopicFactory;
use Mittwald\Typo3Forum\Domain\Model\Forum\Attachment;
use Mittwald\Typo3Forum\Domain\Model\Forum\Post;
use Mittwald\Typo3Forum\Domain\Model\Forum\Tag;
use Mittwald\Typo3Forum\Domain\Model\Forum\Topic;
use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Repository\Forum\AttachmentRepository;
use Mittwald\Typo3Forum\Domain\Repository\Forum\ForumRepository;
use Mittwald\Typo3Forum\Domain\Repository\Forum\PostRepository;
use Mittwald\Typo3Forum\Domain\Repository\Forum\TagRepository;
use Mittwald\Typo3Forum\Domain\Repository\Forum\TopicRepository;
use Mittwald\Typo3Forum\Domain\Validator\Forum\AttachmentPlainValidator;
use Mittwald\Typo3Forum\Domain\Validator\Forum\PostValidator;
use Mittwald\Typo3Forum\Service\AttachmentService;
use Mittwald\Typo3Forum\Service\TagService;
use Mittwald\Typo3Forum\Utility\Localization;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Extbase\Attribute\IgnoreValidation;
use TYPO3\CMS\Extbase\Attribute\Validate;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

class PostController extends AbstractController
{
    protected AttachmentRepository $attachmentRepository;
    protected AttachmentService $attachmentService;
    protected ForumRepository $forumRepository;
    protected PostRepository $postRepository;
    protected PostFactory $postFactory;
    protected TopicRepository $topicRepository;
    protected TopicFactory $topicFactory;
    protected PersistenceManager $persistenceManager;
    protected TagRepository $tagRepository;
    protected TagService $tagService;

    public function __construct(
        AttachmentRepository $attachmentRepository,
        AttachmentService $attachmentService,
        ForumRepository $forumRepository,
        PostRepository $postRepository,
        PostFactory $postFactory,
        TopicRepository $topicRepository,
        TopicFactory $topicFactory,
        PersistenceManager $persistenceManager,
        TagRepository $tagRepository,
        TagService $tagService
    ) {
        $this->attachmentRepository = $attachmentRepository;
        $this->attachmentService = $attachmentService;
        $this->forumRepository = $forumRepository;
        $this->postRepository = $postRepository;
        $this->postFactory = $postFactory;
        $this->topicRepository = $topicRepository;
        $this->topicFactory = $topicFactory;
        $this->persistenceManager = $persistenceManager;
        $this->tagRepository = $tagRepository;
        $this->tagService = $tagService;
    }

    public function listAction(int $page = 1): ResponseInterface
    {
        $showPaginate = false;

        switch ($this->settings['listPosts'] ?? '1') {
            case '2':
                $posts = $this->postRepository->findByFilter(
                    $this->settings['maxItems'] ?? null,
                    ['crdate' => 'DESC']
                );
                break;

            default:
                $posts = $this->postRepository->findByFilter(
                    $this->settings['maxItems'] ?? null,
                    ['crdate' => 'DESC']
                );
                $showPaginate = true;
                break;
        }

        $this->view->assign('showPaginate', $showPaginate);
        $this->view->assign('posts', $posts);
        $this->view->assign('page', $page);

        return $this->htmlResponse();
    }

    public function supportAction(Post $post): ResponseInterface
    {
        /** @var FrontendUser $currentUser */
        $currentUser = $this->authenticationService->getUser();

        if (
            $currentUser === null
            || $currentUser->isAnonymous()
            || $post->hasBeenSupportedByUser($currentUser)
        ) {
            return (new ForwardResponse('show'))
                ->withControllerName('Post')
                ->withArguments(['post' => $post]);
        }

        $post->addSupporter($currentUser);
        $this->postRepository->update($post);

        $post->getAuthor()->setHelpfulCount($post->getAuthor()->getHelpfulCount() + 1);
        $post->getAuthor()->increasePoints((int)$this->settings['rankScore']['gotHelpful']);
        $this->frontendUserRepository->update($post->getAuthor());

        $currentUser->increasePoints((int)$this->settings['rankScore']['markHelpful']);
        $this->frontendUserRepository->update($currentUser);

        $this->clearCacheForCurrentPage();

        return (new ForwardResponse('show'))
            ->withControllerName('Post')
            ->withArguments(['post' => $post]);
    }

    public function unsupportAction(Post $post): ResponseInterface
    {
        /** @var FrontendUser $currentUser */
        $currentUser = $this->authenticationService->getUser();

        if (!$post->hasBeenSupportedByUser($currentUser)) {
            return (new ForwardResponse('show'))
                ->withControllerName('Post')
                ->withArguments(['post' => $post]);
        }

        $post->removeSupporter($currentUser);
        $this->postRepository->update($post);

        $post->getAuthor()->setHelpfulCount($post->getAuthor()->getHelpfulCount() - 1);
        $post->getAuthor()->decreasePoints((int)$this->settings['rankScore']['gotHelpful']);
        $this->frontendUserRepository->update($post->getAuthor());

        $currentUser->decreasePoints((int)$this->settings['rankScore']['markHelpful']);
        $this->frontendUserRepository->update($currentUser);

        $this->clearCacheForCurrentPage();

        return (new ForwardResponse('show'))
            ->withControllerName('Post')
            ->withArguments(['post' => $post]);
    }

    public function showAction(Post $post, ?Post $quote = null): ResponseInterface
    {
        $this->authenticationService->assertReadAuthorization($post);

        $topic = $post->getTopic();
        $pageWithPost = 1;

        if ($topic->getPageCount() > 1) {
            $postOffsetInTopic = array_search(
                $post,
                $topic->getPosts()->toArray(),
                true
            );

            $pageWithPost = intdiv(
                $postOffsetInTopic,
                (int)($topic->getSettings()['pagebrowser']['topicShow']['itemsPerPage'] ?? 10)
            ) + 1;

            $pageWithPost = max(1, $pageWithPost);
        }

        $sectionWrap = $this->settings['topicController']['show']['postIdWrap'] ?? 'post-|';

        $targetUrl = $this->uriBuilder
            ->reset()
            ->setSection(str_replace('|', (string)$post->getUid(), $sectionWrap))
            ->uriFor(
                'show',
                [
                    'topic' => $post->getTopic(),
                    'quote' => $quote,
                    'page' => $pageWithPost,
                ],
                'Topic',
            );

        return $this->redirectToUri($targetUrl);
    }

    /**
     * Displays the form for creating a new post.
     */
    public function newAction(
        Topic $topic,
        #[IgnoreValidation]
        ?Post $post = null,
        ?Post $quote = null
    ): ResponseInterface {
        $this->authenticationService->assertNewPostAuthorization($topic);

        if ($post === null) {
            $post = ($quote !== null)
                ? $this->postFactory->createPostWithQuote($quote)
                : $this->postFactory->createEmptyPost();
        } else {
            $this->authenticationService->assertEditPostAuthorization($post);
        }

        $this->view->assignMultiple([
            'topic' => $topic,
            'post' => $post,
            'currentUser' => $this->frontendUserRepository->findCurrent(),
        ]);

        return $this->htmlResponse();
    }

    /**
     * Creates a new post.
     * @phpstan-param list<\Psr\Http\Message\UploadedFileInterface> $newAttachments
     */
    public function createAction(
        Topic $topic,
        #[Validate(validator: PostValidator::class)]
        Post $post,
        #[Validate(validator: AttachmentPlainValidator::class)]
        array $newAttachments = []
    ): ForwardResponse {
        $this->authenticationService->assertNewPostAuthorization($topic);

        $this->postFactory->assignUserToPost($post);

        if (!empty($newAttachments)) {
            $attachments = $this->attachmentService->initAttachments($newAttachments);
            $post->setAttachments($attachments);
        }

        $topic->addPost($post);
        $this->topicRepository->update($topic);

        $this->persistenceManager->persistAll();

        $this->eventDispatcher->dispatch($post);

        $this->getFlashMessageQueue()->enqueue(
            new FlashMessage(Localization::translate('Post_Create_Success'))
        );

        $this->clearCacheForCurrentPage();

        return (new ForwardResponse('show'))
            ->withControllerName('Post')
            ->withArguments(['post' => $post]);
    }

    /**
     * Displays a form for editing a post.
     */
    public function editAction(
        #[IgnoreValidation]
        Post $post
    ): ResponseInterface {
        if (
            $post->getAuthor() != $this->authenticationService->getUser()
            || $post->getTopic()->getLastPost()->getAuthor() != $post->getAuthor()
        ) {
            $this->authenticationService->assertModerationAuthorization(
                $post->getTopic()->getForum()
            );
        }

        $this->view->assign('post', $post);
        $this->view->assign('availableTags', $this->tagRepository->findAll());

        return $this->htmlResponse();
    }

    /**
     * Updates a post and its containing topic (if this is a first post).
     * @phpstan-param list<int|string> $keepAttachments
     * @phpstan-param list<\Psr\Http\Message\UploadedFileInterface> $newAttachments
     * @phpstan-param list<int|string> $tags
     */
    public function updateAction(
        Post $post,
        array $newAttachments = [],
        array $keepAttachments = [],
        array $tags = []
    ): ResponseInterface {
        if (
            $post->getAuthor()->getUid() != $this->getCurrentUser()->getUid()
            || $post->getTopic()->getLastPost()->getAuthor() != $post->getAuthor()
        ) {
            $this->authenticationService->assertModerationAuthorization(
                $post->getTopic()->getForum()
            );
        }

        $keepAttachments = array_map('intval', $keepAttachments);

        foreach ($post->getAttachments()->toArray() as $existingAttachment) {
            if (!in_array($existingAttachment->getUid(), $keepAttachments)) {
                $post->removeAttachment($existingAttachment);
            }
        }

        if (count($newAttachments) > 0) {
            $newAttachments = $this->attachmentService->initAttachments($newAttachments);

            foreach ($newAttachments as $newAttachment) {
                $post->addAttachments($newAttachment);
            }
        }

        $topic = $post->getTopic();

        if ($post->isFirstPost()) {
            $tags = $this->tagService->hydrateTags($tags);

            $existingTags = $topic->getTags();

            foreach ($existingTags->toArray() as $existingTag) {
                /** @var Tag $existingTag */
                $existingTag->decreaseTopicCount();
                $existingTags->detach($existingTag);
                $this->tagRepository->update($existingTag);
            }

            foreach ($tags as $tag) {
                $tag->increaseTopicCount();
                $existingTags->attach($tag);
                $this->tagRepository->update($tag);
            }
        }

        if (
            $post->isFirstPost()
            && !$topic->isQuestion()
            && $topic->isSolved()
        ) {
            $this->topicFactory->setPostAsSolution($topic, null);
        }

        $this->postRepository->update($post);

        $this->eventDispatcher->dispatch($post);

        $this->getFlashMessageQueue()->enqueue(
            new FlashMessage(Localization::translate('Post_Update_Success'))
        );

        $this->clearCacheForCurrentPage();

        return (new ForwardResponse('show'))
            ->withControllerName('Post')
            ->withArguments(['post' => $post]);
    }

    /**
     * Displays a confirmation screen in which the user is prompted if a post
     * should really be deleted.
     */
    public function confirmDeleteAction(Post $post): ResponseInterface
    {
        $this->authenticationService->assertDeletePostAuthorization($post);

        $this->view->assign('post', $post);
        return $this->htmlResponse();
    }

    /**
     * Deletes a post.
     */
    public function deleteAction(Post $post): ResponseInterface
    {
        $this->authenticationService->assertDeletePostAuthorization($post);

        $postCount = $post->getTopic()->getPostCount();
        $this->postFactory->deletePost($post);

        $this->getFlashMessageQueue()->enqueue(
            new FlashMessage(Localization::translate('Post_Delete_Success'))
        );

        $this->eventDispatcher->dispatch($post);

        $this->clearCacheForCurrentPage();

        if ($postCount > 1) {
            $uri = $this->uriBuilder->uriFor(
                'show',
                ['topic' => $post->getTopic()],
                'Topic'
            );

            return $this->responseFactory->createResponse(307)
                ->withHeader('Location', $uri);
        }

        $uri = $this->uriBuilder->uriFor(
            'show',
            ['forum' => $post->getForum()],
            'Forum'
        );

        return $this->responseFactory->createResponse(307)
            ->withHeader('Location', $uri);
    }

    /**
     * Downloads an attachment and increase the download counter
     */
    public function downloadAttachmentAction(Attachment $attachment): ResponseInterface
    {
        $attachment->increaseDownloadCount();
        $this->attachmentRepository->update($attachment);
        $this->persistenceManager->persistAll();

        $file = $attachment->getFileReference()->getOriginalResource();
        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', $file->getMimeType() ?: 'application/download')
            ->withHeader('Content-Disposition', 'attachment; filename="' . addcslashes($attachment->getName(), '"\\') . '"')
            ->withBody($this->streamFactory->createStream($file->getContents()));
    }
}
