<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Configuration\ConfigurationBuilder;
use Mittwald\Typo3Forum\Configuration\NotificationConfigurationResolver;
use Mittwald\Typo3Forum\Configuration\NotificationEmailConfigurationInterface;
use Mittwald\Typo3Forum\Configuration\NotificationEmailOptions;
use Mittwald\Typo3Forum\Configuration\NotificationOverride;
use Mittwald\Typo3Forum\Domain\Model\Forum\Forum;
use Mittwald\Typo3Forum\Domain\Model\Forum\Post;
use Mittwald\Typo3Forum\Domain\Model\Forum\Topic;
use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Service\Mailing\HTMLMailingService;
use Mittwald\Typo3Forum\Service\Notification\NotificationEmailRenderer;
use Mittwald\Typo3Forum\Service\Notification\NotificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

final class NotificationServiceTest extends TestCase
{
    #[DataProvider('optionMatrix')]
    public function testAllOptionCombinationsForBothNotificationTypes(
        string $event,
        NotificationEmailOptions $options
    ): void {
        $service = $this->service($options);
        [$forum, $topic, $post] = $this->contentObjects('General & Members', 'Topic "One"', "Paragraph one\n\nParagraph two & <safe>");

        $message = $service->compose($event, 'Recipient', $forum, $topic, $post, '<a href="https://example.test/unsubscribe/99">unsubscribe</a>');
        $subject = $service->subject('Mail_Subscribe_' . $event . '_Subject', $forum);

        self::assertSame($options->includeForumNameInSubject, str_contains($subject, '[General & Members]'));
        self::assertSame($options->includePostText, str_contains($message, 'Paragraph one'));
        self::assertSame($options->includeForumLink, str_contains($message, 'href="https://example.test/forum/7"'));
        self::assertSame($options->includeTopicLink, str_contains($message, 'href="https://example.test/' . ($event === 'NewPost' ? 'post/77' : 'topic/14548') . '"'));
        self::assertSame($options->includeUnsubscribeLink, str_contains($message, 'href="https://example.test/unsubscribe/99"'));
        self::assertStringNotContainsString('href=""', $message);
        self::assertDoesNotMatchRegularExpression('/###(?:GREETING|EVENT_DESCRIPTION|POST_TEXT_BLOCK|FORUM_LINK_BLOCK|TOPIC_LINK_BLOCK|UNSUBSCRIBE_BLOCK|SIGNATURE)###/', $message);
        if (!$options->includeUnsubscribeLink) {
            self::assertStringNotContainsString('unsubscribes only', $message);
        }
    }

    public static function optionMatrix(): iterable
    {
        foreach (['NewTopic', 'NewPost'] as $event) {
            for ($mask = 0; $mask < 32; $mask++) {
                yield $event . '-' . $mask => [
                    $event,
                    new NotificationEmailOptions(
                        (bool)($mask & 1),
                        (bool)($mask & 2),
                        (bool)($mask & 4),
                        (bool)($mask & 8),
                        (bool)($mask & 16)
                    ),
                ];
            }
        }
    }

    public function testFullTextUsesTriggeringReplyInsteadOfTopicsLastPost(): void
    {
        $options = new NotificationEmailOptions(false, true, false, false, false);
        $subscriber = $this->user(10, 'reader');
        $author = $this->user(20, 'author');
        [$forum, $topic, $triggeringPost] = $this->contentObjects('Forum', 'Topic', 'TRIGGERING POST');
        $triggeringPost->method('getAuthor')->willReturn($author);
        $triggeringPost->method('getAuthorName')->willReturn('author');
        $unrelatedPost = $this->createStub(Post::class);
        $unrelatedPost->method('getText')->willReturn('UNRELATED LAST POST');
        $topic->method('getLastPost')->willReturn($unrelatedPost);
        $topic->method('getSubscribers')->willReturn($this->storage($subscriber));
        $forum->method('checkReadAccess')->willReturn(true);
        $messages = [];
        $service = $this->service($options, $messages);

        $service->notifySubscribers($topic, $triggeringPost);

        self::assertCount(1, $messages);
        self::assertStringContainsString('TRIGGERING POST', $messages[0]['body']);
        self::assertStringNotContainsString('UNRELATED LAST POST', $messages[0]['body']);
    }

