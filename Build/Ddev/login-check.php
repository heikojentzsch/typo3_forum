<?php

declare(strict_types = 1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: login-check.php <base-url> <credentials-file>\n");
    exit(2);
}

$baseUrl = rtrim($argv[1], '/');
$credentials = json_decode((string)file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$username = $credentials['member']['username'] ?? null;
$password = $credentials['member']['password'] ?? null;
if (!is_string($username) || !is_string($password)) {
    throw new RuntimeException('Member credentials are unavailable.');
}

$cookieFile = tempnam(sys_get_temp_dir(), 'forum-login-cookie-');
if ($cookieFile === false) {
    throw new RuntimeException('Unable to create a temporary cookie jar.');
}

$curl = curl_init();
if ($curl === false) {
    throw new RuntimeException('Unable to initialize cURL.');
}
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_URL => $baseUrl . '/login',
]);

try {
    $html = curl_exec($curl);
    if (!is_string($html) || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
        throw new RuntimeException('Unable to load the frontend login form: ' . curl_error($curl));
    }

    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $forms = $xpath->query('//form[.//input[@name="user"] and .//input[@name="pass"]]');
    $form = $forms?->item(0);
    if (!$form instanceof DOMElement) {
        throw new RuntimeException('No frontend login form was found.');
    }

    $fields = [];
    foreach ($xpath->query('.//input[@name]', $form) ?: [] as $input) {
        if ($input instanceof DOMElement) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
    }
    $fields['user'] = $username;
    $fields['pass'] = $password;
    $fields['logintype'] = 'login';
    $action = html_entity_decode($form->getAttribute('action'));
    if ($action === '') {
        $action = $baseUrl . '/login';
    } elseif (!str_starts_with($action, 'http://') && !str_starts_with($action, 'https://')) {
        $action = $baseUrl . '/' . ltrim($action, '/');
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => $action,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);
    $response = curl_exec($curl);
    if (!is_string($response) || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) >= 400) {
        throw new RuntimeException('Frontend login request failed: ' . curl_error($curl));
    }
    if (!str_contains(strtolower($response), 'logout') && !str_contains($response, $username)) {
        throw new RuntimeException('Frontend login completed without recognizable authenticated content.');
    }
} finally {
    curl_close($curl);
    unlink($cookieFile);
}

echo "Frontend member login passed with a real request token and session cookie.\n";
