<?php
declare(strict_types=1);
namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Domain\Model\Forum\{Post, Tag};
use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Repository\Forum\TagRepository;
use Mittwald\Typo3Forum\Domain\Repository\User\FrontendUserRepository;
use Mittwald\Typo3Forum\Domain\Validator\Forum\{PostValidator, TagValidator};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\{ContainerBuilder, Definition};
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Reflection\ReflectionService;
use TYPO3\CMS\Extbase\Validation\ValidatorResolver;

final class ValidatorLifecycleTest extends TestCase
{
    public function testResolverUsesNonSharedInjectedValidatorsAndPreservesValidationRules(): void
    {
        $tags = $this->createMock(TagRepository::class);
        $lookups = [];
        $tags->method('findOneByName')->willReturnCallback(function ($name) use (&$lookups) {
            $lookups[] = $name;
            return $name === 'Existing Tag' ? new Tag() : null;
        });
        $users = $this->createStub(FrontendUserRepository::class);
        $user = $this->createStub(FrontendUser::class);
        $user->method('isAnonymous')->willReturn(true);
        $users->method('findCurrent')->willReturn($user);
        $container = new ContainerBuilder();
        foreach ([TagRepository::class, FrontendUserRepository::class] as $class) {
            $container->setDefinition($class, (new Definition($class))->setSynthetic(true)->setPublic(true));
        }
        $services = Yaml::parseFile(dirname(__DIR__, 2) . '/Configuration/Services.yaml')['services'];
        foreach ([TagValidator::class, PostValidator::class] as $class) {
            $config = $services[$class];
            $container->setDefinition($class, (new Definition($class))
                ->setAutowired($config['autowire'])->setPublic($config['public'])->setShared($config['shared']));
        }
        $container->compile();
        $container->set(TagRepository::class, $tags);
        $container->set(FrontendUserRepository::class, $users);
        $property = new \ReflectionProperty(GeneralUtility::class, 'container');
        $previous = $property->getValue();
        GeneralUtility::setContainer($container);
        try {
            $resolver = new ValidatorResolver($this->createStub(ReflectionService::class));
            $validator = $resolver->createValidator(TagValidator::class, [], new ServerRequest());
            self::assertNotSame($validator, $resolver->createValidator(TagValidator::class));
            foreach (['existing tag' => [1373871960], 'unknown tag' => [], ' ' => [1373871955]] as $name => $codes) {
                $tag = new Tag();
                $tag->setName($name);
                self::assertSame($codes, array_map(fn($error) => $error->getCode(), $validator->validate($tag)->getErrors()));
            }
            self::assertSame(['Existing Tag', 'Unknown Tag', ' '], $lookups);
            $validator = $resolver->createValidator(PostValidator::class, [], new ServerRequest());
            foreach ([[' ', '', [1221560718, 1335106565]], ['text', 'ab', [1335106566]], ['text', 'abc', []]] as [$text, $name, $codes]) {
                $post = $this->createStub(Post::class);
                $post->method('getText')->willReturn($text);
                $post->method('getAuthorName')->willReturn($name);
                self::assertSame($codes, array_map(fn($error) => $error->getCode(), $validator->validate($post)->getErrors()));
            }
        } finally {
            $property->setValue(null, $previous);
        }
    }
}
