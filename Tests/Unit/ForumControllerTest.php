<?php

declare(strict_types=1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Controller\ForumController;
use Mittwald\Typo3Forum\Domain\Exception\Authentication\{NoAccessException, NotLoggedInException};
use Mittwald\Typo3Forum\Domain\Model\Forum\{Forum, RootForum, Topic};
use Mittwald\Typo3Forum\Domain\Model\User\{AnonymousFrontendUser, FrontendUser};
use Mittwald\Typo3Forum\Domain\Repository\Forum\{ForumRepository, TopicRepository};
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Http\ForwardResponse;
use TYPO3\CMS\Extbase\Persistence\{ObjectStorage, QueryResultInterface};

final class ForumControllerTest extends AbstractControllerTestCase
{
    private ForumController $controller;
    private ForumRepository&MockObject $forumRepository;
    private TopicRepository&MockObject $topicRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forumRepository = $this->createMock(ForumRepository::class);
        $this->topicRepository = $this->createMock(TopicRepository::class);
        $this->controller = new ForumController(
            $this->forumRepository,
            $this->topicRepository,
            $this->createStub(RootForum::class),
        );
        $this->initializeController($this->controller);
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
        $this->authenticationService->expects(self::once())->method('assertReadAuthorization')->with($forum);
        $this->view->expects(self::once())->method('assignMultiple')
            ->with(['forum' => $forum, 'topics' => $topics, 'page' => 2])->willReturnSelf();
        $this->view->expects(self::once())->method('render')->willReturn('<p>Forum</p>');
        $this->assertHtmlResponse($this->controller->showAction($forum, 2), '<p>Forum</p>');
    }

    public function testShowPropagatesDeniedReadAccessWithoutRendering(): void
    {
        $forum = $this->forum();
        $this->topicRepository->method('findForIndex')->willReturn($this->createStub(QueryResultInterface::class));
        $this->authenticationService->expects(self::once())->method('assertReadAuthorization')->with($forum)
            ->willThrowException(new NoAccessException('Denied', 1284709852));
        $this->view->expects(self::never())->method('assignMultiple');
        $this->view->expects(self::never())->method('render');
        $this->expectException(NoAccessException::class);
        $this->expectExceptionCode(1284709852);
        $this->controller->showAction($forum);
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
