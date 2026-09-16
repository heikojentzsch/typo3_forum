<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Configuration;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class NotificationEmailConfiguration implements NotificationEmailConfigurationInterface
{
    private const DEFAULTS = [
        'includeForumNameInSubject' => false,
        'includePostText' => false,
        'includeForumLink' => true,
        'includeTopicLink' => true,
        'includeUnsubscribeLink' => true,
    ];

    private ?NotificationEmailOptions $options = null;

    public function __construct(private readonly ExtensionConfiguration $extensionConfiguration)
    {
    }

    public function getOptions(): NotificationEmailOptions
    {
        if ($this->options instanceof NotificationEmailOptions) {
            return $this->options;
        }

        try {
            $extensionConfiguration = $this->extensionConfiguration->get('typo3_forum');
        } catch (ExtensionConfigurationExtensionNotConfiguredException | ExtensionConfigurationPathDoesNotExistException) {
            $extensionConfiguration = [];
        }
        $notifications = is_array($extensionConfiguration)
            && isset($extensionConfiguration['notifications'])
            && is_array($extensionConfiguration['notifications'])
                ? $extensionConfiguration['notifications']
                : [];

        return $this->options = new NotificationEmailOptions(
            $this->booleanValue($notifications['includeForumNameInSubject'] ?? null, self::DEFAULTS['includeForumNameInSubject']),
            $this->booleanValue($notifications['includePostText'] ?? null, self::DEFAULTS['includePostText']),
            $this->booleanValue($notifications['includeForumLink'] ?? null, self::DEFAULTS['includeForumLink']),
            $this->booleanValue($notifications['includeTopicLink'] ?? null, self::DEFAULTS['includeTopicLink']),
            $this->booleanValue($notifications['includeUnsubscribeLink'] ?? null, self::DEFAULTS['includeUnsubscribeLink'])
        );
    }

    private function booleanValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }
        return $default;
    }
}
