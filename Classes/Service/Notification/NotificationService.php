<?php
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

namespace Mittwald\Typo3Forum\Service\Notification;

use Mittwald\Typo3Forum\Configuration\ConfigurationBuilder;
use Mittwald\Typo3Forum\Configuration\NotificationConfigurationResolver;
use Mittwald\Typo3Forum\Configuration\NotificationEmailOptions;
use Mittwald\Typo3Forum\Domain\Model\Forum\Forum;
use Mittwald\Typo3Forum\Domain\Model\Forum\Post;
use Mittwald\Typo3Forum\Domain\Model\Forum\Topic;
use Mittwald\Typo3Forum\Domain\Model\NotifiableInterface;
use Mittwald\Typo3Forum\Domain\Model\SubscribeableInterface;
use Mittwald\Typo3Forum\Service\AbstractService;
use Mittwald\Typo3Forum\Service\Mailing\HTMLMailingService;
use Mittwald\Typo3Forum\Utility\Localization;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\LazyLoadingProxy;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * Service class for notifications. This service notifies subscribers of
 * forums and topic about new posts within the subscribed objects.
 */
class NotificationService extends AbstractService implements NotificationServiceInterface
{
    protected HTMLMailingService $htmlMailingService;
    protected ContentObjectRenderer $contentObjectRenderer;
    protected ConfigurationBuilder $configurationBuilder;
    protected NotificationConfigurationResolver $notificationConfigurationResolver;
    protected NotificationEmailRenderer $notificationEmailRenderer;

    /** @var array<string, mixed> */
    protected array $settings;

    public function __construct(
        HTMLMailingService $htmlMailingService,
        ContentObjectRenderer $contentObjectRenderer,
        ConfigurationBuilder $configurationBuilder,
        NotificationConfigurationResolver $notificationConfigurationResolver,
        NotificationEmailRenderer $notificationEmailRenderer
    ) {
        $this->htmlMailingService = $htmlMailingService;
        $this->contentObjectRenderer = $contentObjectRenderer;
        $this->configurationBuilder = $configurationBuilder;
        $this->notificationConfigurationResolver = $notificationConfigurationResolver;
        $this->notificationEmailRenderer = $notificationEmailRenderer;
        $this->settings = $this->configurationBuilder->getSettings();
    }

    /** @return void */
    public function initializeObject()
    {
        $this->settings = $this->configurationBuilder->getSettings();
    }

    /**
     * Notifies subscribers of a subscribeable objects about a new notifiable object
     * within the subscribeable object, e.g. of a new post within a subscribed topic.
     *
     * @param SubscribeableInterface $subscriptionObject The subscribed object. This may for example be a forum or a topic.
     * @param NotifiableInterface $notificationObject The object that the subscriber is notified about. This may for example be a new post within an observed topic or forum or a new topic within an observed forum.
     * @return void
     */
    public function notifySubscribers(SubscribeableInterface $subscriptionObject, NotifiableInterface $notificationObject)
    {
        if ($subscriptionObject instanceof Forum && $notificationObject instanceof Topic) {
            $forum = $subscriptionObject;
            $topic = $notificationObject;
            $post = $topic->getFirstPost();
            if ($post instanceof Post) {
                $this->notifyForumSubscribers($forum, $topic, $post);
            }
        } elseif ($subscriptionObject instanceof Topic && $notificationObject instanceof Post) {
            $topic = $subscriptionObject;
            $forum = $topic->getForum();
            $post = $notificationObject;
            $this->notifyTopicSubscribers($forum, $topic, $post);
        }
    }

    /**
     * Notifies subscribers of a new post within a subscribed topic.
     */
    protected function notifyTopicSubscribers(Forum $forum, Topic $topic, Post $post): void
    {
        $options = $this->notificationConfigurationResolver->resolve($forum);
        $subject = $this->getSubject('Mail_Subscribe_NewPost_Subject', $forum, $options);
        $postAuthorUid = $post->getAuthor()->getUid();
        foreach ($topic->getSubscribers() as $subscriber) {
            if ($forum->checkReadAccess($subscriber) && $subscriber->getUid() !== $postAuthorUid) {
                $subscriberMessage = $this->getMessage(
                    'NewPost',
                    $subscriber->getUsername(),
                    $forum,
                    $topic,
                    $post,
                    $options->includeUnsubscribeLink ? $this->getTopicUnsubscribeLink($topic) : '',
                    $options
                );
                $this->htmlMailingService->sendMail($subscriber, $subject, $subscriberMessage);
            }
        }
    }

