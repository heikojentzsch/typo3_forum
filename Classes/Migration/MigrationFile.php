<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Migration;

use RuntimeException;

final class MigrationFile
{
    /** @return array<string, mixed> */
    public function read(string $path, string $format): array
    {
        $permissions = @fileperms($path);
        if (DIRECTORY_SEPARATOR === '/' && $permissions !== false && ($permissions & 0o077) !== 0) {
            throw new RuntimeException('Migration files must not be accessible by group or other users (expected mode 0600).');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read migration file: ' . $path);
        }
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['manifest_format'] ?? null) !== $format) {
            throw new RuntimeException('Unexpected migration file format.');
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    public function write(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('The selected output directory must already exist and be writable.');
        }
        $temporary = tempnam($directory, '.forum-migration-');
        if ($temporary === false || !chmod($temporary, 0600)) {
            throw new RuntimeException('Cannot create protected migration output.');
        }
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (file_put_contents($temporary, $json) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write migration output.');
        }
        chmod($path, 0600);
    }
}
