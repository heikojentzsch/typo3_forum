<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Configuration\ConfigurationBuilder;
use Mittwald\Typo3Forum\Domain\Factory\Forum\TopicFactory;
use Mittwald\Typo3Forum\Domain\Model\Forum\{Forum, Post, Topic};
use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Repository\Forum\{ForumRepository, PostRepository, TopicRepository};
use Mittwald\Typo3Forum\Domain\Repository\User\FrontendUserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

final class SolutionPointsTest extends TestCase
{
    public static function scores(): iterable
    {
        yield 'missing settings' => [[], 5, 2];
        yield 'configured strings' => [['rankScore.' => ['gaveSolution' => '9', 'selectedSolution' => '3']], 9, 3];
        yield 'explicit zero' => [['rankScore.' => ['gaveSolution' => 0, 'selectedSolution' => 0]], 0, 0];
        yield 'null values' => [['rankScore.' => ['gaveSolution' => null, 'selectedSolution' => null]], 5, 2];
    }

    #[DataProvider('scores')]
    public function testSolutionAwardsAndRemovalUseConfiguredOrDefaultPoints(array $settings, int $given, int $selected): void
    {
        $answerAuthor = $this->createMock(FrontendUser::class);
        $answerAuthor->expects(self::once())->method('increasePoints')->with($given)->willReturnSelf();
        $answerAuthor->expects(self::once())->method('decreasePoints')->with($given)->willReturnSelf();
        $questionAuthor = $this->createMock(FrontendUser::class);
        $questionAuthor->expects(self::once())->method('increasePoints')->with($selected)->willReturnSelf();
        $questionAuthor->expects(self::once())->method('decreasePoints')->with($selected)->willReturnSelf();
        $solution = $this->createStub(Post::class);
        $solution->method('getAuthor')->willReturn($answerAuthor);
        $topic = $this->getMockBuilder(Topic::class)->disableOriginalConstructor()->onlyMethods(['getAuthor', 'getForum'])->getMock();
        $topic->setQuestion(true);
        $topic->method('getAuthor')->willReturn($questionAuthor);
        $topic->method('getForum')->willReturn($this->createStub(Forum::class));
        $configuration = $this->createStub(ConfigurationBuilder::class);
        $configuration->method('getSettings')->willReturn($settings);
        $factory = new TopicFactory(
            $this->createStub(ForumRepository::class),
            $this->createStub(PostRepository::class),
            $this->createStub(TopicRepository::class),
            $this->createStub(PersistenceManager::class)
        );
        $factory->injectConfigurationBuilder($configuration);
        $factory->injectFrontendUserRepository($this->createStub(FrontendUserRepository::class));
        $factory->initializeObject();

        $factory->setPostAsSolution($topic, $solution);
        self::assertSame($solution, $topic->getSolution());
        $factory->setPostAsSolution($topic, null);
        self::assertNull($topic->getSolution());
    }
}