    public function testNewTopicUsesInitialPostAndKeepsContentForumSeparateFromParentSubscription(): void
    {
        $options = new NotificationEmailOptions(false, false, false, false, true);
        $parentSubscriber = $this->user(10, 'parent-reader');
        $author = $this->user(20, 'author');
        [$contentForum, $topic, $initialPost] = $this->contentObjects('Content forum', 'Topic 14548', 'INITIAL POST');
        $parentForum = $this->createStub(Forum::class);
        $contentForum->method('getUid')->willReturn(7);
        $parentForum->method('getUid')->willReturn(1);
        $contentForum->method('getParent')->willReturn($parentForum);
        $parentForum->method('getParent')->willReturn(null);
        $contentForum->method('getNotificationIncludeForumNameInSubject')->willReturn(NotificationOverride::ENABLED);
        $contentForum->method('getNotificationIncludePostText')->willReturn(NotificationOverride::ENABLED);
        $contentForum->method('getNotificationIncludeTopicLink')->willReturn(NotificationOverride::ENABLED);
        $parentForum->method('getNotificationIncludePostText')->willReturn(NotificationOverride::DISABLED);
        $parentForum->method('getNotificationIncludeForumLink')->willReturn(NotificationOverride::ENABLED);
        $contentForum->method('getSubscribers')->willReturn(new ObjectStorage());
        $parentForum->method('getSubscribers')->willReturn($this->storage($parentSubscriber));
        $contentForum->method('checkReadAccess')->willReturn(true);
        $parentForum->method('checkReadAccess')->willReturn(true);
        $initialPost->method('getAuthor')->willReturn($author);
        $initialPost->method('getAuthorName')->willReturn('author');
        $topic->method('getFirstPost')->willReturn($initialPost);
        $lastPost = $this->createStub(Post::class);
        $lastPost->method('getText')->willReturn('LATER LAST POST');
        $topic->method('getLastPost')->willReturn($lastPost);
        $messages = [];
        $service = $this->service($options, $messages);

        $service->notifySubscribers($contentForum, $topic);

        self::assertCount(1, $messages);
        self::assertSame('[Content forum] New topic', $messages[0]['subject']);
        self::assertStringContainsString('INITIAL POST', $messages[0]['body']);
        self::assertStringNotContainsString('LATER LAST POST', $messages[0]['body']);
        self::assertStringContainsString('https://example.test/forum/7', $messages[0]['body']);
        self::assertStringContainsString('https://example.test/topic/14548', $messages[0]['body']);
        self::assertStringContainsString('https://example.test/unsubscribe/forum/1', $messages[0]['body']);
    }

    public function testPostTextIsNotReadWhenItIsDisabled(): void
    {
        $service = $this->service(new NotificationEmailOptions(false, false, false, false, false));
        [$forum, $topic] = $this->contentObjects('Forum', 'Topic', 'unused');
        $post = $this->createMock(Post::class);
        $post->method('getAuthorName')->willReturn('author');
        $post->expects(self::never())->method('getText');

        $service->compose('NewPost', 'reader', $forum, $topic, $post, '');
    }

    public function testRecipientsReceiveIndependentPersonalizedMessages(): void
    {
        $options = new NotificationEmailOptions(false, false, false, false, false);
        $alice = $this->user(10, 'Alice');
        $bob = $this->user(11, 'Bob');
        $author = $this->user(20, 'author');
        [$forum, $topic, $post] = $this->contentObjects('Forum', 'Topic', 'hidden');
        $post->method('getAuthor')->willReturn($author);
        $topic->method('getSubscribers')->willReturn($this->storage($alice, $bob));
        $forum->method('checkReadAccess')->willReturn(true);
        $messages = [];
        $service = $this->service($options, $messages);

        $service->notifySubscribers($topic, $post);

        self::assertCount(2, $messages);
        self::assertStringContainsString('Hello Alice', $messages[0]['body']);
        self::assertStringNotContainsString('Bob', $messages[0]['body']);
        self::assertStringContainsString('Hello Bob', $messages[1]['body']);
        self::assertStringNotContainsString('Alice', $messages[1]['body']);
        self::assertCount(2, $topic->getSubscribers());
    }

    public function testFullTextEscapesMarkupAndPreservesEmptyAndZeroValues(): void
    {
        $service = $this->service(new NotificationEmailOptions(false, true, false, false, false));
        [$forum, $topic, $post] = $this->contentObjects('F', 'T', "0\n<script src=https://evil.test/x.js></script>\n[url=javascript:alert(1)]bad[/url] 😀");
        $message = $service->compose('NewPost', 'reader', $forum, $topic, $post, '');

        self::assertStringContainsString('Post text:<br>' . "\n0", $message);
        self::assertStringContainsString('&lt;script', $message);
        self::assertStringNotContainsString('<script', $message);
        self::assertStringNotContainsString('href="javascript:', $message);
        self::assertStringContainsString('😀', $message);

        [, , $emptyPost] = $this->contentObjects('F', 'T', '');
        self::assertStringContainsString('Post text:', $service->compose('NewPost', 'reader', $forum, $topic, $emptyPost, ''));
    }

