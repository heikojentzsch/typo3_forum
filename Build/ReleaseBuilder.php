<?php

declare(strict_types=1);

namespace Typo3Forum\Build;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ReleaseBuilder
{
    public static function build(string $root, string $output, string $version): string
    {
        if (!preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $version)) {
            throw new RuntimeException('Supply a semantic version, for example 14.0.0 or 14.0.0-test.');
        }
        $prerelease = explode('-', explode('+', $version)[0], 2)[1] ?? '';
        foreach (explode('.', $prerelease) as $identifier) {
            if (ctype_digit($identifier) && strlen($identifier) > 1 && $identifier[0] === '0') {
                throw new RuntimeException('Numeric prerelease identifiers must not have leading zeroes.');
            }
        }
        $files = ['composer.json', 'ext_emconf.php', 'ext_localconf.php', 'ext_tables.sql', 'LICENSE.txt', 'README.md'];
        foreach (['ext_tables.php', 'ext_tables_static+adt.sql', 'ext_icon.gif'] as $optional) {
            if (is_file($root . '/' . $optional)) {
                $files[] = $optional;
            }
        }
        foreach (['Classes', 'Configuration', 'Resources', 'Documentation'] as $directory) {
            if (!is_dir($root . '/' . $directory) || is_link($root . '/' . $directory)) {
                throw new RuntimeException('Missing or linked runtime directory: ' . $directory);
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new RuntimeException('Release sources must not contain symlinks: ' . $file->getPathname());
                }
                if ($file->isFile()) {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        sort($files, SORT_STRING);
        $temporary = sys_get_temp_dir() . '/typo3-forum-release-' . bin2hex(random_bytes(12));
        if (!mkdir($temporary, 0700)) {
            throw new RuntimeException('Cannot create release staging directory.');
        }
        $archive = $temporary . '/package.zip';
        try {
            $zip = new ZipArchive();
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Cannot create release archive.');
            }
            try {
                foreach ($files as $relative) {
                    $path = $root . '/' . $relative;
                    if (!is_file($path) || is_link($path)) {
                        throw new RuntimeException('Missing or linked runtime file: ' . $relative);
                    }
                    $contents = file_get_contents($path);
                    if ($contents === false || !$zip->addFromString($relative, $contents)
                        || !$zip->setCompressionName($relative, ZipArchive::CM_STORE)
                        || !$zip->setMtimeName($relative, 315532800)
                        || !$zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
                        throw new RuntimeException('Cannot package: ' . $relative);
                    }
                }
            } finally {
                if (!$zip->close()) {
                    throw new RuntimeException('Cannot finalize release archive.');
                }
            }
            if (!is_dir($output) && !mkdir($output, 0777, true)) {
                throw new RuntimeException('Cannot create output directory.');
            }
            $name = 'typo3_forum_' . $version . '.zip';
            $destination = $output . '/' . $name;
            if (!copy($archive, $destination)
                || file_put_contents($destination . '.sha256', hash_file('sha256', $archive) . '  ' . $name . "\n") === false) {
                throw new RuntimeException('Cannot write release artifacts.');
            }
            return $destination;
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
            rmdir($temporary);
        }
    }
}