    /**
     * Notifies subscribers of a new topic within a subscribed forum.
     */
    protected function notifyForumSubscribers(Forum $forum, Topic $topic, Post $post): void
    {
        $contentForum = $forum;
        $options = $this->notificationConfigurationResolver->resolve($contentForum);
        $subject = $this->getSubject('Mail_Subscribe_NewTopic_Subject', $contentForum, $options);
        $postAuthorUid = $post->getAuthor()->getUid();

        $notifiedSubscribers = [];

        while ($forum) {
            foreach ($forum->getSubscribers() as $subscriber) {
                if (!isset($notifiedSubscribers[$subscriber->getUid()])) {
                    if ($subscriber->getUid() !== $postAuthorUid
                        && $contentForum->checkReadAccess($subscriber)
                        && ($forum === $contentForum || $forum->checkReadAccess($subscriber))
                    ) {
                        $subscriberMessage = $this->getMessage(
                            'NewTopic',
                            $subscriber->getUsername(),
                            $contentForum,
                            $topic,
                            $post,
                            $options->includeUnsubscribeLink ? $this->getForumUnsubscribeLink($forum) : '',
                            $options
                        );
                        $this->htmlMailingService->sendMail($subscriber, $subject, $subscriberMessage);
                        $notifiedSubscribers[$subscriber->getUid()] = true;
                    }
                }

            }

            $forum = $forum->getParent();
            if ($forum instanceof LazyLoadingProxy) {
                $forum = $forum->_loadRealInstance();
            }
        }
    }

    protected function getSubject(string $translationKey, Forum $contentForum, NotificationEmailOptions $options): string
    {
        $subject = $this->notificationEmailRenderer->sanitizeSubject($this->translate($translationKey));
        $forumName = $this->notificationEmailRenderer->sanitizeSubject($contentForum->getTitle());
        if ($options->includeForumNameInSubject && $forumName !== '') {
            $subject = '[' . $forumName . '] ' . $subject;
        }
        return $subject;
    }

    protected function getMessage(
        string $event,
        string $recipient,
        Forum $contentForum,
        Topic $topic,
        Post $post,
        string $unsubscribeLink,
        NotificationEmailOptions $options
    ): string {
        $forumName = $contentForum->getTitle();
        $topicName = $topic->getName();
        $plainMarkers = [
            '###RECIPIENT###' => $this->notificationEmailRenderer->escape($recipient),
            '###POST_AUTHOR###' => $this->notificationEmailRenderer->escape($post->getAuthorName()),
            '###FORUM_NAME###' => $this->notificationEmailRenderer->escape($forumName),
            '###TOPIC_NAME###' => $this->notificationEmailRenderer->escape($topicName),
            '###POST_TEXT###' => $options->includePostText
                ? $this->notificationEmailRenderer->escape($post->getText())
                : '',
            '###FORUM_TEAM###' => $this->notificationEmailRenderer->escape((string)($this->settings['mailing.']['sender.']['name'] ?? '')),
        ];
        $markers = $plainMarkers + [
            '###FORUM_LINK###' => $options->includeForumLink ? $this->getForumLink($contentForum) : $plainMarkers['###FORUM_NAME###'],
            '###TOPIC_LINK###' => $options->includeTopicLink ? $this->getTopicLink($topic) : $plainMarkers['###TOPIC_NAME###'],
            '###POST_LINK###' => $options->includeTopicLink ? $this->getPostLink($post) : $plainMarkers['###TOPIC_NAME###'],
            '###UNSUBSCRIBE_LINK###' => $options->includeUnsubscribeLink ? $unsubscribeLink : '',
        ];

        $fragments = [
            '###GREETING###' => $this->renderFragment('Mail_Subscribe_Greeting', $markers),
            '###EVENT_DESCRIPTION###' => $this->renderFragment('Mail_Subscribe_' . $event . '_Event', $markers),
            '###POST_TEXT_BLOCK###' => $options->includePostText
                ? $this->renderFragment('Mail_Subscribe_PostTextBlock', $markers)
                : '',
            '###FORUM_LINK_BLOCK###' => $options->includeForumLink
                ? $this->renderFragment('Mail_Subscribe_ForumLinkBlock', $markers)
                : '',
            '###TOPIC_LINK_BLOCK###' => $options->includeTopicLink
                ? $this->renderFragment('Mail_Subscribe_' . $event . '_TopicLinkBlock', $markers)
                : '',
            '###UNSUBSCRIBE_BLOCK###' => $options->includeUnsubscribeLink
                ? $this->renderFragment('Mail_Subscribe_' . $event . '_UnsubscribeBlock', $markers)
                : '',
            '###SIGNATURE###' => $this->renderFragment('Mail_Subscribe_Signature', $markers),
        ];

        $messageTemplate = $this->translate('Mail_Subscribe_' . $event . '_Body');
        if ($options->includePostText
            && !str_contains($messageTemplate, '###POST_TEXT###')
            && !str_contains($messageTemplate, '###POST_TEXT_BLOCK###')
        ) {
            $messageTemplate .= "\n\n###POST_TEXT_BLOCK###";
        }

        return $this->notificationEmailRenderer->render(
            $messageTemplate,
            $markers + $fragments,
            $options->includeUnsubscribeLink,
            $options->includePostText
        );
    }