    public function testLegacyMarkerOverrideRespectsDisabledBlocksAndAppendsEnabledPostText(): void
    {
        $service = $this->service(new NotificationEmailOptions(false, true, false, false, false));
        $service->translations['Mail_Subscribe_NewPost_Body'] = "Hello ###RECIPIENT###\nOpen ###TOPIC_LINK### in ###FORUM_LINK###.\nRemove it here: ###UNSUBSCRIBE_LINK###";
        [$forum, $topic, $post] = $this->contentObjects('Forum name', 'Topic name', 'Complete text');

        $message = $service->compose('NewPost', 'Reader', $forum, $topic, $post, '<a href="https://example.test/unsubscribe">unsubscribe</a>');

        self::assertStringContainsString('Open Topic name in Forum name.', $message);
        self::assertStringContainsString('Complete text', $message);
        self::assertStringNotContainsString('<a ', $message);
        self::assertStringNotContainsString('Remove it here', $message);
    }

    #[DataProvider('languageProvider')]
    public function testShippedEnglishAndGermanFragmentsComposeACompleteMessage(
        string $file,
        string $greeting,
        string $postLabel,
        string $forumLinkLabel
    ): void {
        $service = $this->service(new NotificationEmailOptions(true, true, true, true, true));
        $service->translations = $this->languageLabels($file);
        [$forum, $topic, $post] = $this->contentObjects('Example forum', 'Example topic', 'Example post');

        $message = $service->compose('NewTopic', 'Alex', $forum, $topic, $post, '<a href="https://example.test/unsubscribe">unsubscribe</a>');

        self::assertStringContainsString($greeting, $message);
        self::assertStringContainsString($postLabel, $message);
        self::assertStringContainsString($forumLinkLabel, $message);
        self::assertDoesNotMatchRegularExpression('/###[A-Z_]+###/', $message);
    }

    public static function languageProvider(): iterable
    {
        yield 'English' => ['locallang.xlf', 'Hello Alex', 'Post text:', 'Open forum:'];
        yield 'German' => ['de.locallang.xlf', 'Hallo Alex', 'Beitragstext:', 'Forum öffnen:'];
    }

    /**
     * @param array<int, array{subject: string, body: string}> $messages
     */
    private function service(NotificationEmailOptions $options, array &$messages = []): TestableNotificationService
    {
        $configuration = new class ($options) implements NotificationEmailConfigurationInterface {
            public function __construct(private readonly NotificationEmailOptions $options)
            {
            }
            public function getOptions(): NotificationEmailOptions
            {
                return $this->options;
            }
        };
        $settings = $this->createStub(ConfigurationBuilder::class);
        $settings->method('getSettings')->willReturn([
            'mailing.' => ['sender.' => ['name' => 'Forum Team']],
            'pids.' => ['Forum' => 23],
        ]);
        $mail = $this->createMock(HTMLMailingService::class);
        $mail->method('sendMail')->willReturnCallback(static function (FrontendUser $recipient, string $subject, string $body) use (&$messages): void {
            $messages[] = ['subject' => $subject, 'body' => $body];
        });
        return new TestableNotificationService(
            $mail,
            $this->createStub(ContentObjectRenderer::class),
            $settings,
            new NotificationConfigurationResolver($configuration),
            new NotificationEmailRenderer()
        );
    }

    /** @return array{Forum, Topic, Post} */
    private function contentObjects(string $forumTitle, string $topicTitle, string $postText): array
    {
        $forum = $this->createStub(Forum::class);
        $forum->method('getTitle')->willReturn($forumTitle);
        $forum->method('getUid')->willReturn(7);
        $topic = $this->createStub(Topic::class);
        $topic->method('getName')->willReturn($topicTitle);
        $topic->method('getTitle')->willReturn($topicTitle);
        $topic->method('getUid')->willReturn(14548);
        $topic->method('getForum')->willReturn($forum);
        $post = $this->createStub(Post::class);
        $post->method('getUid')->willReturn(77);
        $post->method('getText')->willReturn($postText);
        $post->method('getAuthorName')->willReturn('Author');
        $post->method('getTopic')->willReturn($topic);
        return [$forum, $topic, $post];
    }

    private function user(int $uid, string $username): FrontendUser
    {
        $user = $this->createStub(FrontendUser::class);
        $user->method('getUid')->willReturn($uid);
        $user->method('getUsername')->willReturn($username);
        return $user;
    }

