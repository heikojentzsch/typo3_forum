<?php

declare(strict_types = 1);

$root = dirname(__DIR__);
$files = glob($root . '/*.php') ?: [];
$files[] = $root . '/.php-cs-fixer.php';
foreach (['Classes', 'Configuration', 'Tests', 'Build', 'Resources/Private/Migration', 'ddev/Packages/typo3_forum_dev'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files, SORT_STRING);
$failed = false;
foreach (array_unique($files) as $file) {
    $process = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start PHP syntax checker.');
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        fwrite(STDERR, $output);
        $failed = true;
    }
}
printf("PHP syntax: %d files checked.\n", count(array_unique($files)));
exit($failed ? 1 : 0);
