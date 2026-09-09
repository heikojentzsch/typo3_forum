<?php

declare(strict_types = 1);

if ($argc !== 3 || !in_array($argv[1], ['inspect', 'prepare', 'verify'], true)) {
    fwrite(STDERR, "Usage: repair-local-settings.php inspect|prepare|verify <settings.php>\n");
    exit(2);
}

$path = $argv[2];
if (!is_file($path)) {
    exit(0);
}

$settings = require $path;
$connection = $settings['DB']['Connections']['Default'] ?? [];
$missingConnection = $connection === [];
$wrongSocket = ($connection['driver'] ?? '') === 'pdo_mysql'
    || ($connection['host'] ?? '') === 'localhost'
    || isset($connection['unix_socket']);

if (!$wrongSocket && !$missingConnection) {
    foreach (['host' => 'db', 'port' => 3306, 'dbname' => 'db', 'user' => 'db'] as $key => $value) {
        if (($connection[$key] ?? null) !== $value) {
            fwrite(STDERR, "Unexpected database setting {$key}; expected the DDEV database target.\n");
            exit(1);
        }
    }
    if ($argv[1] !== 'prepare') {
        exit(0);
    }
}

if ($argv[1] === 'verify') {
    fwrite(STDERR, "Existing TYPO3 installation has no verified DDEV TCP database connection.\n");
    exit(1);
}

$backupDirectory = dirname(dirname(dirname($path))) . '/.bootstrap/backups';
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Unable to create settings backup directory.');
}
$backup = $backupDirectory . '/settings.php.' . gmdate('YmdHis') . '.' . bin2hex(random_bytes(4)) . '.bak';
if (!copy($path, $backup)) {
    throw new RuntimeException('Unable to back up settings.php.');
}

if ($wrongSocket || $missingConnection) {
    unset($connection['unix_socket']);
    $connection['driver'] = 'mysqli';
    $connection['host'] = 'db';
    $connection['port'] = 3306;
    $connection['dbname'] = 'db';
    $connection['user'] = 'db';
    $connection['password'] = 'db';
    $settings['DB']['Connections']['Default'] = $connection;
    $contents = "<?php\n\nreturn " . var_export($settings, true) . ";\n";
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Unable to repair settings.php.');
    }
}
echo "Backed up and prepared the recognized local DDEV database configuration.\n";
