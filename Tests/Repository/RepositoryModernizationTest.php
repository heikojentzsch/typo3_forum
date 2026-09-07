<?php
declare(strict_types=1);

namespace Mittwald\Typo3Forum\Tests\Repository;

use Mittwald\Typo3Forum\Configuration\ConfigurationBuilder;
use Mittwald\Typo3Forum\Domain\Repository\AbstractRepository;
use Mittwald\Typo3Forum\Domain\Repository\Format\{BBCodeRepository, SmileyRepository, SyntaxHighlightingRepository};
use Mittwald\Typo3Forum\Domain\Repository\Forum\{ColorRepository, ForumRepository, PostRepository, TagRepository, TopicRepository};
use Mittwald\Typo3Forum\Domain\Repository\Moderation\{PostReportRepository, ReportWorkflowStatusRepository};
use Mittwald\Typo3Forum\Domain\Repository\Stats\SummaryRepository;
use Mittwald\Typo3Forum\Domain\Repository\User\{FrontendUserRepository, RankRepository, UserfieldRepository};
use Mittwald\Typo3Forum\Domain\Model\Forum\{Forum, Post, Tag, Topic};
use Mittwald\Typo3Forum\Domain\Model\User\{FrontendUser, Rank};
use Mittwald\Typo3Forum\Domain\Model\Moderation\{PostReport, ReportWorkflowStatus};
use Mittwald\Typo3Forum\Service\Authentication\AuthenticationServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\DependencyInjection\AutowireInjectMethodsPass;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\{Query, Typo3QuerySettings};
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapFactory;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\QueryObjectModelFactory;
use TYPO3\CMS\Extbase\Persistence\{PersistenceManagerInterface, QueryInterface, QueryResultInterface, Repository};
use Symfony\Component\DependencyInjection\{ContainerBuilder, Definition};
use Symfony\Component\Yaml\Yaml;

/** Tests use real TYPO3 queries/settings; only persistence execution is substituted. */
final class RepositoryModernizationTest extends TestCase
{
    private array $queries = [];
    private mixed $first = null;
    private array $rows = [];

