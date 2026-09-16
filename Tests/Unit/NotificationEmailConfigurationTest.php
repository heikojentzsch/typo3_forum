<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Configuration\NotificationEmailConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\EventDispatcher\NoopEventDispatcher;
use TYPO3\CMS\Core\TypoScript\AST\AstBuilder;
use TYPO3\CMS\Core\TypoScript\Tokenizer\LossyTokenizer;
use TYPO3\CMS\Core\TypoScript\TypoScriptStringFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class NotificationEmailConfigurationTest extends TestCase
{
    public function testExtensionConfigurationTemplateUsesCoreParserAndDeclaresAllDefaults(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/ext_conf_template.txt');
        self::assertIsString($source);
        $factory = new TypoScriptStringFactory($this->createStub(ContainerInterface::class), new LossyTokenizer());
        $tree = $factory->parseFromString($source, new AstBuilder(new NoopEventDispatcher()));
        $configuration = GeneralUtility::removeDotsFromTS($tree->toArray());

        self::assertSame([
            'includeForumNameInSubject' => '0',
            'includePostText' => '0',
            'includeForumLink' => '1',
            'includeTopicLink' => '1',
            'includeUnsubscribeLink' => '1',
        ], $configuration['notifications']);
    }

    public function testDefaultsAreUsedWhenExtensionConfigurationIsMissing(): void
    {
        $extensionConfiguration = new readonly class () extends ExtensionConfiguration {
            public function get(string $extension, string $path = ''): mixed
            {
                throw new ExtensionConfigurationExtensionNotConfiguredException();
            }
        };

        $options = (new NotificationEmailConfiguration($extensionConfiguration))->getOptions();

        self::assertFalse($options->includeForumNameInSubject);
        self::assertFalse($options->includePostText);
        self::assertTrue($options->includeForumLink);
        self::assertTrue($options->includeTopicLink);
        self::assertTrue($options->includeUnsubscribeLink);
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('storedValueProvider')]
    public function testStoredBooleanRepresentationsAreParsedWithoutChangingOtherOptions(
        array $values,
        bool $forumName,
        bool $postText,
        bool $forumLink,
        bool $topicLink,
        bool $unsubscribeLink
    ): void {
        $extensionConfiguration = new readonly class ($values) extends ExtensionConfiguration {
            /** @param array<string, mixed> $values */
            public function __construct(private array $values)
            {
            }

            public function get(string $extension, string $path = ''): mixed
            {
                return ['notifications' => $this->values];
            }
        };

        $options = (new NotificationEmailConfiguration($extensionConfiguration))->getOptions();

        self::assertSame($forumName, $options->includeForumNameInSubject);
        self::assertSame($postText, $options->includePostText);
        self::assertSame($forumLink, $options->includeForumLink);
        self::assertSame($topicLink, $options->includeTopicLink);
        self::assertSame($unsubscribeLink, $options->includeUnsubscribeLink);
    }

    public static function storedValueProvider(): iterable
    {
        yield 'strings' => [[
            'includeForumNameInSubject' => '1',
            'includePostText' => '0',
            'includeForumLink' => 'false',
            'includeTopicLink' => 'true',
            'includeUnsubscribeLink' => 'off',
        ], true, false, false, true, false];
        yield 'integers' => [[
            'includeForumNameInSubject' => 1,
            'includePostText' => 1,
            'includeForumLink' => 0,
            'includeTopicLink' => 0,
            'includeUnsubscribeLink' => 1,
        ], true, true, false, false, true];
        yield 'booleans' => [[
            'includeForumNameInSubject' => false,
            'includePostText' => true,
            'includeForumLink' => false,
            'includeTopicLink' => true,
            'includeUnsubscribeLink' => false,
        ], false, true, false, true, false];
        yield 'invalid values use safe defaults' => [[
            'includeForumNameInSubject' => 'perhaps',
            'includePostText' => ['unexpected'],
            'includeForumLink' => 2,
            'includeTopicLink' => new \stdClass(),
            'includeUnsubscribeLink' => null,
        ], false, false, true, true, true];
        yield 'one changed option is independent' => [[
            'includeForumLink' => false,
        ], false, false, false, true, true];
    }
}
