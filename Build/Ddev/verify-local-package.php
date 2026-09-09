<?php

declare(strict_types = 1);

$packagePath = realpath(__DIR__ . '/../../');
$installedPath = realpath(__DIR__ . '/../../ddev/vendor/pottkinder/typo3forum');

if ($packagePath === false || $installedPath === false || $packagePath !== $installedPath) {
    fwrite(STDERR, "Composer did not resolve pottkinder/typo3forum to the mounted checkout.\n");
    exit(1);
}

echo "Local forum package resolves to the checked-out source.\n";