    private function entity(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function repository(string $class, array $persistence = ['storagePid' => '7,9'], mixed $frameworkPid = '12', array $initialIds = [12]): Repository
    {
        $configuration = $this->createMock(ConfigurationManagerInterface::class);
        $configuration->method('getConfiguration')->willReturn([
            'persistence' => ['storagePid' => $frameworkPid],
            'settings' => ['userfields' => ['core_fields' => []]],
        ]);
        $authentication = $this->createMock(AuthenticationServiceInterface::class);
        $authentication->method('checkAuthorization')->willReturn(true);
        $authentication->method('checkModerationAuthorization')->willReturn(true);
        $repo = match ($class) {
            ForumRepository::class => new ForumRepository($authentication),
            PostReportRepository::class => new PostReportRepository($authentication, $configuration),
            UserfieldRepository::class => new UserfieldRepository($configuration),
            default => new $class(),
        };
        $manager = $this->createMock(PersistenceManagerInterface::class);
        $manager->method('createQueryForType')->willReturnCallback(function (string $type) use ($configuration, $manager, $initialIds) {
            $factory = new QueryObjectModelFactory();
            $query = $this->getMockBuilder(Query::class)->setConstructorArgs([
                $this->createMock(DataMapFactory::class), $manager, $factory, $this->createMock(ContainerInterface::class),
            ])->onlyMethods(['execute'])->getMock();
            $query->setType($type);
            $query->setSource($factory->selector($type, 'fixture'));
            $settings = new Typo3QuerySettings(new Context(), $configuration);
            $settings->setStoragePageIds($initialIds);
            $query->setQuerySettings($settings);
            $query->method('execute')->willReturnCallback(function () {
                $result = $this->createMock(QueryResultInterface::class);
                $result->method('getFirst')->willReturn($this->first);
                $result->method('toArray')->willReturn($this->rows);
                return $result;
            });
            $this->queries[] = $query;
            return $query;
        });
        $repo->injectPersistenceManager($manager);
        $features = $this->createMock(\TYPO3\CMS\Core\Configuration\Features::class);
        $features->method('isFeatureEnabled')->willReturn(false);
        $repo->injectFeatures($features);
        if ($repo instanceof AbstractRepository) {
            $builder = $this->createMock(ConfigurationBuilder::class);
            $builder->method('getSettings')->willReturn(['timeIntervals' => ['onlineUser' => 900]]);
            $builder->method('getPersistenceSettings')->willReturn($persistence);
            $repo->injectConfigurationBuilder($builder);
            $repo->initializeObject();
        }
        return $repo;
    }

    private function lastQuery(): Query
    {
        return $this->queries[array_key_last($this->queries)];
    }

    public static function propertyQueries(): array
    {
        return [
            [TagRepository::class, 'findAllOrderedByCounter', [], ['topicCount' => 'DESC'], Tag::class],
            [TopicRepository::class, 'findForIndex', [Forum::class], ['sticky' => 'DESC', 'lastPostCrdate' => 'DESC'], Topic::class],
            [TopicRepository::class, 'findLastByForum', [Forum::class, 2], ['lastPostCrdate' => 'DESC'], Topic::class],
            [TopicRepository::class, 'findLatest', [2, 6], ['lastPostCrdate' => 'DESC'], Topic::class],
            [RankRepository::class, 'findOneByPoints', [15], ['pointLimit' => 'ASC'], Rank::class],
        ];
    }

    #[DataProvider('propertyQueries')]
    public function testPropertyQueries(string $class, string $method, array $args, array $orderings, string $model): void
    {
        $repo = $this->repository($class);
        $args = array_map(fn($arg) => is_string($arg) && class_exists($arg) ? $this->entity($arg) : $arg, $args);
        $repo->$method(...$args);
        $query = $this->lastQuery();
        self::assertSame($orderings, $query->getOrderings());
        foreach (array_keys($orderings) as $property) {
            self::assertTrue((new \ReflectionClass($model))->hasProperty($property));
        }
        if ($method === 'findForIndex' || $method === 'findLastByForum') {
            self::assertSame('forum', $query->getConstraint()->getOperand1()->getPropertyName());
            self::assertSame($args[0], $query->getConstraint()->getOperand2());
        }
        if ($method === 'findLastByForum' || $method === 'findOneByPoints') {
            self::assertSame(1, $query->getLimit());
        }
        if ($method === 'findLatest') {
            self::assertSame(6, $query->getLimit());
            self::assertSame(2, $query->getOffset());
        }
        if ($method === 'findOneByPoints') {
            self::assertSame('pointLimit', $query->getConstraint()->getOperand1()->getPropertyName());
            self::assertSame(QueryInterface::OPERATOR_GREATER_THAN, $query->getConstraint()->getOperator());
            self::assertSame(15, $query->getConstraint()->getOperand2());
        }
    }

    public function testOnlinePropertyAndThreshold(): void
    {
        $repo = $this->repository(FrontendUserRepository::class);
        $before = time();
        $repo->findByFilter(6, ['username' => 'ASC'], true);
        $query = $this->lastQuery();
        $constraint = $query->getConstraint()->getConstraint1();
        self::assertSame('isOnline', $constraint->getOperand1()->getPropertyName());
        self::assertTrue((new \ReflectionClass(FrontendUser::class))->hasProperty('isOnline'));
        self::assertGreaterThanOrEqual($before - 900, $constraint->getOperand2());
        self::assertLessThanOrEqual(time() - 900, $constraint->getOperand2());
        self::assertSame(6, $query->getLimit());
        self::assertSame(['username' => 'ASC'], $query->getOrderings());
    }

    public static function fallbackRepositories(): array
    {
        return array_map(fn($class) => [$class], [BBCodeRepository::class, SmileyRepository::class, SyntaxHighlightingRepository::class, ColorRepository::class, UserfieldRepository::class, ReportWorkflowStatusRepository::class]);
    }

    #[DataProvider('fallbackRepositories')]
    public function testFallbackAndRepeatedQueries(string $class): void
    {
        foreach (['7,9', '7,0,0', '0'] as $ids) {
            $repo = $this->repository($class, ['storagePid' => $ids]);
            for ($i = 0; $i < 2; $i++) {
                $repo->findAll();
                $settings = $this->lastQuery()->getQuerySettings();
                $actual = array_map('intval', $settings->getStoragePageIds());
                self::assertSame(array_values(array_unique([...array_map('intval', explode(',', $ids)), 0])), $actual);
                self::assertTrue($settings->getRespectStoragePage());
            }
        }
    }

    public static function reportSettings(): array
    {
        return [
            [['storagePid' => '7,9'], '12', ['7','9'], true],
            [['storagePid' => '0'], '12', ['0'], true],
            [['storagePid' => ''], '12', [''], true],
            [[], '12,14', ['12','14'], true],
            [[], '0', ['0'], false],
            [[], '', [''], false],
            [[], null, [12], false],
        ];
    }

    #[DataProvider('reportSettings')]
    public function testReportStoragePrecedenceAndAuthorizedQueries(array $persistence, mixed $framework, array $ids, bool $respect): void
    {
        $repo = $this->repository(PostReportRepository::class, $persistence, $framework);
        $settings = $repo->createQuery()->getQuerySettings();
        self::assertSame($ids, $settings->getStoragePageIds());
        self::assertSame($respect, $settings->getRespectStoragePage());
        self::assertSame([], $repo->findAllAuthorizedToEdit());
        self::assertFalse($this->lastQuery()->getQuerySettings()->getRespectStoragePage());
        // Preserve the existing persistent default override of the authorized finder.
        self::assertFalse($repo->createQuery()->getQuerySettings()->getRespectStoragePage());
    }

    public function testAbsentStorageConfigurationKeepsCoreSettings(): void
    {
        $repo = $this->repository(PostRepository::class, [], null, [22]);
        self::assertSame([22], $repo->createQuery()->getQuerySettings()->getStoragePageIds());
        $repo = $this->repository(BBCodeRepository::class, [], null, [22]);
        $repo->findAll();
        self::assertSame([22, 0], $this->lastQuery()->getQuerySettings()->getStoragePageIds());
        self::assertSame(['uid' => 'ASC'], $this->lastQuery()->getOrderings());
    }

    public function testModerationFilterPreservesAccessChecksAndArrayKeys(): void
    {
        $repo = $this->repository(PostReportRepository::class);
        $allowed = $this->entity(Forum::class);
        $denied = $this->entity(Forum::class);
        $authentication = $this->createMock(AuthenticationServiceInterface::class);
        $authentication->expects($this->exactly(2))->method('checkModerationAuthorization')
            ->willReturnCallback(fn(Forum $forum) => $forum === $allowed);
        (new \ReflectionProperty(PostReportRepository::class, 'authenticationService'))->setValue($repo, $authentication);
        foreach ([4 => $allowed, 6 => $denied] as $key => $forum) {
            $topic = $this->getMockBuilder(Topic::class)->disableOriginalConstructor()->onlyMethods(['getForum'])->getMock();
            $topic->method('getForum')->willReturn($forum);
            $report = $this->getMockBuilder(PostReport::class)->disableOriginalConstructor()->onlyMethods(['getTopic'])->getMock();
            $report->method('getTopic')->willReturn($topic);
            $this->rows[$key] = $report;
        }
        $orphan = $this->getMockBuilder(PostReport::class)->disableOriginalConstructor()->onlyMethods(['getTopic'])->getMock();
        $orphan->method('getTopic')->willReturn(null);
        $this->rows[8] = $orphan;
        self::assertSame([4 => $this->rows[4]], $repo->findAllAuthorizedToEdit());
        self::assertFalse($this->lastQuery()->getQuerySettings()->getRespectStoragePage());
    }

    public function testWorkflowInitialResultAndGeneralQueries(): void
    {
        $repo = $this->repository(ReportWorkflowStatusRepository::class);
        foreach ([null, $this->entity(ReportWorkflowStatus::class)] as $first) {
            $this->first = $first;
            self::assertSame($first, $repo->findInitial());
            self::assertSame(1, $this->lastQuery()->getLimit());
            self::assertSame('initial', $this->lastQuery()->getConstraint()->getOperand1()->getPropertyName());
            self::assertTrue($this->lastQuery()->getConstraint()->getOperand2());
            self::assertSame([7,9,0], array_map('intval', $this->lastQuery()->getQuerySettings()->getStoragePageIds()));
        }
    }

    public function testInjectedManagerAndLanguageSettings(): void
    {
        foreach ([ForumRepository::class, TopicRepository::class, PostRepository::class, TagRepository::class, SummaryRepository::class, BBCodeRepository::class] as $class) {
            $repo = $this->repository($class);
            $query = $repo->createQuery();
            self::assertSame($this->lastQuery(), $query);
            self::assertSame(!in_array($class, [ForumRepository::class, TopicRepository::class], true), $query->getQuerySettings()->getRespectSysLanguage());
            $query->getQuerySettings()->setStoragePageIds([999]);
            self::assertNotSame([999], $repo->createQuery()->getQuerySettings()->getStoragePageIds());
        }
    }

    public function testPostTypedFindersRetainQueries(): void
    {
        $repo = $this->repository(PostRepository::class);
        $topic = $this->entity(Topic::class);
        $forum = $this->entity(Forum::class);
        self::assertInstanceOf(QueryResultInterface::class, $repo->findByFilter(6, ['crdate'=>'DESC']));
        self::assertSame(6, $this->lastQuery()->getLimit());
        self::assertSame(['crdate'=>'DESC'], $this->lastQuery()->getOrderings());
        $repo->findByUids([]);
        self::assertNull($this->lastQuery()->getConstraint());
        $repo->findByUids([3,4]);
        self::assertSame([3,4], $this->lastQuery()->getConstraint()->getConstraint1()->getOperand2());
        $repo->findForTopic($topic);
        self::assertSame(1000000, $this->lastQuery()->getLimit());
        self::assertSame(['crdate'=>'ASC'], $this->lastQuery()->getOrderings());
        self::assertFalse($this->lastQuery()->getQuerySettings()->getRespectSysLanguage());
        foreach (['findLastByTopic' => $topic, 'findLastByForum' => $forum] as $method => $argument) {
            foreach ([null, $this->entity(Post::class)] as $first) {
                $this->first = $first;
                self::assertSame($first, $repo->$method($argument, 2));
                self::assertSame(1, $this->lastQuery()->getLimit());
                self::assertSame(2, $this->lastQuery()->getOffset());
                self::assertSame(['crdate'=>'DESC'], $this->lastQuery()->getOrderings());
            }
        }
        self::assertIsArray($this->repository(UserfieldRepository::class)->findAll());
        $topics = $this->repository(TopicRepository::class);
        self::assertInstanceOf(QueryResultInterface::class, $topics->findQuestions(6, false, null));
        self::assertSame(6, $this->lastQuery()->getLimit());
    }

    public function testRepositoryServiceLifecycleConfiguration(): void
    {
        $services = Yaml::parseFile(dirname(__DIR__, 2) . '/Configuration/Services.yaml')['services'];
        $prefix = 'Mittwald\\Typo3Forum\\Domain\\Repository\\';
        $container = new ContainerBuilder();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/Classes/Domain/Repository')) as $file) {
            if ($file->getExtension() !== 'php') { continue; }
            $relative = substr($file->getPathname(), strlen(dirname(__DIR__, 2) . '/Classes/Domain/Repository/'));
            $class = $prefix . str_replace(['/', '.php'], ['\\', ''], $relative);
            if ((new \ReflectionClass($class))->isAbstract()) { continue; }
            $config = array_replace($services['_defaults'], $services[$prefix], $services[$class] ?? []);
            self::assertTrue($config['autowire'], $class);
            self::assertTrue($config['public'], $class);
            $definition = (new Definition($class))->setAutowired(true)->setPublic(true);
            foreach ($config['arguments'] ?? [] as $name => $reference) {
                $definition->setArgument($name, new \Symfony\Component\DependencyInjection\Reference(substr($reference, 1)));
            }
            $container->setDefinition($class, $definition);
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                if (!$method->isConstructor() && !str_starts_with($method->name, 'inject')) { continue; }
                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();
                    if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) { continue; }
                    $dependency = $type->getName();
                    if (!str_starts_with($dependency, $prefix)) {
                        $container->setDefinition($dependency, (new Definition($dependency))->setSynthetic(true)->setPublic(true));
                    }
                }
            }
        }
        (new AutowireInjectMethodsPass())->process($container);
        foreach ($container->getDefinitions() as $class => $definition) {
            if (!str_starts_with($class, $prefix)) { continue; }
            $calls = array_column($definition->getMethodCalls(), 0);
            self::assertContains('injectPersistenceManager', $calls, $class);
            if (is_subclass_of($class, AbstractRepository::class)) {
                self::assertContains('injectConfigurationBuilder', $calls);
                self::assertSame('initializeObject', end($calls));
            }
        }
        $container->compile();
        self::assertTrue($container->isCompiled());
    }
}
