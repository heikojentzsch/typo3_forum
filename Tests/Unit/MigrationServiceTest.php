<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Migration\CanonicalJson;
use Mittwald\Typo3Forum\Migration\FlexFormMigrator;
use Mittwald\Typo3Forum\Migration\MigrationContract;
use Mittwald\Typo3Forum\Migration\MigrationService;
use RuntimeException;

final class MigrationServiceTest extends DatabaseTestCase
{
    private MigrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection->executeStatement('CREATE TABLE tt_content (uid INTEGER PRIMARY KEY, pid INTEGER, CType TEXT, list_type TEXT, pi_flexform TEXT, pages TEXT, recursive INTEGER, sys_language_uid INTEGER, l18n_parent INTEGER, t3ver_oid INTEGER, t3ver_id INTEGER, t3ver_wsid INTEGER, t3ver_state INTEGER, hidden INTEGER, deleted INTEGER, starttime INTEGER, endtime INTEGER, sorting INTEGER, colPos INTEGER, header TEXT)');
        $this->connection->executeStatement('CREATE TABLE be_groups (uid INTEGER PRIMARY KEY, subgroup TEXT, explicit_allowdeny TEXT)');
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_migration_journal (uid INTEGER PRIMARY KEY AUTOINCREMENT, manifest_checksum TEXT, table_name TEXT, record_uid INTEGER, rule_id TEXT, rule_version TEXT, before_fingerprint TEXT, after_fingerprint TEXT, applied_at INTEGER, UNIQUE (manifest_checksum, table_name, record_uid))');
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_migration_lock (lock_id INTEGER PRIMARY KEY, manifest_checksum TEXT, started_at INTEGER)');
        $this->service = new MigrationService($this->pool, new MigrationContract(), new FlexFormMigrator());
    }

    public function testAllStandardPluginsMigrateWithoutChangingOtherFieldsOrFlexForms(): void
    {
        $mapping = (new MigrationContract())->standardPlugins();
        $expectedFlexForms = [];
        foreach (array_keys($mapping) as $index => $listType) {
            $uid = $index + 1;
            $flexForm = '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="settings.value"><value index="vDEF">' . $uid . '</value></field></language></sheet></data></T3FlexForms>';
            $expectedFlexForms[$uid] = $flexForm;
            $this->insertContent($uid, 'list', $listType, $flexForm, ['hidden' => $uid === 2 ? 1 : 0, 'deleted' => $uid === 3 ? 1 : 0, 'sys_language_uid' => $uid === 4 ? 1 : 0, 'l18n_parent' => $uid === 4 ? 1 : 0, 't3ver_oid' => $uid === 5 ? 1 : 0, 't3ver_wsid' => $uid === 5 ? 2 : 0]);
        }
        $this->insertContent(50, 'list', 'news_pi1', '<foreign/>');
        $this->insertContent(51, 'typo3forum_forum', '', '<already/>');

        $plan = $this->service->plan();
        self::assertSame('READY', $plan['status']);
        self::assertCount(9, $plan['operations']);
        self::assertSame('SUCCESS', $this->service->apply($plan, $plan['checksum'])['status']);

        foreach ($mapping as $listType => $cType) {
            $row = $this->connection->fetchAssociative('SELECT * FROM tt_content WHERE uid = ?', [array_search($listType, array_keys($mapping), true) + 1]);
            self::assertSame($cType, $row['CType']);
            self::assertSame('', $row['list_type']);
            self::assertSame($expectedFlexForms[(int)$row['uid']], $row['pi_flexform']);
        }
        self::assertSame(['list', 'news_pi1', '<foreign/>'], array_values($this->connection->fetchAssociative('SELECT CType, list_type, pi_flexform FROM tt_content WHERE uid = 50')));
        self::assertSame(['typo3forum_forum', '', '<already/>'], array_values($this->connection->fetchAssociative('SELECT CType, list_type, pi_flexform FROM tt_content WHERE uid = 51')));
        self::assertSame('ALREADY_MIGRATED', $this->service->plan()['status']);
    }

    public function testKnownPi1PostAndTopicRulesAreMigratedAndUnknownFieldsArePreservedByBlocking(): void
    {
        $post = $this->legacyFlexForm('Post->list', ['settings.listPosts' => '2']);
        $topic = $this->legacyFlexForm('Topic->list', ['settings.listTopics' => '4', 'settings.maxTopicItems' => '17']);
        $this->insertContent(1, 'list', 'typo3forum_pi1', $post);
        $this->insertContent(2, 'list', 'typo3forum_pi1', $topic);
        $plan = $this->service->plan();
        self::assertSame('READY', $plan['status']);
        self::assertSame(['pi1-post-list-v10', 'pi1-topic-list-v10'], array_column($plan['operations'], 'rule_id'));
        $this->service->apply($plan, $plan['checksum']);
        self::assertSame('typo3forum_postlist', $this->connection->fetchOne('SELECT CType FROM tt_content WHERE uid = 1'));
        self::assertSame('typo3forum_topiclist', $this->connection->fetchOne('SELECT CType FROM tt_content WHERE uid = 2'));
        $topicAfter = (string)$this->connection->fetchOne('SELECT pi_flexform FROM tt_content WHERE uid = 2');
        self::assertStringNotContainsString('switchableControllerActions', $topicAfter);
        self::assertStringContainsString('settings.maxItems', $topicAfter);
        self::assertStringNotContainsString('settings.maxTopicItems', $topicAfter);

        $this->insertContent(3, 'list', 'typo3forum_pi1', $this->legacyFlexForm('Post->list', ['project.custom' => 'keep-me']));
        $blocked = $this->service->plan();
        self::assertSame('BLOCKED', $blocked['status']);
        self::assertSame('list', $this->connection->fetchOne('SELECT CType FROM tt_content WHERE uid = 3'));
    }

    public function testUnknownMalformedAndEntityFlexFormsBlockWithoutWrites(): void
    {
        foreach ([
            1 => $this->legacyFlexForm('User->show;User->showMyProfile'),
            2 => '<broken',
            3 => '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><T3FlexForms>&e;</T3FlexForms>',
        ] as $uid => $flexForm) {
            $this->insertContent($uid, 'list', 'typo3forum_pi1', $flexForm);
        }
        $plan = $this->service->plan();
        self::assertSame('BLOCKED', $plan['status']);
        self::assertSame([], $plan['operations']);
        self::assertSame(['list', 'list', 'list'], $this->connection->fetchFirstColumn('SELECT CType FROM tt_content ORDER BY uid'));
    }

    public function testBackendRightsAreMappedOneToOneWhilePi1AndContradictionsBlock(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $this->connection->insert('be_groups', ['uid' => 1, 'subgroup' => '2', 'explicit_allowdeny' => 'tt_content:list_type:typo3forum_forum:ALLOW,tt_content:list_type:news_pi1:ALLOW']);
        $plan = $this->service->plan();
        self::assertSame('READY', $plan['status']);
        self::assertCount(2, $plan['operations']);
        $this->service->apply($plan, $plan['checksum']);
        self::assertSame('tt_content:CType:typo3forum_forum:ALLOW,tt_content:list_type:news_pi1:ALLOW', $this->connection->fetchOne('SELECT explicit_allowdeny FROM be_groups WHERE uid = 1'));

        $this->connection->insert('be_groups', ['uid' => 2, 'subgroup' => '', 'explicit_allowdeny' => 'tt_content:list_type:typo3forum_pi1:ALLOW']);
        self::assertSame('BLOCKED', $this->service->check()['status']);
    }

    public function testChangedSourceAndTamperedPlansAreRejected(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '<same/>');
        $plan = $this->service->plan();
        $this->connection->update('tt_content', ['header' => 'changed'], ['uid' => 1]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source conflict');
        $this->service->apply($plan, $plan['checksum']);
    }

    public function testNewLegacyRecordAfterPreflightBlocksTheTargetPlan(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $check = $this->service->check();
        $preflight = [
            'manifest_format' => 'typo3-forum-preflight/1.0',
            'contract_version' => (new MigrationContract())->version(),
            'inventory' => ['content_records' => [['uid' => 1, 'fingerprint' => $check['inventory']['content_records'][0]['fingerprint']]]],
        ];
        $preflight['checksum'] = CanonicalJson::checksum($preflight);
        $this->insertContent(2, 'list', 'typo3forum_topiclist', '');
        $blocked = $this->service->check($preflight);
        self::assertSame('BLOCKED', $blocked['status']);
        self::assertContains('SOURCE_RECORD_ADDED_AFTER_PREFLIGHT', array_column($blocked['findings'], 'code'));
    }

    public function testManipulatedPlanIsRejectedBeforeWriting(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $plan = $this->service->plan();
        $plan['operations'][0]['after']['CType'] = 'text';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checksum');
        $this->service->apply($plan, $plan['checksum']);
    }

    public function testRechecksummedUnsupportedManifestOperationIsRejected(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $plan = $this->service->plan();
        $plan['operations'][0]['operation'] = 'execute_sql';
        unset($plan['checksum']);
        $plan['checksum'] = CanonicalJson::checksum($plan);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported operation');
        $this->service->dryRun($plan);
    }

    public function testInterruptedRunResumesJournaledOperationsWithoutDuplicateChanges(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $this->insertContent(2, 'list', 'typo3forum_topiclist', $this->legacyFlexForm('ignored'));
        $plan = $this->service->plan();
        $first = $plan['operations'][0];
        $this->connection->update('tt_content', ['CType' => $first['after']['CType'], 'list_type' => ''], ['uid' => 1]);
        $this->connection->insert('tx_typo3forum_migration_journal', ['manifest_checksum' => $plan['checksum'], 'table_name' => 'tt_content', 'record_uid' => 1, 'rule_id' => $first['rule_id'], 'rule_version' => $first['rule_version'], 'before_fingerprint' => $first['before_fingerprint'], 'after_fingerprint' => $first['after_fingerprint'], 'applied_at' => time()]);
        $result = $this->service->apply($plan, $plan['checksum']);
        self::assertSame(1, $result['resumed']);
        self::assertSame(1, $result['applied']);
        self::assertSame(2, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM tx_typo3forum_migration_journal'));
    }

    public function testInterruptedLockRequiresExplicitSamePlanRecovery(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $plan = $this->service->plan();
        $this->connection->insert('tx_typo3forum_migration_lock', ['lock_id' => 1, 'manifest_checksum' => $plan['checksum'], 'started_at' => 1]);
        try {
            $this->service->apply($plan, $plan['checksum']);
            self::fail('An existing lock must stop the default apply path.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('holds the migration lock', $exception->getMessage());
        }
        self::assertSame('SUCCESS', $this->service->apply($plan, $plan['checksum'], true)['status']);
        self::assertSame(0, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM tx_typo3forum_migration_lock'));
    }

    public function testMissingListTypeIsIndeterminateWithForumDataButFreshSchemaIsComplete(): void
    {
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_domain_model_forum_forum (uid INTEGER PRIMARY KEY, title TEXT)');
        $this->connection->insert('tx_typo3forum_domain_model_forum_forum', ['uid' => 1, 'title' => 'existing']);
        $this->connection->executeStatement('ALTER TABLE tt_content DROP COLUMN list_type');
        self::assertSame('INDETERMINATE', $this->service->check()['status']);
    }

    public function testFreshSchemaWithoutLegacyColumnDoesNotRequireTheWizard(): void
    {
        $this->connection->executeStatement('ALTER TABLE tt_content DROP COLUMN list_type');
        self::assertSame('ALREADY_MIGRATED', $this->service->check()['status']);
        self::assertSame([], $this->service->plan()['operations']);
    }

    public function testForumRelationsPasswordsAndFileReferencesRemainUnchanged(): void
    {
        $this->connection->executeStatement('CREATE TABLE fe_users (uid INTEGER PRIMARY KEY, password TEXT)');
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_domain_model_forum_forum (uid INTEGER PRIMARY KEY, title TEXT)');
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_domain_model_forum_topic (uid INTEGER PRIMARY KEY, forum INTEGER, author INTEGER, last_post INTEGER, solution INTEGER, subject TEXT)');
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_domain_model_forum_post (uid INTEGER PRIMARY KEY, topic INTEGER, author INTEGER, author_name TEXT, text TEXT)');
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_domain_model_forum_attachment (uid INTEGER PRIMARY KEY, post INTEGER, name TEXT)');
        $this->connection->executeStatement('CREATE TABLE sys_file_reference (uid INTEGER PRIMARY KEY, uid_local INTEGER, uid_foreign INTEGER, tablenames TEXT, fieldname TEXT)');
        $this->connection->insert('fe_users', ['uid' => 7, 'password' => '$argon2id$preserve']);
        $this->connection->insert('tx_typo3forum_domain_model_forum_forum', ['uid' => 11, 'title' => 'Forum']);
        $this->connection->insert('tx_typo3forum_domain_model_forum_topic', ['uid' => 12, 'forum' => 11, 'author' => 7, 'last_post' => 13, 'solution' => 13, 'subject' => 'Topic']);
        $this->connection->insert('tx_typo3forum_domain_model_forum_post', ['uid' => 13, 'topic' => 12, 'author' => 7, 'author_name' => '', 'text' => 'Post']);
        $this->connection->insert('tx_typo3forum_domain_model_forum_attachment', ['uid' => 14, 'post' => 13, 'name' => 'file.pdf']);
        $this->connection->insert('sys_file_reference', ['uid' => 15, 'uid_local' => 99, 'uid_foreign' => 14, 'tablenames' => 'tx_typo3forum_domain_model_forum_attachment', 'fieldname' => 'referenced_files']);
        $this->insertContent(1, 'list', 'typo3forum_forum', '<same/>');
        $plan = $this->service->plan();
        self::assertSame('READY', $plan['status']);
        self::assertSame('SUCCESS', $this->service->apply($plan, $plan['checksum'])['status']);
        self::assertSame('$argon2id$preserve', $this->connection->fetchOne('SELECT password FROM fe_users WHERE uid = 7'));
        self::assertSame(12, (int)$this->connection->fetchOne('SELECT topic FROM tx_typo3forum_domain_model_forum_post WHERE uid = 13'));
        self::assertSame(99, (int)$this->connection->fetchOne('SELECT uid_local FROM sys_file_reference WHERE uid = 15'));
    }

    public function testCheckAndPlanAreDryRuns(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '<unchanged/>');
        $before = $this->connection->fetchAssociative('SELECT * FROM tt_content WHERE uid = 1');
        $this->service->check();
        $plan = $this->service->plan();
        self::assertSame('READY', $this->service->dryRun($plan)['status']);
        self::assertSame($before, $this->connection->fetchAssociative('SELECT * FROM tt_content WHERE uid = 1'));
        self::assertSame(0, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM tx_typo3forum_migration_journal'));
        self::assertSame(0, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM tx_typo3forum_migration_lock'));
    }

    /** @param array<string, int|string> $overrides */
    private function insertContent(int $uid, string $cType, string $listType, string $flexForm, array $overrides = []): void
    {
        $this->connection->insert('tt_content', array_replace([
            'uid' => $uid, 'pid' => 10, 'CType' => $cType, 'list_type' => $listType, 'pi_flexform' => $flexForm,
            'pages' => '20,21', 'recursive' => 2, 'sys_language_uid' => 0, 'l18n_parent' => 0, 't3ver_oid' => 0,
            't3ver_id' => 0, 't3ver_wsid' => 0, 't3ver_state' => 0, 'hidden' => 0, 'deleted' => 0,
            'starttime' => 0, 'endtime' => 0, 'sorting' => $uid * 10, 'colPos' => 0, 'header' => 'Element ' . $uid,
        ], $overrides));
    }

    /** @param array<string, string> $values */
    private function legacyFlexForm(string $action, array $values = []): string
    {
        $fields = '<field index="switchableControllerActions"><value index="vDEF">' . htmlspecialchars($action, ENT_XML1) . '</value></field>';
        foreach ($values as $name => $value) {
            $fields .= '<field index="' . htmlspecialchars($name, ENT_XML1) . '"><value index="vDEF">' . htmlspecialchars($value, ENT_XML1) . '</value></field>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF">' . $fields . '</language></sheet></data></T3FlexForms>';
    }
}
