<?php

declare(strict_types=1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Typo3Forum\Build\ReleaseBuilder;

require_once dirname(__DIR__, 2) . '/Build/ReleaseBuilder.php';

final class ReleaseBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/forum-package-test-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        foreach (['Classes/test.php', 'Configuration/test.php', 'Resources/test.txt', 'Documentation/test.rst',
            'composer.json', 'ext_emconf.php', 'ext_localconf.php', 'ext_tables.sql', 'LICENSE.txt', 'README.md',
            '.git/config', '.github/workflows/ci.yml', '.Build/vendor/test.php', 'Tests/test.php', 'ddev/test.yml',
            'build-release.sh', '.gitlab-ci.yml', 'phpstan.neon'] as $file) {
            $directory = dirname($this->root . '/' . $file);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($this->root . '/' . $file, $file . "\n");
        }
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testRuntimeContentsChecksumsAndSourcesAreReproducible(): void
    {
        $before = file_get_contents($this->root . '/ext_emconf.php');
        $first = ReleaseBuilder::build($this->root, $this->root . '/first', '14.0.0-test');
        touch($this->root . '/Classes/test.php', 1700000000);
        $second = ReleaseBuilder::build($this->root, $this->root . '/second', '14.0.0-test');
        self::assertSame(hash_file('sha256', $first), hash_file('sha256', $second));
        self::assertSame(hash_file('sha256', $first) . '  ' . basename($first) . "\n", file_get_contents($first . '.sha256'));
        self::assertSame($before, file_get_contents($this->root . '/ext_emconf.php'));
        self::assertSame("composer.json\n", file_get_contents($this->root . '/composer.json'));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($first));
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = $zip->getNameIndex($index);
        }
        $zip->close();
        self::assertSame(['Classes/test.php', 'Configuration/test.php', 'Documentation/test.rst', 'LICENSE.txt', 'README.md', 'Resources/test.txt', 'composer.json', 'ext_emconf.php', 'ext_localconf.php', 'ext_tables.sql'], $names);
    }

    public static function invalidVersions(): iterable
    {
        foreach (['', '../escape', '1.2', '01.2.3', '1.2.3-01', '1.2.3/escape'] as $version) {
            yield [$version];
        }
    }

    #[DataProvider('invalidVersions')]
    public function testInvalidVersionsAreRejected(string $version): void
    {
        $this->expectException(\RuntimeException::class);
        ReleaseBuilder::build($this->root, $this->root . '/dist', $version);
    }

    public function testMissingRuntimeMetadataFails(): void
    {
        unlink($this->root . '/composer.json');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing or linked runtime file: composer.json');
        ReleaseBuilder::build($this->root, $this->root . '/dist', '14.0.0');
    }
}