    /** @param array<string, string> $markers */
    private function renderFragment(string $translationKey, array $markers): string
    {
        return $this->notificationEmailRenderer->renderFragment($this->translate($translationKey), $markers);
    }

    protected function translate(string $key): string
    {
        return (string)Localization::translate($key, $key);
    }

    protected function getForumLink(Forum $forum): string
    {
        $arguments = [
            'tx_typo3forum_forum[controller]' => 'Forum',
            'tx_typo3forum_forum[action]' => 'show',
            'tx_typo3forum_forum[forum]' => $forum->getUid(),
        ];

        $forumLink = $this->buildAbsoluteLink($arguments);

        return $this->notificationEmailRenderer->link($forumLink, $forum->getTitle());
    }

    /** @return string */
    protected function getTopicLink(Topic $topic)
    {
        $arguments = [
            'tx_typo3forum_forum[controller]' => 'Topic',
            'tx_typo3forum_forum[action]' => 'show',
            'tx_typo3forum_forum[topic]' => $topic->getUid(),
        ];

        $topicLink = $this->buildAbsoluteLink($arguments);

        return $this->notificationEmailRenderer->link($topicLink, $topic->getTitle());
    }

    protected function getPostLink(Post $post): string
    {
        $arguments = [
            'tx_typo3forum_forum[controller]' => 'Post',
            'tx_typo3forum_forum[action]' => 'show',
            'tx_typo3forum_forum[post]' => $post->getUid(),
        ];

        $postLink = $this->buildAbsoluteLink($arguments);

        return $this->notificationEmailRenderer->link($postLink, $post->getTopic()?->getSubject() ?? '');
    }

    protected function getForumUnsubscribeLink(Forum $forum): string
    {
        $unSubscribeLink = $this->buildAbsoluteLink([
            'tx_typo3forum_forum[controller]' => 'User',
            'tx_typo3forum_forum[action]' => 'subscribe',
            'tx_typo3forum_forum[forum]' => $forum->getUid(),
            'tx_typo3forum_forum[unsubscribe]' => 1,
        ]);

        return $this->notificationEmailRenderer->link($unSubscribeLink, $this->translate('Button_Unsubscribe'));
    }

    protected function getTopicUnsubscribeLink(Topic $topic): string
    {
        $unSubscribeLink = $this->buildAbsoluteLink([
            'tx_typo3forum_forum[controller]' => 'User',
            'tx_typo3forum_forum[action]' => 'subscribe',
            'tx_typo3forum_forum[topic]' => $topic->getUid(),
            'tx_typo3forum_forum[unsubscribe]' => 1,
        ]);

        return $this->notificationEmailRenderer->link($unSubscribeLink, $this->translate('Button_Unsubscribe'));
    }

    /** @param array<string, int|string> $arguments */
    protected function buildAbsoluteLink(array $arguments): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            throw new \RuntimeException('A frontend request is required to build notification links.', 1789000001);
        }

        $this->contentObjectRenderer->setRequest($request);
        return $this->contentObjectRenderer->createUrl([
            'parameter' => (int)$this->settings['pids.']['Forum'],
            'queryParameters' => $arguments,
            'forceAbsoluteUrl' => true,
            'linkAccessRestrictedPages' => true,
        ]);
    }
}
