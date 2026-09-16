<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Configuration;

interface NotificationEmailConfigurationInterface
{
    public function getOptions(): NotificationEmailOptions;
}
