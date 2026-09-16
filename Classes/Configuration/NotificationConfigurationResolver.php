<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Configuration;

use Mittwald\Typo3Forum\Domain\Model\Forum\Forum;
use TYPO3\CMS\Extbase\Persistence\Generic\LazyLoadingProxy;

final class NotificationConfigurationResolver
{
    private const GETTERS = [
        'includeForumNameInSubject' => 'getNotificationIncludeForumNameInSubject',
        'includePostText' => 'getNotificationIncludePostText',
        'includeForumLink' => 'getNotificationIncludeForumLink',
        'includeTopicLink' => 'getNotificationIncludeTopicLink',
        'includeUnsubscribeLink' => 'getNotificationIncludeUnsubscribeLink',
    ];

    public function __construct(
        private readonly NotificationEmailConfigurationInterface $globalConfiguration
    ) {
    }

    public function resolve(Forum $contentForum): NotificationEmailOptions
    {
        $resolved = [];
        $visited = [];
        $current = $contentForum;

        while ($current instanceof Forum && count($resolved) < count(self::GETTERS)) {
            $identity = $current->getUid() > 0
                ? 'uid:' . $current->getUid()
                : 'object:' . spl_object_id($current);
            if (isset($visited[$identity])) {
                throw new NotificationConfigurationException(
                    'Cycle detected while resolving notification configuration at forum ' . $identity,
                    1799991001
                );
            }
            $visited[$identity] = true;

            foreach (self::GETTERS as $setting => $getter) {
                if (array_key_exists($setting, $resolved)) {
                    continue;
                }
                $override = $current->$getter();
                if ($override === NotificationOverride::ENABLED) {
                    $resolved[$setting] = true;
                } elseif ($override === NotificationOverride::DISABLED) {
                    $resolved[$setting] = false;
                } elseif ($override !== NotificationOverride::INHERIT) {
                    throw new NotificationConfigurationException(
                        sprintf('Invalid notification override %d for %s at forum %s', $override, $setting, $identity),
                        1799991002
                    );
                }
            }

            $parent = $current->getParent();
            if ($parent instanceof LazyLoadingProxy) {
                $parent = $parent->_loadRealInstance();
            }
            if ($parent !== null && !$parent instanceof Forum) {
                throw new NotificationConfigurationException(
                    'The parent of forum ' . $identity . ' is not a forum.',
                    1799991003
                );
            }
            $current = $parent;
        }

        $defaults = $this->globalConfiguration->getOptions();
        return new NotificationEmailOptions(
            $resolved['includeForumNameInSubject'] ?? $defaults->includeForumNameInSubject,
            $resolved['includePostText'] ?? $defaults->includePostText,
            $resolved['includeForumLink'] ?? $defaults->includeForumLink,
            $resolved['includeTopicLink'] ?? $defaults->includeTopicLink,
            $resolved['includeUnsubscribeLink'] ?? $defaults->includeUnsubscribeLink
        );
    }
}
