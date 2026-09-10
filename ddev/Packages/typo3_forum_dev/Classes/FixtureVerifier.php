<?php

declare(strict_types=1);

namespace Pottkinder\Typo3ForumDev;

use RuntimeException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\PackageManager;

final class FixtureVerifier
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly OwnershipStore $ownershipStore,
        private readonly DevelopmentGuard $guard,
        private readonly PackageManager $packageManager,
        private readonly PasswordHashFactory $passwordHashFactory,
        private readonly Typo3Version $typo3Version,
    ) {
    }

    /** @return list<string> */
    public function verify(): array
    {
        $this->guard->assertSafe();
        if (!self::supportsTypo3Version($this->typo3Version->getVersion())) {
            throw new RuntimeException('Expected TYPO3 >=14.3.0 and <15.0.0.');
        }
        if (!$this->packageManager->isPackageActive('typo3_forum') || !$this->packageManager->isPackageActive('typo3_forum_dev')) {
            throw new RuntimeException('The forum or its development provisioner is not active.');
        }
        foreach (['pages', 'identities', 'content', 'parser', 'forum', 'storage', 'configuration'] as $phase) {
            if (!$this->ownershipStore->phaseComplete($phase)) {
                throw new RuntimeException(sprintf('Provisioning phase %s is incomplete.', $phase));
            }
        }

        $checks = [];
        $rootUid = $this->ownershipStore->uid('page.root');
        $this->assertRecord('pages', $rootUid, ['is_siteroot' => 1]);
        foreach (['forum', 'login', 'profile', 'users', 'dashboard', 'tags', 'topics', 'posts', 'moderation', 'statistics', 'forum_storage', 'users_storage'] as $page) {
            $this->assertRecord('pages', $this->ownershipStore->uid('page.' . $page), ['pid' => $rootUid]);
        }
        $checks[] = 'managed page tree and site root';

        $postConnection = $this->connectionPool->getConnectionForTable('tx_typo3forum_domain_model_forum_post');
        $postUid = $this->ownershipStore->uid('post.sample');
        $text = $postConnection->fetchOne('SELECT text FROM tx_typo3forum_domain_model_forum_post WHERE uid = ?', [$postUid]);
        if (!is_string($text) || !str_contains($text, 'DDEV-FORUM-SAMPLE')) {
            throw new RuntimeException('The managed sample post marker is missing.');
        }
        $forumConnection = $this->connectionPool->getConnectionForTable('tx_typo3forum_domain_model_forum_forum');
        $forumUid = $this->ownershipStore->uid('forum.public');
        $forum = $forumConnection->fetchAssociative('SELECT forum, topics, acls, last_post FROM tx_typo3forum_domain_model_forum_forum WHERE uid = ?', [$forumUid]);
        if ($forum === false || (int)$forum['forum'] !== $this->ownershipStore->uid('forum.category')
            || (int)$forum['topics'] !== 1 || (int)$forum['acls'] !== 8 || (int)$forum['last_post'] !== $postUid) {
            throw new RuntimeException('The managed forum relationships or counters are inconsistent.');
        }
        $checks[] = 'forum, sample topic and sample post';
        $parserConnection = $this->connectionPool->getConnectionForTable('tx_typo3forum_domain_model_format_textparser');
        if ((int)$parserConnection->fetchOne('SELECT COUNT(*) FROM tx_typo3forum_domain_model_format_textparser WHERE deleted = 0') === 0) {
            throw new RuntimeException('TYPO3 Forum parser defaults were not imported by extension setup.');
        }
        $checks[] = 'forum parser defaults';

        $credentials = $this->credentials();
        $backendConnection = $this->connectionPool->getConnectionForTable('be_users');
        $backendHash = $backendConnection->fetchOne('SELECT password FROM be_users WHERE username = ? AND admin = 1 AND disable = 0 AND deleted = 0', [$credentials['backend']['username']]);
        if (!is_string($backendHash) || !$this->passwordHashFactory->get($backendHash, 'BE')->checkPassword($credentials['backend']['password'], $backendHash)) {
            throw new RuntimeException('The backend account no longer matches the local credentials file.');
        }

        $userConnection = $this->connectionPool->getConnectionForTable('fe_users');
        foreach (['member', 'moderator'] as $role) {
            $user = $userConnection->fetchAssociative('SELECT uid, password, usergroup FROM fe_users WHERE username = ? AND disable = 0 AND deleted = 0', [$credentials[$role]['username']]);
            if ($user === false || !$this->passwordHashFactory->get((string)$user['password'], 'FE')->checkPassword($credentials[$role]['password'], (string)$user['password'])) {
                throw new RuntimeException(sprintf('Frontend %s account is missing or no longer matches the credentials file.', $role));
            }
            $expectedGroups = $role === 'member'
                ? (string)$this->ownershipStore->uid('group.member')
                : $this->ownershipStore->uid('group.member') . ',' . $this->ownershipStore->uid('group.moderator');
            if ($user['usergroup'] !== $expectedGroups) {
                throw new RuntimeException(sprintf('Frontend %s group membership is inconsistent.', $role));
            }
        }
        $groupConnection = $this->connectionPool->getConnectionForTable('fe_groups');
        if ((int)$groupConnection->fetchOne('SELECT tx_typo3forum_user_mod FROM fe_groups WHERE uid = ?', [$this->ownershipStore->uid('group.member')]) !== 0
            || (int)$groupConnection->fetchOne('SELECT tx_typo3forum_user_mod FROM fe_groups WHERE uid = ?', [$this->ownershipStore->uid('group.moderator')]) !== 1) {
            throw new RuntimeException('Member and moderator group roles are inconsistent.');
        }
        $aclConnection = $this->connectionPool->getConnectionForTable('tx_typo3forum_domain_model_forum_access');
        if ((int)$aclConnection->fetchOne('SELECT COUNT(*) FROM tx_typo3forum_domain_model_forum_access WHERE forum = ? AND deleted = 0', [$this->ownershipStore->uid('forum.public')]) < 8) {
            throw new RuntimeException('The managed forum ACL fixture is incomplete.');
        }
        $aclExpectations = [
            'read.everyone' => ['read', 0, 0],
            'topic.member' => ['newTopic', 2, $this->ownershipStore->uid('group.member')],
            'post.member' => ['newPost', 2, $this->ownershipStore->uid('group.member')],
            'moderate.moderator' => ['moderate', 2, $this->ownershipStore->uid('group.moderator')],
            'delete-topic.moderator' => ['deleteTopic', 2, $this->ownershipStore->uid('group.moderator')],
            'delete-post.moderator' => ['deletePost', 2, $this->ownershipStore->uid('group.moderator')],
            'edit-post.moderator' => ['editPost', 2, $this->ownershipStore->uid('group.moderator')],
            'solution.moderator' => ['solution', 2, $this->ownershipStore->uid('group.moderator')],
        ];
        foreach ($aclExpectations as $key => [$operation, $level, $group]) {
            $acl = $aclConnection->fetchAssociative('SELECT forum, operation, login_level, affected_group, negate FROM tx_typo3forum_domain_model_forum_access WHERE uid = ? AND deleted = 0', [$this->ownershipStore->uid('acl.' . $key)]);
            if ($acl === false || (int)$acl['forum'] !== $this->ownershipStore->uid('forum.public')
                || $acl['operation'] !== $operation || (int)$acl['login_level'] !== $level
                || (int)$acl['affected_group'] !== $group || (int)$acl['negate'] !== 0) {
                throw new RuntimeException(sprintf('Managed ACL %s is inconsistent.', $key));
            }
        }
        $checks[] = 'member, moderator and scoped ACL records';

        $storageConnection = $this->connectionPool->getConnectionForTable('sys_file_storage');
        if ((int)$storageConnection->fetchOne("SELECT COUNT(*) FROM sys_file_storage WHERE is_default = 1 AND is_online = 1 AND is_writable = 1 AND driver = 'Local' AND configuration LIKE '%fileadmin%' AND deleted = 0") !== 1) {
            throw new RuntimeException('No unique writable default FAL storage is available.');
        }
        if (!is_writable(Environment::getPublicPath() . '/fileadmin/user_upload')) {
            throw new RuntimeException('The local upload directory is not writable.');
        }
        $checks[] = 'writable default local FAL storage';

        $sitePath = Environment::getConfigPath() . '/sites/forum-dev/config.yaml';
        $site = is_file($sitePath) ? (string)file_get_contents($sitePath) : '';
        if (!str_contains($site, 'rootPageId: ' . $rootUid)
            || !str_contains($site, (string)getenv('DDEV_PRIMARY_URL'))) {
            throw new RuntimeException('Generated site routing configuration is missing or stale.');
        }
        $templateConnection = $this->connectionPool->getConnectionForTable('sys_template');
        $constants = $templateConnection->fetchOne('SELECT constants FROM sys_template WHERE uid = ?', [$this->ownershipStore->uid('template.root')]);
        $expectedSettings = [
            'storagePid = ' . $this->ownershipStore->uid('page.forum_storage') . ',' . $this->ownershipStore->uid('page.users_storage'),
            'pids.Forum = ' . $this->ownershipStore->uid('page.forum'),
            'pids.UserShow = ' . $this->ownershipStore->uid('page.profile'),
            'pids.UserList = ' . $this->ownershipStore->uid('page.users'),
            'pids.Dashboard = ' . $this->ownershipStore->uid('page.dashboard'),
            'pids.TagList = ' . $this->ownershipStore->uid('page.tags'),
            'pids.ReportList = ' . $this->ownershipStore->uid('page.moderation'),
            'styles.content.loginform.pid = ' . $this->ownershipStore->uid('page.users_storage'),
            'styles.content.loginform.redirectMode = getpost,login',
        ];
        if (!is_string($constants)) {
            throw new RuntimeException('Generated forum storage/page TypoScript settings are stale.');
        }
        foreach ($expectedSettings as $setting) {
            if (!str_contains($constants, $setting)) {
                throw new RuntimeException(sprintf('Generated TypoScript is missing %s.', $setting));
            }
        }
        $dashboardGroup = $this->connectionPool->getConnectionForTable('pages')->fetchOne(
            'SELECT fe_group FROM pages WHERE uid = ?',
            [$this->ownershipStore->uid('page.dashboard')],
        );
        if ((string)$dashboardGroup !== (string)$this->ownershipStore->uid('group.member')
            || !str_contains($site, 'errorHandler: LoginRedirect')
            || !str_contains($site, 'loginRedirectParameter: redirect_url')) {
            throw new RuntimeException('Dashboard login redirect configuration is missing or stale.');
        }
        $checks[] = 'site routing, TypoScript and generated UIDs';

        if (($GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] ?? '') !== 'smtp'
            || ($GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] ?? '') !== 'localhost:1025') {
            throw new RuntimeException('Development mail is not routed to DDEV Mailpit.');
        }
        $trustedHostsPattern = DevelopmentGuard::trustedHostsPattern((string)getenv('DDEV_PRIMARY_URL'));
        if (($GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] ?? '') !== $trustedHostsPattern) {
            throw new RuntimeException('The DDEV primary host is not configured as a trusted TYPO3 host.');
        }
        $checks[] = 'DDEV Mailpit transport and trusted host';

        $packagePath = realpath(Environment::getProjectPath() . '/packages/typo3_forum');
        $installedPath = realpath(Environment::getProjectPath() . '/vendor/pottkinder/typo3forum');
        if ($packagePath === false || $packagePath !== $installedPath) {
            throw new RuntimeException('The forum package is not resolved from the mounted checkout.');
        }
        $checks[] = 'local checked-out forum package';

        return $checks;
    }

    public static function supportsTypo3Version(string $version): bool
    {
        return version_compare($version, '14.3.0', '>=')
            && version_compare($version, '15.0.0', '<');
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
                throw new RuntimeException('Generated credentials are incomplete.');
            }
        }
        return $credentials;
    }

    /** @param array<string, int> $expected */
    private function assertRecord(string $table, int $uid, array $expected): void
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        $record = $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE uid = ?', [$uid]);
        if ($record === false) {
            throw new RuntimeException(sprintf('Expected record %s:%d is missing.', $table, $uid));
        }
        foreach ($expected as $field => $value) {
            if ((int)$record[$field] !== $value) {
                throw new RuntimeException(sprintf('Expected value for %s.%s is missing.', $table, $field));
            }
        }
    }
}
