<?php
declare(strict_types=1);
namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Configuration\ConfigurationBuilder;
use Mittwald\Typo3Forum\Controller\{AjaxController, PostController, ReportController, TopicController};
use Mittwald\Typo3Forum\Domain\Factory\Forum\{PostFactory, TopicFactory};
use Mittwald\Typo3Forum\Domain\Model\Forum\{Access, Forum, Post, RootForum, Topic};
use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Repository\Forum\ForumRepository;
use Mittwald\Typo3Forum\Service\Mailing\HTMLMailingService;
use Mittwald\Typo3Forum\Service\Notification\NotificationService;
use Mittwald\Typo3Forum\Service\{AttachmentService, TagService};
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\{LanguageService, LanguageServiceFactory, Locale, Locales};
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

final class RuntimeRegressionTest extends AbstractControllerTestCase
{
    private mixed $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = (new \ReflectionProperty(GeneralUtility::class, 'container'))->getValue();
        $language = $this->createStub(LanguageService::class);
        $language->method('translate')->willReturn('Test message');
        $language->method('getLocale')->willReturn(new Locale('en'));
        $factory = $this->createStub(LanguageServiceFactory::class);
        $factory->method('create')->willReturn($language);
        $factory->method('createFromUserPreferences')->willReturn($language);
        $locales = $this->createStub(Locales::class);
        $locales->method('createLocaleFromRequest')->willReturn(new Locale('en'));
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn($id) => in_array($id, [LanguageServiceFactory::class, Locales::class], true));
        $container->method('get')->willReturnCallback(fn($id) => $id === Locales::class ? $locales : $factory);
        GeneralUtility::setContainer($container);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(GeneralUtility::class, 'container'))->setValue(null, $this->previousContainer);
        parent::tearDown();
    }

    public function testAjaxControllerAutowiresExtbaseLifecycleDependencies(): void
    {
        $services = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 2) . '/Configuration/Services.yaml')['services'];
        $config = array_replace($services['_defaults'], $services[AjaxController::class]);
        self::assertTrue($config['autowire']);

        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->setDefinition(
            AjaxController::class,
            (new \Symfony\Component\DependencyInjection\Definition(AjaxController::class))->setAutowired(true)
        );
        (new \TYPO3\CMS\Core\DependencyInjection\AutowireInjectMethodsPass())->process($container);
        $methodCalls = array_column($container->getDefinition(AjaxController::class)->getMethodCalls(), 0);

        self::assertContains('injectReflectionService', $methodCalls);
        self::assertContains('injectConfigurationManager', $methodCalls);
        self::assertContains('injectResponseFactory', $methodCalls);
    }

    public function testForumNotificationsIncludeFirstAndParentSubscriberOnlyOnce(): void
    {
        $users = [];
        foreach ([1, 2, 3, 4, 5] as $uid) {
            $user = $this->createStub(FrontendUser::class);
            $user->method('getUid')->willReturn($uid);
            $user->method('getUsername')->willReturn('user' . $uid);
            $users[$uid] = $user;
        }
        $forum = $this->createStub(Forum::class);
        $parent = $this->createStub(Forum::class);
        $forum->method('getParent')->willReturn($parent);
        $parent->method('getParent')->willReturn(null);
        foreach ([[$forum, [1, 2, 3]], [$parent, [1, 2, 3, 4, 5]]] as [$node, $uids]) {
            $storage = new ObjectStorage();
            foreach ($uids as $uid) { $storage->attach($users[$uid]); }
            $node->method('getSubscribers')->willReturn($storage);
            $node->method('checkReadAccess')->willReturnCallback(
                fn($user) => $node === $parent || !in_array($user->getUid(), [3, 5], true)
            );
        }
        $post = $this->createStub(Post::class);
        $post->method('getAuthor')->willReturn($users[2]);
        $topic = $this->createStub(Topic::class);
        $topic->method('getLastPost')->willReturn($post);
        $mail = $this->createMock(HTMLMailingService::class);
        $recipients = [];
        $mail->expects(self::exactly(2))->method('sendMail')->willReturnCallback(function ($user) use (&$recipients): void { $recipients[] = $user->getUid(); });
        $service = new class($mail, $this->createStub(ContentObjectRenderer::class), $this->createStub(ConfigurationBuilder::class)) extends NotificationService {
            protected function getMessage(Forum $forum, Topic $topic, Post $post, string $messageTemplate, string $unsubscribeLink): string { return 'Hello ###RECIPIENT###'; }
            protected function getForumUnsubscribeLink(Forum $forum): string { return '/unsubscribe'; }
        };
        $service->notifySubscribers($forum, $topic);
        self::assertSame([1, 4], $recipients, 'Author 2 and users 3/5 without origin-forum access are excluded even when subscribed to an accessible parent; user 1 is not duplicated.');
    }

    public function testNotificationLinksUseTheCurrentFrontendRequestWithoutSessionState(): void
    {
        $request = new ServerRequest('https://example.test/forum?FE_SESSION_KEY=must-not-leak');
        $previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $GLOBALS['TYPO3_REQUEST'] = $request;
        try {
            $contentObjectRenderer = $this->createMock(ContentObjectRenderer::class);
            $contentObjectRenderer->expects(self::once())->method('setRequest')->with($request);
            $contentObjectRenderer->expects(self::once())->method('createUrl')->with([
                'parameter' => 23,
                'queryParameters' => [
                    'tx_typo3forum_forum[controller]' => 'User',
                    'tx_typo3forum_forum[action]' => 'subscribe',
                    'tx_typo3forum_forum[topic]' => 42,
                    'tx_typo3forum_forum[unsubscribe]' => 1,
                ],
                'forceAbsoluteUrl' => true,
                'linkAccessRestrictedPages' => true,
            ])->willReturn('https://example.test/unsubscribe');
            $configuration = $this->createStub(ConfigurationBuilder::class);
            $configuration->method('getSettings')->willReturn(['pids.' => ['Forum' => 23]]);
            $topic = $this->createStub(Topic::class);
            $topic->method('getUid')->willReturn(42);
            $service = new class($this->createStub(HTMLMailingService::class), $contentObjectRenderer, $configuration) extends NotificationService {
                public function topicUnsubscribeLink(Topic $topic): string
                {
                    return $this->getTopicUnsubscribeLink($topic);
                }
            };

            $link = $service->topicUnsubscribeLink($topic);
            self::assertSame('<a href="https://example.test/unsubscribe">Test message</a>', $link);
            self::assertStringNotContainsString('SESSION', $link);
        } finally {
            if ($previousRequest === null) {
                unset($GLOBALS['TYPO3_REQUEST']);
            } else {
                $GLOBALS['TYPO3_REQUEST'] = $previousRequest;
            }
        }
    }

    public function testRootInitializesCollectionsAndRetainsInjectedAuthentication(): void
    {
        $repository = $this->createStub(ForumRepository::class);
        $children = new ObjectStorage();
        $repository->method('findRootForums')->willReturn($children);
        $root = new RootForum($repository);
        foreach (['getTopics', 'getAcls', 'getSubscribers', 'getReaders'] as $method) {
            self::assertCount(0, $root->$method());
        }
        self::assertSame($children, $root->getChildren());
        self::assertTrue($root->checkAccess());
        self::assertFalse($root->checkAccess(null, Access::TYPE_NEW_POST));
        $root->injectAuthenticationService($this->authenticationService);
        $root->initializeObject();
        self::assertSame($this->authenticationService, (new \ReflectionProperty($root, 'authenticationService'))->getValue($root));
    }

    public function testParentForumReturnsTheLastPostFromItsChildren(): void
    {
        $this->authenticationService->method('checkAuthorization')->willReturn(true);
        $parent = new Forum();
        $parent->injectAuthenticationService($this->authenticationService);
        $child = new Forum();
        $child->injectAuthenticationService($this->authenticationService);
        $lastPost = $this->createStub(Post::class);
        $lastPost->method('getTimestamp')->willReturn(new \DateTime());
        $child->setLastPost($lastPost);
        $parent->addChild($child);

        self::assertSame($lastPost, $parent->getLastPost());
    }

    public function testRegularUsersReadTagCreationPermissionFromTypoScriptSettings(): void
    {
        $user = new FrontendUser();
        (new \ReflectionProperty($user, 'settings'))->setValue($user, [
            'forum.' => ['tag.' => ['usersCanCreate' => '1']],
        ]);

        self::assertTrue($user->canCreateTags());
    }

    private function controller(string $class): object
    {
        $controller = $this->getMockBuilder($class)->disableOriginalConstructor()
            ->onlyMethods(['getFlashMessageQueue', 'clearCacheForCurrentPage', 'purgeUrl'])->getMock();
        $controller->method('getFlashMessageQueue')->willReturn(new FlashMessageQueue('test'));
        $this->initializeController($controller);
        $this->setProperty($controller, 'eventDispatcher', $this->createStub(EventDispatcherInterface::class));
        return $controller;
    }

    public function testUserReportReturnsTheRedirectToConfiguredUserPage(): void
    {
        $controller = $this->controller(ReportController::class);
        $user = $this->createStub(FrontendUser::class);
        $report = $this->createMock(\Mittwald\Typo3Forum\Domain\Model\Moderation\UserReport::class);
        $report->expects(self::once())->method('setUser')->with($user);
        $factory = $this->createStub(\Mittwald\Typo3Forum\Domain\Factory\Moderation\ReportFactory::class);
        $factory->method('createUserReport')->willReturn($report);
        $repository = $this->createMock(\Mittwald\Typo3Forum\Domain\Repository\Moderation\UserReportRepository::class);
        $repository->expects(self::once())->method('add')->with($report);
        $this->setProperty($controller, 'reportFactory', $factory);
        $this->setProperty($controller, 'userReportRepository', $repository);
        $this->setProperty($controller, 'settings', ['pids.' => ['UserShow' => 42]]);
        $request = $this->createStub(Request::class);
        $request->method('getFormat')->willReturn('html');
        $params = $this->createStub(NormalizedParams::class);
        $params->method('isHttps')->willReturn(false);
        $request->method('getAttribute')->with('normalizedParams')->willReturn($params);
        $this->setProperty($controller, 'request', $request);
        $this->uriBuilder->method('reset')->willReturnSelf();
        $this->uriBuilder->method('setCreateAbsoluteUri')->willReturnSelf();
        $this->uriBuilder->expects(self::once())->method('setTargetPageUid')->with(42)->willReturnSelf();
        $this->uriBuilder->expects(self::once())->method('uriFor')->with('show', ['user' => $user], 'User', null)->willReturn('/user/42');
        $response = $controller->createUserReportAction($user, $this->createStub(\Mittwald\Typo3Forum\Domain\Model\Moderation\ReportComment::class));
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/user/42', $response->getHeaderLine('Location'));
    }

    public function testCreatingPostProcessesSuppliedAttachments(): void
    {
        $controller = $this->controller(PostController::class);
        $topic = $this->createMock(Topic::class);
        $post = $this->createMock(Post::class);
        $files = [['name' => 'upload.txt']];
        $attachments = new ObjectStorage();
        $service = $this->createMock(\Mittwald\Typo3Forum\Service\AttachmentService::class);
        $service->expects(self::once())->method('initAttachments')->with($files)->willReturn($attachments);
        $post->expects(self::once())->method('setAttachments')->with($attachments);
        $topic->expects(self::once())->method('addPost')->with($post);
        $this->setProperty($controller, 'attachmentService', $service);
        $this->setProperty($controller, 'postFactory', $this->createStub(\Mittwald\Typo3Forum\Domain\Factory\Forum\PostFactory::class));
        $this->setProperty($controller, 'topicRepository', $this->createStub(\Mittwald\Typo3Forum\Domain\Repository\Forum\TopicRepository::class));
        $this->setProperty($controller, 'persistenceManager', $this->createStub(\TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager::class));
        self::assertSame(['post' => $post], $controller->createAction($topic, $post, $files)->getArguments());
    }

    public function testCreatingTopicWithAttachmentResetsUriBuilderAfterCachePurge(): void
    {
        $controller = $this->controller(TopicController::class);
        $forum = $this->createStub(Forum::class);
        $forum->method('getUid')->willReturn(2);
        $post = $this->createMock(Post::class);
        $topic = $this->createStub(Topic::class);
        $uploadedFile = $this->createStub(UploadedFileInterface::class);
        $attachments = new ObjectStorage();
        $tags = new ObjectStorage();

        $attachmentService = $this->createMock(AttachmentService::class);
        $attachmentService->expects(self::once())->method('initAttachments')->with([$uploadedFile])->willReturn($attachments);
        $post->expects(self::once())->method('setAttachments')->with($attachments);
        $postFactory = $this->createMock(PostFactory::class);
        $postFactory->expects(self::once())->method('assignUserToPost')->with($post);
        $tagService = $this->createMock(TagService::class);
        $tagService->expects(self::once())->method('hydrateTags')->with([])->willReturn($tags);
        $topicFactory = $this->createMock(TopicFactory::class);
        $topicFactory->expects(self::once())->method('createTopic')
            ->with($forum, $post, 'Test', false, $tags, false)
            ->willReturn($topic);
        $persistenceManager = $this->createMock(PersistenceManager::class);
        $persistenceManager->expects(self::once())->method('persistAll');

        $this->setProperty($controller, 'attachmentService', $attachmentService);
        $this->setProperty($controller, 'postFactory', $postFactory);
        $this->setProperty($controller, 'tagService', $tagService);
        $this->setProperty($controller, 'topicFactory', $topicFactory);
        $this->setProperty($controller, 'persistenceManager', $persistenceManager);
        $this->setProperty($controller, 'settings', ['purgeCache' => true, 'pids' => ['Forum' => 23]]);

        $this->uriBuilder->expects(self::once())->method('setTargetPageUid')->with(23)->willReturnSelf();
        $this->uriBuilder->expects(self::once())->method('setArguments')->with([
            'tx_typo3forum_forum[forum]' => 2,
            'tx_typo3forum_forum[controller]' => 'Forum',
            'tx_typo3forum_forum[action]' => 'show',
        ])->willReturnSelf();
        $this->uriBuilder->expects(self::once())->method('build')->willReturn('forum/development-forums');
        $this->uriBuilder->expects(self::once())->method('reset')->willReturnSelf();
        $this->uriBuilder->expects(self::once())->method('uriFor')
            ->with('show', ['topic' => $topic], 'Topic')
            ->willReturn('/forum/topic/test');

        $previousHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'typo3forum.ddev.site';
        try {
            $response = $controller->createAction($forum, $post, 'Test', [], [$uploadedFile]);
        } finally {
            if ($previousHost === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $previousHost;
            }
        }

        self::assertSame(307, $response->getStatusCode());
        self::assertSame('/forum/topic/test', $response->getHeaderLine('Location'));
    }
}
