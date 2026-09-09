<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DdevBootstrapTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/typo3-forum-ddev-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->temporaryDirectory);
    }

    public function testCredentialsAreGeneratedOnceOutsideTheDocumentRoot(): void
    {
        $script = dirname(__DIR__, 2) . '/Build/Ddev/Credentials.php';
        $path = $this->temporaryDirectory . '/.bootstrap/credentials.json';
        $this->runPhp($script, 'create', $path);
        $first = file_get_contents($path);
        self::assertIsString($first);
        $credentials = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('forum_admin', $credentials['backend']['username']);
        self::assertSame('forum_member', $credentials['member']['username']);
        self::assertSame('forum_moderator', $credentials['moderator']['username']);
        self::assertGreaterThanOrEqual(32, strlen($credentials['backend']['password']));
        self::assertStringNotContainsString('/public/', str_replace('\\', '/', $path));

        $this->runPhp($script, 'create', $path);
        self::assertSame($first, file_get_contents($path), 'A normal rerun must not rotate credentials.');
    }

    public function testLocalPackageVerifierUsesTheProvidedCanonicalPaths(): void
    {
        $script = dirname(__DIR__, 2) . '/Build/Ddev/verify-local-package.php';
        mkdir($this->temporaryDirectory . '/nested');

        $this->runPhp(
            $script,
            $this->temporaryDirectory,
            $this->temporaryDirectory . '/nested/..'
        );

        $wrapper = (string)file_get_contents(dirname(__DIR__, 2) . '/Build/setup-ddev.sh');
        self::assertStringContainsString(
            '/var/www/html/packages/typo3_forum /var/www/html/vendor/pottkinder/typo3forum',
            $wrapper
        );
    }

    public function testFixtureVerifierAcceptsTheComposerSupportedTypo3Range(): void
    {
        $verifierPath = dirname(__DIR__, 2) . '/ddev/Packages/typo3_forum_dev/Classes/FixtureVerifier.php';
        require_once $verifierPath;

        foreach (['14.3.0', '14.3.99', '14.4.0', '14.99.0'] as $supportedVersion) {
            self::assertTrue(
                \Pottkinder\Typo3ForumDev\FixtureVerifier::supportsTypo3Version($supportedVersion),
                $supportedVersion
            );
        }
        foreach (['14.2.99', '15.0.0'] as $unsupportedVersion) {
            self::assertFalse(
                \Pottkinder\Typo3ForumDev\FixtureVerifier::supportsTypo3Version($unsupportedVersion),
                $unsupportedVersion
            );
        }

        $verifier = (string)file_get_contents($verifierPath);
        self::assertStringContainsString('Typo3Version $typo3Version', $verifier);
        self::assertStringContainsString('$this->typo3Version->getVersion()', $verifier);
        self::assertStringNotContainsString("defined('TYPO3_version')", $verifier);
    }

    public function testDdevPrimaryHostIsConfiguredAsAnExactTrustedHost(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/ddev/Packages/typo3_forum_dev/Classes/DevelopmentGuard.php';

        self::assertSame(
            '^typo3forum\\.ddev\\.site$',
            \Pottkinder\Typo3ForumDev\DevelopmentGuard::trustedHostsPattern('https://typo3forum.ddev.site')
        );

        $provisioner = (string)file_get_contents($root . '/ddev/Packages/typo3_forum_dev/Classes/FixtureProvisioner.php');
        $verifier = (string)file_get_contents($root . '/ddev/Packages/typo3_forum_dev/Classes/FixtureVerifier.php');
        self::assertStringContainsString("['SYS']['trustedHostsPattern']", $provisioner);
        self::assertStringContainsString("['SYS']['trustedHostsPattern']", $verifier);
    }

    public function testWrongSocketRepairPreservesUnrelatedSettingsAndCreatesBackup(): void
    {
        $configurationDirectory = $this->temporaryDirectory . '/config/system';
        mkdir($configurationDirectory, 0700, true);
        $path = $configurationDirectory . '/settings.php';
        file_put_contents($path, <<<'PHP'
<?php
return [
    'DB' => ['Connections' => ['Default' => ['driver' => 'pdo_mysql', 'host' => 'localhost', 'unix_socket' => '/tmp/mysql.sock']]],
    'SYS' => ['trustedHostsPattern' => 'keep-me'],
];
PHP
        );
        $script = dirname(__DIR__, 2) . '/Build/Ddev/repair-local-settings.php';
        $this->runPhp($script, 'inspect', $path);
        $settings = require $path;
        self::assertSame('mysqli', $settings['DB']['Connections']['Default']['driver']);
        self::assertSame('db', $settings['DB']['Connections']['Default']['host']);
        self::assertSame(3306, $settings['DB']['Connections']['Default']['port']);
        self::assertArrayNotHasKey('unix_socket', $settings['DB']['Connections']['Default']);
        self::assertSame('keep-me', $settings['SYS']['trustedHostsPattern']);
        self::assertCount(1, glob($this->temporaryDirectory . '/.bootstrap/backups/settings.php.*.bak'));
    }

    public function testFreshSetupPreparationBacksUpRecognizedConfiguration(): void
    {
        $configurationDirectory = $this->temporaryDirectory . '/config/system';
        mkdir($configurationDirectory, 0700, true);
        $path = $configurationDirectory . '/settings.php';
        file_put_contents($path, "<?php\nreturn ['SYS' => ['trustedHostsPattern' => 'keep-me']];\n");

        $script = dirname(__DIR__, 2) . '/Build/Ddev/repair-local-settings.php';
        $this->runPhp($script, 'prepare', $path);
        $settings = require $path;
        self::assertSame('mysqli', $settings['DB']['Connections']['Default']['driver']);
        self::assertSame('db', $settings['DB']['Connections']['Default']['host']);
        self::assertSame('keep-me', $settings['SYS']['trustedHostsPattern']);
        self::assertCount(1, glob($this->temporaryDirectory . '/.bootstrap/backups/settings.php.*.bak'));
    }

    public function testBootstrapContractsAreExplicitAndDevelopmentOnly(): void
    {
        $root = dirname(__DIR__, 2);
        $wrapper = (string)file_get_contents($root . '/Build/setup-ddev.sh');
        self::assertStringContainsString('docker info', $wrapper);
        self::assertStringContainsString('vendor/bin/typo3 setup --no-interaction --force', $wrapper);
        self::assertStringContainsString('TYPO3_DB_DRIVER=mysqli', $wrapper);
        self::assertStringContainsString('TYPO3_DB_HOST=db', $wrapper);
        self::assertStringContainsString('forum-dev:check', $wrapper);
        self::assertStringContainsString('extension:setup --extension=typo3_forum_dev', $wrapper);
        self::assertStringContainsString('repair-local-settings.php prepare', $wrapper);
        self::assertStringContainsString('ddev launch --mailpit --print-url', $wrapper);
        self::assertStringNotContainsString('DDEV_MAILPIT_HTTPS_PORT', $wrapper);
        self::assertStringNotContainsString('git reset', $wrapper);
        self::assertStringNotContainsString('ddev delete', $wrapper);

        $guard = (string)file_get_contents($root . '/ddev/Packages/typo3_forum_dev/Classes/DevelopmentGuard.php');
        self::assertStringContainsString("Environment::getContext()->isDevelopment()", $guard);
        self::assertStringContainsString("getenv('DDEV_PROJECT') !== 'typo3forum'", $guard);
        self::assertStringContainsString("'host' => 'db'", $guard);
        self::assertStringContainsString("'dbname' => 'db'", $guard);

        $ddevConfiguration = (string)file_get_contents($root . '/ddev/.ddev/config.yaml');
        self::assertStringContainsString('disable_settings_management: true', $ddevConfiguration);
    }

    public function testFixtureUsesCurrentContentTypesAndTracksOwnership(): void
    {
        $root = dirname(__DIR__, 2);
        $provisioner = (string)file_get_contents($root . '/ddev/Packages/typo3_forum_dev/Classes/FixtureProvisioner.php');
        foreach (['typo3forum_forum', 'typo3forum_userprofile', 'typo3forum_moderationreports',
            'typo3forum_userlist', 'typo3forum_dashboard', 'typo3forum_taglist', 'typo3forum_postlist',
            'typo3forum_topiclist', 'typo3forum_statsbox'] as $contentType) {
            self::assertStringContainsString("'{$contentType}'", $provisioner);
        }
        self::assertStringContainsString('DDEV-FORUM-SAMPLE', $provisioner);
        self::assertStringContainsString('PasswordHashFactory', $provisioner);
        self::assertStringContainsString('tx_typo3forum_domain_model_forum_access', $provisioner);
        self::assertStringContainsString('styles.content.loginform.pid = %d', $provisioner);
        self::assertStringNotContainsString('plugin.tx_felogin_login.settings.pages', $provisioner);
        self::assertStringContainsString("fetchOne('SELECT constants FROM sys_template WHERE uid = ?'", $provisioner);

        $schema = (string)file_get_contents($root . '/ddev/Packages/typo3_forum_dev/ext_tables.sql');
        self::assertStringContainsString('UNIQUE KEY logical_key', $schema);
        self::assertStringContainsString('UNIQUE KEY phase', $schema);
    }

    public function testGeneratedAndDevelopmentFilesAreIgnoredAndOutsideReleaseAllowlist(): void
    {
        $root = dirname(__DIR__, 2);
        $ignore = (string)file_get_contents($root . '/ddev/.gitignore');
        self::assertStringContainsString('/.bootstrap/', $ignore);
        self::assertStringContainsString('/config/system/', $ignore);
        self::assertStringContainsString('/config/sites/forum-dev/config.yaml', $ignore);

        $releaseBuilder = (string)file_get_contents($root . '/Build/ReleaseBuilder.php');
        self::assertStringNotContainsString("'ddev'", $releaseBuilder);
        self::assertStringNotContainsString("'Build'", $releaseBuilder);
    }

    private function runPhp(string ...$arguments): void
    {
        $command = array_merge([PHP_BINARY], $arguments);
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), trim($stdout . "\n" . $stderr));
    }
}
