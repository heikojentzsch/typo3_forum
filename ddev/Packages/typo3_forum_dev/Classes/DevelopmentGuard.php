<?php

declare(strict_types=1);

namespace Pottkinder\Typo3ForumDev;

use RuntimeException;
use TYPO3\CMS\Core\Core\Environment;

final class DevelopmentGuard
{
    public static function trustedHostsPattern(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new RuntimeException('DDEV_PRIMARY_URL does not contain a valid host.');
        }

        return '^' . preg_quote($host, '/') . '$';
    }

    public function assertSafe(): void
    {
        if (!Environment::getContext()->isDevelopment()) {
            throw new RuntimeException('Fixture provisioning is restricted to a TYPO3 Development context.');
        }
        if (getenv('IS_DDEV_PROJECT') !== 'true' || getenv('DDEV_PROJECT') !== 'typo3forum') {
            throw new RuntimeException('Fixture provisioning is restricted to the typo3forum DDEV project.');
        }

        $connection = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [];
        $expected = ['driver' => 'mysqli', 'host' => 'db', 'port' => 3306, 'dbname' => 'db', 'user' => 'db'];
        foreach ($expected as $key => $value) {
            if (($connection[$key] ?? null) !== $value) {
                throw new RuntimeException(sprintf('Unexpected database setting %s; refusing fixture access.', $key));
            }
        }
        if (isset($connection['unix_socket'])) {
            throw new RuntimeException('A Unix socket database connection is not permitted in this DDEV fixture.');
        }
    }
}
