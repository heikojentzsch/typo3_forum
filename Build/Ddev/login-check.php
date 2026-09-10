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
$moderatorUsername = $credentials['moderator']['username'] ?? null;
if (!is_string($username) || !is_string($password) || !is_string($moderatorUsername)) {
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
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_URL => $baseUrl . '/dashboard',
]);

try {
    $redirectResponse = curl_exec($curl);
    $redirectStatus = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $loginUrl = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    if (!is_string($redirectResponse) || $redirectStatus < 300 || $redirectStatus >= 400 || !is_string($loginUrl)) {
        throw new RuntimeException('Anonymous dashboard request did not redirect to the login page.');
    }
    $loginQuery = [];
    parse_str((string)parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);
    if (parse_url($loginUrl, PHP_URL_PATH) !== '/login'
        || ($loginQuery['redirect_url'] ?? null) !== $baseUrl . '/dashboard'
        || preg_match('/(?:^|[?&])(?:id|sid|session|fe_session)[^=]*=/i', $baseUrl . '/dashboard') === 1) {
        throw new RuntimeException('Login redirect did not preserve a clean dashboard return URL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_URL => $loginUrl,
    ]);
    $html = curl_exec($curl);
    if (!is_string($html) || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
        throw new RuntimeException('Unable to load the redirected frontend login form: ' . curl_error($curl));
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
    if (rtrim((string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL), '/') !== $baseUrl . '/dashboard') {
        throw new RuntimeException('Frontend login did not return to the originally requested dashboard URL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => $baseUrl . '/login',
        CURLOPT_HTTPGET => true,
    ]);
    $authenticatedHtml = curl_exec($curl);
    if (!is_string($authenticatedHtml) || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
        throw new RuntimeException('Unable to verify the authenticated frontend session: ' . curl_error($curl));
    }
    $authenticatedDocument = new DOMDocument();
    @$authenticatedDocument->loadHTML($authenticatedHtml);
    $authenticatedXPath = new DOMXPath($authenticatedDocument);
    $loginForm = $authenticatedXPath->query('//form[.//input[@name="user"] and .//input[@name="pass"]]')?->item(0);
    $logoutField = $authenticatedXPath->query('//input[@name="logintype" and @value="logout"]')?->item(0);
    if ($loginForm instanceof DOMElement
        || (!$logoutField instanceof DOMElement && !str_contains($authenticatedHtml, $username))) {
        throw new RuntimeException('Frontend login completed without recognizable authenticated content.');
    }
    if (($authenticatedXPath->query('//a[contains(@href, "/moderation")]')?->length ?? 0) !== 0) {
        throw new RuntimeException('The moderation page is visible in the member navigation.');
    }

    foreach (['/profile', '/users', '/dashboard', '/tags', '/topics', '/posts', '/statistics', '/forum/topic/welcome-to-the-development-forum'] as $path) {
        curl_setopt($curl, CURLOPT_URL, $baseUrl . $path);
        $pageHtml = curl_exec($curl);
        if (!is_string($pageHtml) || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
            throw new RuntimeException(sprintf('Authenticated frontend page failed: %s%s', $baseUrl, $path));
        }
        if ($path === '/users' && !str_contains($pageHtml, $moderatorUsername)) {
            throw new RuntimeException('The frontend user list does not contain the managed moderator.');
        }
        if ($path === '/statistics'
            && (!str_contains($pageHtml, 'Posts') || !str_contains($pageHtml, 'Topics') || !str_contains($pageHtml, 'Members'))) {
            throw new RuntimeException('The statistics page does not contain the managed summaries.');
        }
    }
} finally {
    curl_close($curl);
    unlink($cookieFile);
}

echo "Frontend member login and authenticated pages passed with a real request token and session cookie.\n";