    private function storage(FrontendUser ...$users): ObjectStorage
    {
        $storage = new ObjectStorage();
        foreach ($users as $user) {
            $storage->attach($user);
        }
        return $storage;
    }

    /** @return array<string, string> */
    private function languageLabels(string $file): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/Resources/Private/Language/' . $file);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        $labels = [];
        foreach ($xml->xpath('//*[local-name()="trans-unit"]') ?: [] as $unit) {
            $id = (string)$unit['id'];
            $target = $unit->xpath('./*[local-name()="target"]');
            $source = $unit->xpath('./*[local-name()="source"]');
            $labels[$id] = (string)(($target[0] ?? null) ?: ($source[0] ?? ''));
        }
        return $labels;
    }
}

final class TestableNotificationService extends NotificationService
{
    /** @var array<string, string> */
    public array $translations = [];

    public function compose(
        string $event,
        string $recipient,
        Forum $contentForum,
        Topic $topic,
        Post $post,
        string $unsubscribeLink
    ): string {
        return $this->getMessage(
            $event,
            $recipient,
            $contentForum,
            $topic,
            $post,
            $unsubscribeLink,
            $this->notificationConfigurationResolver->resolve($contentForum)
        );
    }

    public function subject(string $translationKey, Forum $forum): string
    {
        return $this->getSubject($translationKey, $forum, $this->notificationConfigurationResolver->resolve($forum));
    }

    protected function translate(string $key): string
    {
        return $this->translations[$key] ?? [
            'Mail_Subscribe_NewPost_Subject' => 'New reply',
            'Mail_Subscribe_NewTopic_Subject' => 'New topic',
            'Mail_Subscribe_NewPost_Body' => "###GREETING###\n\n###EVENT_DESCRIPTION###\n\n###POST_TEXT_BLOCK###\n###FORUM_LINK_BLOCK###\n###TOPIC_LINK_BLOCK###\n###UNSUBSCRIBE_BLOCK###\n\n###SIGNATURE###",
            'Mail_Subscribe_NewTopic_Body' => "###GREETING###\n\n###EVENT_DESCRIPTION###\n\n###POST_TEXT_BLOCK###\n###FORUM_LINK_BLOCK###\n###TOPIC_LINK_BLOCK###\n###UNSUBSCRIBE_BLOCK###\n\n###SIGNATURE###",
            'Mail_Subscribe_Greeting' => 'Hello ###RECIPIENT###,',
            'Mail_Subscribe_NewPost_Event' => '###POST_AUTHOR### replied to "###TOPIC_NAME###" in "###FORUM_NAME###".',
            'Mail_Subscribe_NewTopic_Event' => '###POST_AUTHOR### created "###TOPIC_NAME###" in "###FORUM_NAME###".',
            'Mail_Subscribe_PostTextBlock' => "Post text:\n###POST_TEXT###",
            'Mail_Subscribe_ForumLinkBlock' => 'Open forum: ###FORUM_LINK###',
            'Mail_Subscribe_NewPost_TopicLinkBlock' => 'Open reply: ###POST_LINK###',
            'Mail_Subscribe_NewTopic_TopicLinkBlock' => 'Open topic: ###TOPIC_LINK###',
            'Mail_Subscribe_NewPost_UnsubscribeBlock' => 'This unsubscribes only the topic: ###UNSUBSCRIBE_LINK###',
            'Mail_Subscribe_NewTopic_UnsubscribeBlock' => 'This unsubscribes only the forum: ###UNSUBSCRIBE_LINK###',
            'Mail_Subscribe_Signature' => 'Regards, ###FORUM_TEAM###',
            'Button_Unsubscribe' => 'unsubscribe',
        ][$key] ?? $key;
    }

    protected function getForumLink(Forum $forum): string
    {
        return $this->notificationEmailRenderer->link('https://example.test/forum/' . $forum->getUid(), $forum->getTitle());
    }

    protected function getTopicLink(Topic $topic): string
    {
        return $this->notificationEmailRenderer->link('https://example.test/topic/' . $topic->getUid(), $topic->getTitle());
    }

    protected function getPostLink(Post $post): string
    {
        return $this->notificationEmailRenderer->link('https://example.test/post/' . $post->getUid(), $post->getTopic()?->getTitle() ?? '');
    }

    protected function getForumUnsubscribeLink(Forum $forum): string
    {
        return $this->notificationEmailRenderer->link('https://example.test/unsubscribe/forum/' . $forum->getUid(), 'unsubscribe');
    }

    protected function getTopicUnsubscribeLink(Topic $topic): string
    {
        return $this->notificationEmailRenderer->link('https://example.test/unsubscribe/topic/' . $topic->getUid(), 'unsubscribe');
    }
}
