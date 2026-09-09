<?php

declare(strict_types = 1);

if ($argc !== 3 || !in_array($argv[1], ['create', 'export', 'show'], true)) {
    fwrite(STDERR, "Usage: Credentials.php create|export|show <file>\n");
    exit(2);
}

$mode = $argv[1];
$path = $argv[2];
$directory = dirname($path);

if ($mode === 'create' && !is_file($path)) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create credential directory.');
    }
    $password = static fn (): string => 'Aa1!' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $credentials = [
        'backend' => ['username' => 'forum_admin', 'password' => $password()],
        'member' => ['username' => 'forum_member', 'password' => $password()],
        'moderator' => ['username' => 'forum_moderator', 'password' => $password()],
    ];
    $json = json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $temporaryPath = $path . '.tmp.' . getmypid();
    if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write credentials.');
    }
    chmod($temporaryPath, 0600);
    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Unable to persist credentials atomically.');
    }
}

if (!is_file($path)) {
    throw new RuntimeException('Credential file does not exist.');
}
chmod($path, 0600);
$permissions = fileperms($path);
if (DIRECTORY_SEPARATOR === '/' && ($permissions === false || ($permissions & 0077) !== 0)) {
    throw new RuntimeException('Credential file permissions must be 0600.');
}
$credentials = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
foreach (['backend', 'member', 'moderator'] as $identity) {
    if (!is_string($credentials[$identity]['username'] ?? null) || !is_string($credentials[$identity]['password'] ?? null)) {
        throw new RuntimeException('Credential file is incomplete.');
    }
}

if ($mode === 'export') {
    printf("export TYPO3_SETUP_ADMIN_USERNAME=%s\n", escapeshellarg($credentials['backend']['username']));
    printf("export TYPO3_SETUP_ADMIN_PASSWORD=%s\n", escapeshellarg($credentials['backend']['password']));
    exit(0);
}

if ($mode === 'show') {
    foreach ($credentials as $role => $identity) {
        printf("%s username: %s\n%s password: %s\n", ucfirst($role), $identity['username'], ucfirst($role), $identity['password']);
    }
}
