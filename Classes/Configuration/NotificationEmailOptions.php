<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Configuration;

final readonly class NotificationEmailOptions
{
    public function __construct(
        public bool $includeForumNameInSubject,
        public bool $includePostText,
        public bool $includeForumLink,
        public bool $includeTopicLink,
        public bool $includeUnsubscribeLink
    ) {
    }
}
