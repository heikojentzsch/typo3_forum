<?php

declare(strict_types = 1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: verify-local-package.php <checkout-path> <installed-path>\n");
    exit(2);
}

$packagePath = realpath($argv[1]);
$installedPath = realpath($argv[2]);

if ($packagePath === false || $installedPath === false || $packagePath !== $installedPath) {
    fwrite(STDERR, "Composer did not resolve pottkinder/typo3forum to the mounted checkout.\n");
    exit(1);
}

echo "Local forum package resolves to the checked-out source.\n";
