<?php

declare(strict_types = 1);

require __DIR__ . '/ReleaseBuilder.php';

try {
    if ($argc !== 2) {
        throw new RuntimeException('Usage: ./build-release.sh <version>');
    }
    echo \Typo3Forum\Build\ReleaseBuilder::build(dirname(__DIR__), dirname(__DIR__) . '/dist', $argv[1]) . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
