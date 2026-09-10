<?php

declare(strict_types=1);

namespace Pottkinder\Typo3ForumDev;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class FixtureProvisioner
{
    private const MARKER = 'DDEV-FORUM-SAMPLE';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PasswordHashFactory $passwordHashFactory,
        private readonly OwnershipStore $ownershipStore,
        private readonly DevelopmentGuard $guard,
    ) {
    }

    /** @return array<string, int> */
    public function provision(): array
    {
        $this->guard->assertSafe();
        $credentials = $this->credentials();

        $pages = $this->provisionPages();
        $this->ownershipStore->completePhase('pages');
        $this->ensureBackendAdministrator($credentials);
        $identities = $this->provisionIdentities($pages['users_storage'], $credentials);
        $this->ownershipStore->completePhase('identities');
        $this->protectDashboard($pages['dashboard'], $identities['member_group']);
        $this->provisionContent($pages);
        $this->ownershipStore->completePhase('content');
        $this->failForSmokeTestAfter('content');
        $this->provisionParserDefaults();
        $this->ownershipStore->completePhase('parser');
        $this->provisionForum($pages, $identities);
        $this->ownershipStore->completePhase('forum');
        $this->provisionStorage();
        $this->ownershipStore->completePhase('storage');
        $this->writeSiteConfiguration($pages['root'], $pages['login']);
        $this->writeDevelopmentMailConfiguration();
        $this->ownershipStore->completePhase('configuration');

        return $pages + $identities;
    }

    private function failForSmokeTestAfter(string $phase): void
    {
        if (getenv('TYPO3_FORUM_DDEV_SMOKE') === '1' && getenv('TYPO3_FORUM_TEST_FAIL_AFTER_PHASE') === $phase) {
            throw new RuntimeException(sprintf('Intentional smoke-test interruption after phase %s.', $phase));
        }
    }

    private function provisionParserDefaults(): void
    {
        $table = 'tx_typo3forum_domain_model_format_textparser';
        $connection = $this->connectionPool->getConnectionForTable($table);
        if ((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE deleted = 0') > 0) {
            return;
        }
        $this->ownershipStore->getOrCreate('parser.bold', $table, [
            'pid' => 0,
            'type' => 'Mittwald\\Typo3Forum\\Domain\\Model\\Format\\BBCode',
            'name' => 'Bold',
            'editor_icon_class' => 'tx-typo3forum-miu-bold',
            'bbcode_wrap' => '[b]|[/b]',
            'regular_expression' => '/\\[b\\](.*)\\[\\/b\\]/iU',
            'regular_expression_replacement' => '<b>\\1</b>',
            'hidden' => 0,
            'deleted' => 0,
            'crdate' => time(),
            'tstamp' => time(),
        ]);
    }

    /** @param array<string, array{username: string, password: string}> $credentials */
    private function ensureBackendAdministrator(array $credentials): void
    {
        $connection = $this->connectionPool->getConnectionForTable('be_users');
        $username = $credentials['backend']['username'];
        $existing = $connection->fetchAssociative('SELECT uid, password, admin, disable FROM be_users WHERE username = ? AND deleted = 0', [$username]);
        if ($existing !== false) {
            if ((int)$existing['admin'] !== 1 || (int)$existing['disable'] !== 0) {
                throw new RuntimeException('The configured backend username exists but is not an enabled administrator.');
            }
            $hasher = $this->passwordHashFactory->get((string)$existing['password'], 'BE');
            if (!$hasher->checkPassword($credentials['backend']['password'], (string)$existing['password'])) {
                throw new RuntimeException('The existing backend administrator password no longer matches the local credentials file; it was not reset.');
            }
            return;
        }

        $hasher = $this->passwordHashFactory->getDefaultHashInstance('BE');
        $this->ownershipStore->getOrCreate('user.backend-admin', 'be_users', [
            'pid' => 0,
            'username' => $username,
            'password' => $hasher->getHashedPassword($credentials['backend']['password']),
            'admin' => 1,
            'disable' => 0,
            'deleted' => 0,
            'realName' => 'TYPO3 Forum Developer',
            'email' => 'forum-admin@example.invalid',
            'crdate' => time(),
            'tstamp' => time(),
        ]);
    }

    /** @return array<string, int> */
    private function provisionPages(): array
    {
        $now = time();
        $page = function (string $key, string $title, string $slug, int $parent, int $doktype = 1, bool $root = false) use ($now): int {
            return $this->ownershipStore->getOrCreate('page.' . $key, 'pages', [
                'pid' => $parent,
                'title' => $title,
                'slug' => $slug,
                'doktype' => $doktype,
                'is_siteroot' => $root ? 1 : 0,
                'hidden' => 0,
                'deleted' => 0,
                'crdate' => $now,
                'tstamp' => $now,
            ]);
        };

        $root = $page('root', 'TYPO3 Forum Development', '/', 0, 1, true);
        return [
            'root' => $root,
            'forum' => $page('forum', 'Forum', '/forum', $root),
            'login' => $page('login', 'Login', '/login', $root),
            'profile' => $page('profile', 'Profile', '/profile', $root),
            'users' => $page('users', 'Users', '/users', $root),
            'dashboard' => $page('dashboard', 'Dashboard', '/dashboard', $root),
            'tags' => $page('tags', 'Tags', '/tags', $root),
            'topics' => $page('topics', 'Topics', '/topics', $root),
            'posts' => $page('posts', 'Posts', '/posts', $root),
            'moderation' => $page('moderation', 'Moderation', '/moderation', $root),
            'statistics' => $page('statistics', 'Statistics', '/statistics', $root),
            'forum_storage' => $page('forum_storage', 'Forum data', '/forum-data', $root, 254),
            'users_storage' => $page('users_storage', 'Frontend users', '/frontend-users', $root, 254),
        ];
    }

    /** @param array<string, mixed> $credentials
     *  @return array<string, int>
     */
    private function provisionIdentities(int $storagePid, array $credentials): array
    {
        $now = time();
        $memberGroup = $this->createIdentity('group.member', 'fe_groups', 'title', 'TYPO3 Forum members', [
            'pid' => $storagePid, 'title' => 'TYPO3 Forum members', 'hidden' => 0, 'deleted' => 0,
            'crdate' => $now, 'tstamp' => $now, 'tx_typo3forum_user_mod' => 0,
        ]);
        $moderatorGroup = $this->createIdentity('group.moderator', 'fe_groups', 'title', 'TYPO3 Forum moderators', [
            'pid' => $storagePid, 'title' => 'TYPO3 Forum moderators', 'hidden' => 0, 'deleted' => 0,
            'crdate' => $now, 'tstamp' => $now, 'tx_typo3forum_user_mod' => 1,
        ]);

        $hasher = $this->passwordHashFactory->getDefaultHashInstance('FE');
        $member = $this->createIdentity('user.member', 'fe_users', 'username', $credentials['member']['username'], [
            'pid' => $storagePid,
            'username' => $credentials['member']['username'],
            'password' => $hasher->getHashedPassword($credentials['member']['password']),
            'name' => 'Forum Member',
            'email' => 'forum-member@example.invalid',
            'usergroup' => (string)$memberGroup,
            'deleted' => 0, 'disable' => 0, 'crdate' => $now, 'tstamp' => $now,
        ]);
        $moderator = $this->createIdentity('user.moderator', 'fe_users', 'username', $credentials['moderator']['username'], [
            'pid' => $storagePid,
            'username' => $credentials['moderator']['username'],
            'password' => $hasher->getHashedPassword($credentials['moderator']['password']),
            'name' => 'Forum Moderator',
            'email' => 'forum-moderator@example.invalid',
            'usergroup' => $memberGroup . ',' . $moderatorGroup,
            'deleted' => 0, 'disable' => 0, 'crdate' => $now, 'tstamp' => $now,
        ]);
        $this->assertKnownFrontendPassword($member, $credentials['member']['password']);
        $this->assertKnownFrontendPassword($moderator, $credentials['moderator']['password']);

        return ['member_group' => $memberGroup, 'moderator_group' => $moderatorGroup, 'member' => $member, 'moderator' => $moderator];
    }

    private function assertKnownFrontendPassword(int $uid, string $password): void
    {
        $connection = $this->connectionPool->getConnectionForTable('fe_users');
        $hash = $connection->fetchOne('SELECT password FROM fe_users WHERE uid = ?', [$uid]);
        if (!is_string($hash) || !$this->passwordHashFactory->get($hash, 'FE')->checkPassword($password, $hash)) {
            throw new RuntimeException(sprintf('Frontend user %d no longer matches the local credentials file; its password was not reset.', $uid));
        }
    }

    private function protectDashboard(int $dashboardPageUid, int $memberGroupUid): void
    {
        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            ['fe_group' => (string)$memberGroupUid, 'tstamp' => time()],
            ['uid' => $dashboardPageUid],
        );
    }

    /** @param array<string, mixed> $data */
    private function createIdentity(string $key, string $table, string $field, string $value, array $data): int
    {
        if (!$this->ownershipStore->has($key)) {
            $connection = $this->connectionPool->getConnectionForTable($table);
            $collision = (int)$connection->fetchOne(
                'SELECT COUNT(*) FROM ' . $connection->quoteIdentifier($table) . ' WHERE ' . $connection->quoteIdentifier($field) . ' = ?',
                [$value]
            );
            if ($collision > 0) {
                throw new RuntimeException(sprintf('Unmanaged %s collision for %s.', $table, $value));
            }
        }
        return $this->ownershipStore->getOrCreate($key, $table, $data);
    }

    /** @param array<string, int> $pages */
    private function provisionContent(array $pages): void
    {
        $now = time();
        $elements = [
            'forum' => 'typo3forum_forum',
            'profile' => 'typo3forum_userprofile',
            'users' => 'typo3forum_userlist',
            'dashboard' => 'typo3forum_dashboard',
            'tags' => 'typo3forum_taglist',
            'topics' => 'typo3forum_topiclist',
            'posts' => 'typo3forum_postlist',
            'moderation' => 'typo3forum_moderationreports',
            'statistics' => 'typo3forum_statsbox',
            'login' => 'felogin_login',
        ];
        $sorting = 100;
        foreach ($elements as $key => $cType) {
            $this->ownershipStore->getOrCreate('content.' . $key, 'tt_content', [
                'pid' => $pages[$key], 'CType' => $cType, 'header' => ucfirst($key), 'colPos' => 0,
                'sorting' => $sorting, 'hidden' => 0, 'deleted' => 0, 'crdate' => $now, 'tstamp' => $now,
            ]);
            $sorting += 100;
        }

        $constants = sprintf(
            "plugin.tx_typo3forum.persistence.storagePid = %d,%d\nplugin.tx_typo3forum.settings.pids.Forum = %d\nplugin.tx_typo3forum.settings.pids.UserShow = %d\nplugin.tx_typo3forum.settings.pids.UserList = %d\nplugin.tx_typo3forum.settings.pids.UserEdit = %d\nplugin.tx_typo3forum.settings.pids.Dashboard = %d\nplugin.tx_typo3forum.settings.pids.TagList = %d\nplugin.tx_typo3forum.settings.pids.ReportList = %d\nstyles.content.loginform.pid = %d\nstyles.content.loginform.redirectMode = getpost,login\nstyles.content.loginform.redirectFirstMethod = 1\nstyles.content.loginform.redirectPageLogin = %d\n",
            $pages['forum_storage'], $pages['users_storage'], $pages['forum'], $pages['profile'], $pages['users'], $pages['profile'],
            $pages['dashboard'], $pages['tags'], $pages['moderation'], $pages['users_storage'], $pages['forum']
        );
        $setup = <<<'TYPOSCRIPT'
page = PAGE
page {
  includeCSS.forumDevelopment = EXT:typo3_forum_dev/Resources/Public/Css/forum-dev.css
  10 = HMENU
  10 {
    1 = TMENU
    1.NO.wrapItemAndSub = <li>|</li>
    wrap = <nav><ul>|</ul></nav>
  }
  20 < styles.content.get
  20.wrap = <main>|</main>
}
TYPOSCRIPT;
        $template = [
            'pid' => $pages['root'], 'title' => 'TYPO3 Forum DDEV', 'root' => 1, 'clear' => 3,
            'include_static_file' => 'EXT:fluid_styled_content/Configuration/TypoScript/,EXT:typo3_forum/Configuration/TypoScript/',
            'constants' => $constants, 'config' => $setup, 'hidden' => 0, 'deleted' => 0,
            'crdate' => $now, 'tstamp' => $now,
        ];
        $templateUid = $this->ownershipStore->getOrCreate('template.root', 'sys_template', $template);
        $templateConnection = $this->connectionPool->getConnectionForTable('sys_template');
        $storedTemplate = $templateConnection->fetchAssociative(
            'SELECT constants, config FROM sys_template WHERE uid = ?',
            [$templateUid],
        );
        if ($storedTemplate === false || $storedTemplate['constants'] !== $constants || $storedTemplate['config'] !== $setup) {
            $templateConnection->update(
                'sys_template',
                ['constants' => $constants, 'config' => $setup, 'tstamp' => $now],
                ['uid' => $templateUid],
            );
        }
    }

    /** @param array<string, int> $pages
     *  @param array<string, int> $identities
     */
    private function provisionForum(array $pages, array $identities): void
    {
        $storagePid = $pages['forum_storage'];
        $now = time();
        $category = $this->ownershipStore->getOrCreate('forum.category', 'tx_typo3forum_domain_model_forum_forum', [
            'pid' => $storagePid, 'displayed_pid' => $pages['forum'], 'forum' => 0, 'title' => 'Development forums',
            'description' => 'Forums created by the DDEV fixture.', 'slug' => 'development-forums',
            'sorting' => 100, 'hidden' => 0, 'deleted' => 0, 'crdate' => $now, 'tstamp' => $now,
        ]);
        $forum = $this->ownershipStore->getOrCreate('forum.public', 'tx_typo3forum_domain_model_forum_forum', [
            'pid' => $storagePid, 'displayed_pid' => $pages['forum'], 'forum' => $category, 'title' => 'Development forum',
            'description' => 'Public forum for local development.', 'slug' => 'development-forum',
            'sorting' => 100, 'hidden' => 0, 'deleted' => 0, 'crdate' => $now, 'tstamp' => $now,
        ]);
        $topic = $this->ownershipStore->getOrCreate('topic.sample', 'tx_typo3forum_domain_model_forum_topic', [
            'pid' => $storagePid, 'forum' => $forum, 'subject' => 'Welcome to the development forum',
            'slug' => 'welcome-to-the-development-forum', 'author' => $identities['member'], 'post_count' => 1,
            'hidden' => 0, 'deleted' => 0, 'crdate' => $now, 'tstamp' => $now, 'last_post_crdate' => $now,
        ]);
        $post = $this->ownershipStore->getOrCreate('post.sample', 'tx_typo3forum_domain_model_forum_post', [
            'pid' => $storagePid, 'topic' => $topic, 'text' => self::MARKER . ': the automated forum fixture is ready.',
            'rendered_text' => self::MARKER . ': the automated forum fixture is ready.', 'author' => $identities['member'],
            'author_name' => 'forum_member', 'hidden' => 0, 'deleted' => 0, 'crdate' => $now, 'tstamp' => $now,
        ]);

        $forumConnection = $this->connectionPool->getConnectionForTable('tx_typo3forum_domain_model_forum_forum');
        $forumConnection->executeStatement('UPDATE tx_typo3forum_domain_model_forum_forum SET children = 1 WHERE uid = ? AND children = 0', [$category]);
        $forumConnection->executeStatement('UPDATE tx_typo3forum_domain_model_forum_forum SET topics = 1, topic_count = 1, post_count = 1, last_topic = ?, last_post = ? WHERE uid = ? AND topics = 0', [$topic, $post, $forum]);
        $topicConnection = $this->connectionPool->getConnectionForTable('tx_typo3forum_domain_model_forum_topic');
        $topicConnection->executeStatement('UPDATE tx_typo3forum_domain_model_forum_topic SET posts = 1, last_post = ? WHERE uid = ? AND posts = 0', [$post, $topic]);

        $access = static fn (string $operation, int $level, int $group = 0): array => [
            'pid' => $storagePid, 'forum' => $forum, 'operation' => $operation, 'login_level' => $level,
            'affected_group' => $group, 'negate' => 0, 'hidden' => 0, 'deleted' => 0, 'crdate' => $now, 'tstamp' => $now,
        ];
        $rules = [
            'read.everyone' => $access('read', 0),
            'topic.member' => $access('newTopic', 2, $identities['member_group']),
            'post.member' => $access('newPost', 2, $identities['member_group']),
            'moderate.moderator' => $access('moderate', 2, $identities['moderator_group']),
            'delete-topic.moderator' => $access('deleteTopic', 2, $identities['moderator_group']),
            'delete-post.moderator' => $access('deletePost', 2, $identities['moderator_group']),
            'edit-post.moderator' => $access('editPost', 2, $identities['moderator_group']),
            'solution.moderator' => $access('solution', 2, $identities['moderator_group']),
        ];
        foreach ($rules as $key => $data) {
            $this->ownershipStore->getOrCreate('acl.' . $key, 'tx_typo3forum_domain_model_forum_access', $data);
        }
        $forumConnection->executeStatement('UPDATE tx_typo3forum_domain_model_forum_forum SET acls = 8 WHERE uid = ? AND acls = 0', [$forum]);
    }

    private function provisionStorage(): void
    {
        $publicPath = Environment::getPublicPath();
        foreach ([$publicPath . '/fileadmin', $publicPath . '/fileadmin/user_upload', $publicPath . '/typo3temp/assets'] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create writable directory %s.', $directory));
            }
            chmod($directory, 0775);
        }

        $connection = $this->connectionPool->getConnectionForTable('sys_file_storage');
        $defaultCount = (int)$connection->fetchOne('SELECT COUNT(*) FROM sys_file_storage WHERE is_default = 1 AND deleted = 0');
        if ($defaultCount > 1) {
            throw new RuntimeException('Multiple default FAL storages exist; refusing an ambiguous storage configuration.');
        }
        $existing = $connection->fetchAssociative('SELECT uid, driver, configuration, is_writable, is_online FROM sys_file_storage WHERE is_default = 1 AND deleted = 0');
        if ($existing !== false) {
            if ((int)$existing['is_writable'] !== 1 || (int)$existing['is_online'] !== 1
                || $existing['driver'] !== 'Local' || !str_contains((string)$existing['configuration'], 'fileadmin')) {
                throw new RuntimeException('The existing default FAL storage is not an appropriate writable local fileadmin storage.');
            }
            return;
        }

        $configuration = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="basePath"><value index="vDEF">fileadmin/</value></field><field index="pathType"><value index="vDEF">relative</value></field><field index="caseSensitive"><value index="vDEF">1</value></field></language></sheet></data></T3FlexForms>';
        $this->ownershipStore->getOrCreate('storage.default', 'sys_file_storage', [
            'pid' => 0, 'name' => 'DDEV fileadmin', 'description' => 'Managed local DDEV attachment storage.',
            'driver' => 'Local', 'configuration' => $configuration, 'is_default' => 1, 'is_browsable' => 1,
            'is_public' => 1, 'is_writable' => 1, 'is_online' => 1, 'auto_extract_metadata' => 1,
            'processingfolder' => '_processed_', 'deleted' => 0, 'crdate' => time(), 'tstamp' => time(),
        ]);
    }

    private function writeSiteConfiguration(int $rootPageUid, int $loginPageUid): void
    {
        $projectPath = Environment::getProjectPath();
        $templatePath = $projectPath . '/Configuration/site.template.yaml';
        $targetDirectory = $projectPath . '/config/sites/forum-dev';
        $targetPath = $targetDirectory . '/config.yaml';
        $baseUrl = rtrim((string)getenv('DDEV_PRIMARY_URL'), '/');
        if ($baseUrl === '' || !str_ends_with(parse_url($baseUrl, PHP_URL_HOST) ?: '', '.ddev.site')) {
            throw new RuntimeException('DDEV_PRIMARY_URL is missing or not a local DDEV URL.');
        }
        $template = (string)file_get_contents($templatePath);
        $configuration = str_replace(
            ['__BASE_URL__', '__ROOT_PAGE_UID__', '__LOGIN_PAGE_UID__'],
            [$baseUrl, (string)$rootPageUid, (string)$loginPageUid],
            $template,
        );
        Yaml::parse($configuration);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Unable to create site configuration directory.');
        }
        if (is_file($targetPath)) {
            $existing = (string)file_get_contents($targetPath);
            if (!str_contains($existing, '# TYPO3 Forum DDEV managed site')) {
                throw new RuntimeException('An unmanaged forum-dev site configuration already exists.');
            }
        }
        if (file_put_contents($targetPath, $configuration, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the managed site configuration.');
        }
    }

    private function writeDevelopmentMailConfiguration(): void
    {
        $path = Environment::getConfigPath() . '/system/additional.php';
        $beginMarker = '// TYPO3 Forum DDEV managed mail configuration: begin';
        $endMarker = '// TYPO3 Forum DDEV managed mail configuration: end';
        $trustedHostsPattern = DevelopmentGuard::trustedHostsPattern((string)getenv('DDEV_PRIMARY_URL'));
        $block = "{$beginMarker}\n"
            . "\$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';\n"
            . "\$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] = 'localhost:1025';\n"
            . "\$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = " . var_export($trustedHostsPattern, true) . ";\n"
            . $endMarker;
        $contents = is_file($path) ? (string)file_get_contents($path) : "<?php\n";
        if (str_contains($contents, $beginMarker)) {
            $pattern = '/' . preg_quote($beginMarker, '/') . '.*?' . preg_quote($endMarker, '/') . '/s';
            $updated = preg_replace($pattern, $block, $contents, 1, $count);
            if (!is_string($updated) || $count !== 1) {
                throw new RuntimeException('The managed mail block in additional.php is malformed.');
            }
        } elseif (is_file($path) && !str_contains($contents, '#ddev-generated')) {
            throw new RuntimeException('An unmanaged config/system/additional.php exists; refusing to modify it.');
        } else {
            $contents = rtrim($contents);
            if (str_ends_with($contents, '?>')) {
                $contents = rtrim(substr($contents, 0, -2));
            }
            $updated = $contents . "\n\n{$block}\n";
        }
        if (file_put_contents($path, $updated, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the managed development mail configuration.');
        }
    }

    /** @return array<string, array{username: string, password: string}> */
    private function credentials(): array
    {
        $path = Environment::getProjectPath() . '/.bootstrap/credentials.json';
        if (!is_file($path)) {
            throw new RuntimeException('Generated credentials are missing.');
        }
        $credentials = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($credentials)) {
            throw new RuntimeException('Generated credentials are invalid.');
        }
        foreach (['backend', 'member', 'moderator'] as $role) {
            if (!is_string($credentials[$role]['username'] ?? null) || !is_string($credentials[$role]['password'] ?? null)) {
                throw new RuntimeException('Generated credentials are missing or invalid.');
            }
        }
        return $credentials;
    }
}
