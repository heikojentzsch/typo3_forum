<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Configuration\NotificationConfigurationException;
use Mittwald\Typo3Forum\Configuration\NotificationConfigurationResolver;
use Mittwald\Typo3Forum\Configuration\NotificationEmailConfigurationInterface;
use Mittwald\Typo3Forum\Configuration\NotificationEmailOptions;
use Mittwald\Typo3Forum\Configuration\NotificationOverride;
use Mittwald\Typo3Forum\Domain\Model\Forum\Forum;
use PHPUnit\Framework\TestCase;

final class NotificationConfigurationResolverTest extends TestCase
{
    public function testNoOverridesUsesGlobalDefaultsAndNewForumDefaultsToInherit(): void
    {
        $forum = $this->forum(1);
        self::assertSame(NotificationOverride::INHERIT, $forum->getNotificationIncludeForumNameInSubject());
        self::assertSame(NotificationOverride::INHERIT, $forum->getNotificationIncludePostText());
        self::assertSame(NotificationOverride::INHERIT, $forum->getNotificationIncludeForumLink());
        self::assertSame(NotificationOverride::INHERIT, $forum->getNotificationIncludeTopicLink());
        self::assertSame(NotificationOverride::INHERIT, $forum->getNotificationIncludeUnsubscribeLink());

        $options = $this->resolver(new NotificationEmailOptions(false, false, true, true, true))->resolve($forum);
        self::assertSame([false, false, true, true, true], $this->values($options));
    }

    public function testDirectChildOverrideWins(): void
    {
        $child = $this->forum(3)->setNotificationIncludePostText(NotificationOverride::ENABLED);
        $parent = $this->forum(2)->setNotificationIncludePostText(NotificationOverride::DISABLED);
        $child->setParent($parent);

        $options = $this->resolver(new NotificationEmailOptions(false, false, true, true, true))->resolve($child);
        self::assertTrue($options->includePostText);
    }

    public function testParentValueWinsWhenChildInherits(): void
    {
        $child = $this->forum(3);
        $parent = $this->forum(2)->setNotificationIncludeForumLink(NotificationOverride::DISABLED);
        $child->setParent($parent);

        self::assertFalse($this->defaultsResolver()->resolve($child)->includeForumLink);
    }

    public function testRootValueWinsWhenChildAndParentInherit(): void
    {
        $child = $this->forum(3);
        $parent = $this->forum(2);
        $root = $this->forum(1)->setNotificationIncludeForumNameInSubject(NotificationOverride::ENABLED);
        $child->setParent($parent);
        $parent->setParent($root);

        self::assertTrue($this->defaultsResolver()->resolve($child)->includeForumNameInSubject);
    }

    public function testNearestExplicitValueWins(): void
    {
        $child = $this->forum(3);
        $parent = $this->forum(2)->setNotificationIncludePostText(NotificationOverride::DISABLED);
        $root = $this->forum(1)->setNotificationIncludePostText(NotificationOverride::ENABLED);
        $child->setParent($parent);
        $parent->setParent($root);

        self::assertFalse($this->defaultsResolver()->resolve($child)->includePostText);
    }

    public function testEachSettingResolvesIndependentlyAcrossTheHierarchy(): void
    {
        $child = $this->forum(3)->setNotificationIncludePostText(NotificationOverride::DISABLED);
        $parent = $this->forum(2)
            ->setNotificationIncludeForumLink(NotificationOverride::DISABLED)
            ->setNotificationIncludeTopicLink(NotificationOverride::ENABLED);
        $root = $this->forum(1)->setNotificationIncludeForumNameInSubject(NotificationOverride::ENABLED);
        $child->setParent($parent);
        $parent->setParent($root);

        $options = $this->defaultsResolver()->resolve($child);
        self::assertSame([true, false, false, true, true], $this->values($options));
    }

    public function testSelfParentCycleFailsDiagnostically(): void
    {
        $forum = $this->forum(1);
        $forum->setParent($forum);

        $this->expectException(NotificationConfigurationException::class);
        $this->expectExceptionMessage('Cycle detected');
        $this->defaultsResolver()->resolve($forum);
    }

    public function testTwoForumCycleFailsDiagnostically(): void
    {
        $a = $this->forum(1);
        $b = $this->forum(2);
        $a->setParent($b);
        $b->setParent($a);

        $this->expectException(NotificationConfigurationException::class);
        $this->expectExceptionMessage('Cycle detected');
        $this->defaultsResolver()->resolve($a);
    }

    public function testDeepValidHierarchyResolvesWithoutDepthLimit(): void
    {
        $current = $this->forum(500);
        $contentForum = $current;
        for ($uid = 499; $uid >= 1; --$uid) {
            $parent = $this->forum($uid);
            $current->setParent($parent);
            $current = $parent;
        }
        $current->setNotificationIncludeTopicLink(NotificationOverride::DISABLED);

        self::assertFalse($this->defaultsResolver()->resolve($contentForum)->includeTopicLink);
    }

    public function testInvalidPersistedValueCannotEnablePostText(): void
    {
        $forum = $this->forum(1)->setNotificationIncludePostText(99);

        $this->expectException(NotificationConfigurationException::class);
        $this->expectExceptionMessage('Invalid notification override 99 for includePostText');
        $this->resolver(new NotificationEmailOptions(false, true, true, true, true))->resolve($forum);
    }

    private function defaultsResolver(): NotificationConfigurationResolver
    {
        return $this->resolver(new NotificationEmailOptions(false, false, true, true, true));
    }

    private function resolver(NotificationEmailOptions $defaults): NotificationConfigurationResolver
    {
        $configuration = new class ($defaults) implements NotificationEmailConfigurationInterface {
            public function __construct(private readonly NotificationEmailOptions $defaults)
            {
            }

            public function getOptions(): NotificationEmailOptions
            {
                return $this->defaults;
            }
        };
        return new NotificationConfigurationResolver($configuration);
    }

    private function forum(int $uid): Forum
    {
        $forum = (new \ReflectionClass(Forum::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Forum::class, 'uid'))->setValue($forum, $uid);
        return $forum;
    }

    /** @return list<bool> */
    private function values(NotificationEmailOptions $options): array
    {
        return [
            $options->includeForumNameInSubject,
            $options->includePostText,
            $options->includeForumLink,
            $options->includeTopicLink,
            $options->includeUnsubscribeLink,
        ];
    }
}
