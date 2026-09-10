<?php

declare(strict_types=1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use GuzzleHttp\Psr7\Uri;
use Mittwald\Typo3Forum\Controller\ForumController;
use Mittwald\Typo3Forum\Domain\Exception\Authentication\NotLoggedInException;
use Mittwald\Typo3Forum\Domain\Model\Forum\Access;
use Mittwald\Typo3Forum\Domain\Model\Forum\{Forum, RootForum, Topic};
use Mittwald\Typo3Forum\Domain\Model\User\{AnonymousFrontendUser, FrontendUser};
use Mittwald\Typo3Forum\Domain\Repository\Forum\{ForumRepository, TopicRepository};
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Localization\{LanguageService, LanguageServiceFactory, Locale, Locales};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3\CMS\Extbase\Persistence\{ObjectStorage, QueryResultInterface};

final class ForumControllerTest extends AbstractControllerTestCase
{
    private ForumController $controller;
    private ForumRepository&MockObject $forumRepository;
    private TopicRepository&MockObject $topicRepository;
    private mixed $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = (new \ReflectionProperty(GeneralUtility::class, 'container'))->getValue();
        $language = $this->createStub(LanguageService::class);
        $language->method('translate')->willReturnCallback(
            static fn(string $key): string => $key === 'Error_AccessDenied_Title'
                ? 'Access denied'
                : 'You do not have permission to view this content.'
        );
        $language->method('getLocale')->willReturn(new Locale('en'));
        $factory = $this->createStub(LanguageServiceFactory::class);
        $factory->method('create')->willReturn($language);
        $factory->method('createFromUserPreferences')->willReturn($language);
        $locales = $this->createStub(Locales::class);
        $locales->method('createLocaleFromRequest')->willReturn(new Locale('en'));
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => in_array($id, [LanguageServiceFactory::class, Locales::class], true)
        );
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => $id === Locales::class ? $locales : $factory
        );
        GeneralUtility::setContainer($container);
        $this->forumRepository = $this->createMock(ForumRepository::class);
        $this->topicRepository = $this->createMock(TopicRepository::class);
        $this->controller = new ForumController(
            $this->forumRepository,
            $this->topicRepository,
            $this->createStub(RootForum::class),
        );
        $this->initializeController($this->controller);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(GeneralUtility::class, 'container'))->setValue(null, $this->previousContainer);
        parent::tearDown();
    }

    public function testIndexForwardsTheFirstRootForumToShow(): void
    {
        $forum = $this->forum();
        $this->forumRepository->expects(self::once())->method('findFirstRootForum')->willReturn($forum);
        $this->view->expects(self::never())->method('render');
        $response = $this->controller->indexAction();
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertInstanceOf(ForwardResponse::class, $response);
        self::assertSame('show', $response->getActionName());
        self::assertSame('Forum', $response->getControllerName());
        self::assertSame(['forum' => $forum], $response->getArguments());
    }

    public function testIndexWithoutRootForumAssignsPageAndReturnsHtml(): void
    {
        $this->forumRepository->expects(self::once())->method('findFirstRootForum')->willReturn(null);
        $this->view->expects(self::once())->method('assign')->with('page', 3)->willReturnSelf();
        $this->view->expects(self::once())->method('render')->willReturn('<p>No forums</p>');
        $this->assertHtmlResponse($this->controller->indexAction(3), '<p>No forums</p>');
    }

    public function testShowChecksReadAccessAndAssignsForumTopicsAndPage(): void
    {
        $forum = $this->forum();
        $topics = $this->createStub(QueryResultInterface::class);
        $this->topicRepository->expects(self::once())->method('findForIndex')->with($forum)->willReturn($topics);
        $this->authenticationService->expects(self::once())->method('checkAuthorization')
            ->with($forum, Access::TYPE_READ)->willReturn(true);
        $this->view->expects(self::once())->method('assignMultiple')
            ->with(['forum' => $forum, 'topics' => $topics, 'page' => 2])->willReturnSelf();
        $this->view->expects(self::once())->method('render')->willReturn('<p>Forum</p>');
        $this->assertHtmlResponse($this->controller->showAction($forum, 2), '<p>Forum</p>');
    }

    public function testShowRedirectsAnonymousUserToLoginWithOriginalUrl(): void
    {
        $forum = $this->forum();
        $requestUri = 'https://example.test/forum/private?filter=unread';
        $request = $this->createStub(RequestInterface::class);
        $request->method('getUri')->willReturn(new Uri($requestUri));
        $anonymousUser = (new \ReflectionClass(AnonymousFrontendUser::class))->newInstanceWithoutConstructor();
        $this->setProperty($this->controller, 'request', $request);
        $this->setProperty($this->controller, 'settings', ['pids' => ['Login' => 42]]);
        $this->authenticationService->expects(self::once())->method('checkAuthorization')
            ->with($forum, Access::TYPE_READ)->willReturn(false);
        $this->frontendUserRepository->expects(self::once())->method('findCurrent')->willReturn($anonymousUser);
        $this->uriBuilder->expects(self::once())->method('reset')->willReturnSelf();
        $this->uriBuilder->expects(self::once())->method('setTargetPageUid')->with(42)->willReturnSelf();
        $this->uriBuilder->expects(self::once())->method('setArguments')
            ->with(['redirect_url' => $requestUri])->willReturnSelf();
        $this->uriBuilder->expects(self::once())->method('buildFrontendUri')
            ->willReturn('https://example.test/login?redirect_url=' . rawurlencode($requestUri));
        $this->topicRepository->expects(self::never())->method('findForIndex');
        $this->view->expects(self::never())->method('assignMultiple');
        $this->view->expects(self::never())->method('render');

        $response = $this->controller->showAction($forum);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            'https://example.test/login?redirect_url=' . rawurlencode($requestUri),
            $response->getHeaderLine('Location')
        );
        self::assertDoesNotMatchRegularExpression('/(?:id|sid|session|fe_session)=/i', $requestUri);
    }

    public function testShowReturnsClearForbiddenResponseForAuthenticatedUserWithoutAccess(): void
    {
        $forum = $this->forum();
        $user = $this->createStub(FrontendUser::class);
        $user->method('isAnonymous')->willReturn(false);
        $this->authenticationService->expects(self::once())->method('checkAuthorization')
            ->with($forum, Access::TYPE_READ)->willReturn(false);
        $this->frontendUserRepository->expects(self::once())->method('findCurrent')->willReturn($user);
        $this->topicRepository->expects(self::never())->method('findForIndex');
        $this->view->expects(self::never())->method('assignMultiple');
        $this->view->expects(self::never())->method('render');

        $response = $this->controller->showAction($forum);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('alert-danger', (string)$response->getBody());
        self::assertStringContainsString('Access denied', (string)$response->getBody());
    }

    public function testMarkReadRejectsTheAnonymousUserReturnedWhenLoggedOut(): void
    {
        // findCurrent() now always returns FrontendUser, with AnonymousFrontendUser for guests.
        $user = (new \ReflectionClass(AnonymousFrontendUser::class))->newInstanceWithoutConstructor();
        $forum = $this->forum();
        $this->frontendUserRepository->expects(self::once())->method('findCurrent')->willReturn($user);
        $this->forumRepository->expects(self::never())->method('update');
        $this->uriBuilder->expects(self::never())->method('uriFor');
        $this->expectException(NotLoggedInException::class);
        $this->expectExceptionCode(1288084981);
        $this->controller->markReadAction($forum);
    }

    public function testMarkReadMarksAnEmptyForumAndReturns307Redirect(): void
    {
        $forum = $this->forum();
        $user = $this->loggedInUser();
        $this->forumRepository->expects(self::once())->method('update')->with($forum);
        $this->expectShowUri($forum);
        $response = $this->controller->markReadAction($forum);
        self::assertTrue($forum->getReaders()->contains($user));
        $this->assertShowRedirect($response);
    }

    public function testMarkReadUpdatesTheForumItsChildrenAndTheirTopics(): void
    {
        $forum = $this->forum();
        $child = $this->forum();
        $forum->getChildren()->attach($child);
        $topic = $this->topic();
        $childTopic = $this->topic();
        $forum->getTopics()->attach($topic);
        $child->getTopics()->attach($childTopic);
        $user = $this->loggedInUser();
        $updated = [];
        $this->forumRepository->expects(self::exactly(2))->method('update')
            ->willReturnCallback(static function (object $object) use (&$updated): void { $updated[] = $object; });
        $this->expectShowUri($forum);
        $response = $this->controller->markReadAction($forum);
        self::assertSame([$forum, $child], $updated);
        foreach ([$forum, $child, $topic, $childTopic] as $object) {
            self::assertTrue($object->getReaders()->contains($user));
        }
        $this->assertShowRedirect($response);
    }

    private function loggedInUser(): FrontendUser
    {
        $user = $this->createStub(FrontendUser::class);
        $user->method('isAnonymous')->willReturn(false);
        $this->frontendUserRepository->expects(self::once())->method('findCurrent')->willReturn($user);
        return $user;
    }

    private function expectShowUri(Forum $forum): void
    {
        $this->uriBuilder->expects(self::once())->method('uriFor')
            ->with('show', ['forum' => $forum], 'Forum')->willReturn('/forum/show');
    }

    private function assertShowRedirect(ResponseInterface $response): void
    {
        self::assertSame(307, $response->getStatusCode());
        self::assertSame('/forum/show', $response->getHeaderLine('Location'));
    }

    private function assertHtmlResponse(ResponseInterface $response, string $html): void
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame($html, (string)$response->getBody());
    }

    private function forum(): Forum
    {
        // Keep real read-state behavior without the entity constructor's service dependencies.
        $forum = (new \ReflectionClass(Forum::class))->newInstanceWithoutConstructor();
        foreach (['children', 'visibleChildren', 'topics', 'readers'] as $property) {
            $this->setProperty($forum, $property, new ObjectStorage());
        }
        return $forum;
    }

    private function topic(): Topic
    {
        $topic = (new \ReflectionClass(Topic::class))->newInstanceWithoutConstructor();
        $this->setProperty($topic, 'readers', new ObjectStorage());
        return $topic;
    }
}
