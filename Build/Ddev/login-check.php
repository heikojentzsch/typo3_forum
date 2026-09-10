<?php

declare(strict_types = 1);

/** @return array{0: CurlHandle, 1: string} */
function createClient(): array
{
    $cookieFile = tempnam(sys_get_temp_dir(), 'forum-login-cookie-');
    if ($cookieFile === false) {
        throw new RuntimeException('Unable to create a temporary cookie jar.');
    }

    $curl = curl_init();
    if ($curl === false) {
        unlink($cookieFile);
        throw new RuntimeException('Unable to initialize cURL.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);

    return [$curl, $cookieFile];
}

function authenticate(CurlHandle $curl, string $baseUrl, string $loginUrl, string $username, string $password): void
{
    curl_setopt_array($curl, [CURLOPT_URL => $loginUrl, CURLOPT_HTTPGET => true]);
    $html = curl_exec($curl);
    if (!is_string($html) || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
        throw new RuntimeException('Unable to load the frontend login form: ' . curl_error($curl));
    }

    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $form = $xpath->query('//form[.//input[@name="user"] and .//input[@name="pass"]]')?->item(0);
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
}

function fetchPage(CurlHandle $curl, string $url): string
{
    curl_setopt_array($curl, [CURLOPT_URL => $url, CURLOPT_HTTPGET => true]);
    $html = curl_exec($curl);
    if (!is_string($html) || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
        throw new RuntimeException(sprintf('Authenticated frontend page failed: %s', $url));
    }

    return $html;
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: login-check.php <base-url> <credentials-file>\n");
    exit(2);
}

$baseUrl = rtrim($argv[1], '/');
$credentials = json_decode((string)file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$username = $credentials['member']['username'] ?? null;
$password = $credentials['member']['password'] ?? null;
$moderatorUsername = $credentials['moderator']['username'] ?? null;
$moderatorPassword = $credentials['moderator']['password'] ?? null;
if (!is_string($username) || !is_string($password)
    || !is_string($moderatorUsername) || !is_string($moderatorPassword)) {
    throw new RuntimeException('Frontend credentials are unavailable.');
}

[$curl, $cookieFile] = createClient();
try {
    $protectedForumUrl = $baseUrl . '/forum/moderator-forum';
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_URL => $protectedForumUrl,
    ]);
    $protectedRedirectResponse = curl_exec($curl);
    $protectedRedirectStatus = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $protectedLoginUrl = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    $protectedLoginQuery = [];
    parse_str((string)parse_url($protectedLoginUrl, PHP_URL_QUERY), $protectedLoginQuery);
    if (!is_string($protectedRedirectResponse)
        || $protectedRedirectStatus < 300
        || $protectedRedirectStatus >= 400
        || parse_url($protectedLoginUrl, PHP_URL_PATH) !== '/login'
        || ($protectedLoginQuery['redirect_url'] ?? null) !== $protectedForumUrl) {
        throw new RuntimeException('Anonymous protected-forum request did not redirect to login with a clean return URL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_URL => $baseUrl . '/dashboard',
    ]);
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

    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    authenticate($curl, $baseUrl, $loginUrl, $username, $password);
    if (rtrim((string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL), '/') !== $baseUrl . '/dashboard') {
        throw new RuntimeException('Frontend login did not return to the originally requested dashboard URL.');
    }

    $authenticatedHtml = fetchPage($curl, $baseUrl . '/login');
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

    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_URL => $protectedForumUrl,
        CURLOPT_HTTPGET => true,
    ]);
    $forbiddenHtml = curl_exec($curl);
    if (!is_string($forbiddenHtml)
        || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 403
        || (!str_contains($forbiddenHtml, 'Access denied') && !str_contains($forbiddenHtml, 'Zugriff verweigert'))) {
        throw new RuntimeException('The authenticated member did not receive a clear forbidden response.');
    }

    foreach (['/profile', '/users', '/dashboard', '/tags', '/topics', '/posts', '/statistics', '/forum', '/forum/topic/welcome-to-the-development-forum'] as $path) {
        $pageHtml = fetchPage($curl, $baseUrl . $path);
        if ($path === '/users' && !str_contains($pageHtml, $moderatorUsername)) {
            throw new RuntimeException('The frontend user list does not contain the managed moderator.');
        }
        if ($path === '/statistics'
            && ((!str_contains($pageHtml, 'Posts') && !str_contains($pageHtml, 'Beiträge'))
                || (!str_contains($pageHtml, 'Topics') && !str_contains($pageHtml, 'Themen'))
                || (!str_contains($pageHtml, 'Members') && !str_contains($pageHtml, 'Mitglieder')))) {
            throw new RuntimeException('The statistics page does not contain the managed summaries.');
        }
        if ($path === '/forum' && str_contains($pageHtml, 'moderator-forum')) {
            throw new RuntimeException('The moderator-only forum is visible to the regular member.');
        }
    }
} finally {
    curl_close($curl);
    unlink($cookieFile);
}

[$moderatorCurl, $moderatorCookieFile] = createClient();
try {
    authenticate($moderatorCurl, $baseUrl, $baseUrl . '/login', $moderatorUsername, $moderatorPassword);
    $moderatorForumList = fetchPage($moderatorCurl, $baseUrl . '/forum');
    if (!str_contains($moderatorForumList, 'moderator-forum')) {
        throw new RuntimeException('The moderator-only forum is not visible to the moderator.');
    }
    $moderatorForum = fetchPage($moderatorCurl, $baseUrl . '/forum/moderator-forum');
    if (!str_contains($moderatorForum, '/forum/moderator-forum/new')) {
        throw new RuntimeException('The moderator cannot create a topic in the moderator-only forum.');
    }
} finally {
    curl_close($moderatorCurl);
    unlink($moderatorCookieFile);
}

echo "Frontend member and moderator access passed with real request tokens and session cookies.\n";
