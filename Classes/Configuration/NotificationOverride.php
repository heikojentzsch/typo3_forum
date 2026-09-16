<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Configuration;

final class NotificationOverride
{
    public const INHERIT = 0;
    public const ENABLED = 1;
    public const DISABLED = 2;

    private function __construct()
    {
    }
}
