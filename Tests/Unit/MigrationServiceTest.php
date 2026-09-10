<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Migration\CanonicalJson;
use Mittwald\Typo3Forum\Migration\FlexFormMigrator;
use Mittwald\Typo3Forum\Migration\MigrationContract;
use Mittwald\Typo3Forum\Migration\MigrationService;
use Mittwald\Typo3Forum\Updates\ForumPluginMigrationUpdate;
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
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_migration_lock (lock_id INTEGER PRIMARY KEY, manifest_checksum TEXT, owner_token TEXT, started_at INTEGER)');
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

    public function testBackendRightsUsePlainV14TokensWhileObsoleteAndAggregateFormatsBlock(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $this->connection->insert('be_groups', ['uid' => 1, 'subgroup' => '2', 'explicit_allowdeny' => 'tt_content:list_type:typo3forum_forum,tt_content:list_type:news_pi1,tt_content:list_type:typo3forum_forum,tt_content:CType:typo3forum_forum']);
        $plan = $this->service->plan();
        self::assertSame('READY', $plan['status']);
        self::assertCount(2, $plan['operations']);
        $this->service->apply($plan, $plan['checksum']);
        self::assertSame('tt_content:CType:typo3forum_forum,tt_content:list_type:news_pi1', $this->connection->fetchOne('SELECT explicit_allowdeny FROM be_groups WHERE uid = 1'));
        self::assertSame('2', $this->connection->fetchOne('SELECT subgroup FROM be_groups WHERE uid = 1'));

        $this->connection->insert('be_groups', ['uid' => 2, 'subgroup' => '', 'explicit_allowdeny' => 'tt_content:list_type:typo3forum_pi1:ALLOW']);
        $this->connection->insert('be_groups', ['uid' => 3, 'subgroup' => '', 'explicit_allowdeny' => 'tt_content:list_type:typo3forum_forum:DENY']);
        self::assertSame('BLOCKED', $this->service->check()['status']);
        self::assertContains('OBSOLETE_ACCESS_MODE_PERMISSION', array_column($this->service->check()['findings'], 'code'));
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
        $preflight = $this->preflight();
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
        $this->connection->insert('tx_typo3forum_migration_lock', ['lock_id' => 1, 'manifest_checksum' => $plan['checksum'], 'owner_token' => 'interrupted', 'started_at' => 1]);
        try {
            $this->service->apply($plan, $plan['checksum']);
            self::fail('An existing lock must stop the default apply path.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('holds the migration lock', $exception->getMessage());
        }
        self::assertSame('SUCCESS', $this->service->apply($plan, $plan['checksum'], true)['status']);
        self::assertSame(0, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM tx_typo3forum_migration_lock'));
    }

    public function testInterruptedRecoveryDoesNotStealARecentActiveLock(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $plan = $this->service->plan();
        $this->connection->insert('tx_typo3forum_migration_lock', ['lock_id' => 1, 'manifest_checksum' => $plan['checksum'], 'owner_token' => 'active', 'started_at' => time()]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('still recent');
        $this->service->apply($plan, $plan['checksum'], true);
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

    public function testBlockedErrorAndIndeterminatePlansNeverVerifyAsSuccess(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_pi1', $this->legacyFlexForm('Unknown->action'));
        $blocked = $this->service->plan();
        self::assertSame('BLOCKED', $blocked['status']);
        self::assertSame('BLOCKED', $this->service->verify($blocked)['status']);
        self::assertContains('PLAN_NOT_VERIFIABLE', array_column($this->service->verify($blocked)['problems'], 'code'));

        foreach (['ERROR', 'INDETERMINATE'] as $status) {
            $plan = $blocked;
            $plan['status'] = $status;
            unset($plan['checksum']);
            $plan['checksum'] = CanonicalJson::checksum($plan);
            self::assertSame($status, $this->service->verify($plan)['status']);
        }
    }

    public function testVerificationRequiresAppliedTargetsAndJournalEvidence(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $plan = $this->service->plan();
        $unexecuted = $this->service->verify($plan);
        self::assertSame('BLOCKED', $unexecuted['status']);
        self::assertContains('TARGET_MISMATCH', array_column($unexecuted['problems'], 'code'));
        self::assertContains('JOURNAL_MISSING', array_column($unexecuted['problems'], 'code'));

        self::assertSame('SUCCESS', $this->service->apply($plan, $plan['checksum'])['status']);
        $this->connection->update('tx_typo3forum_migration_journal', ['rule_id' => 'wrong-rule'], ['manifest_checksum' => $plan['checksum']]);
        self::assertContains('JOURNAL_MISSING', array_column($this->service->verify($plan)['problems'], 'code'));
        $this->connection->delete('tx_typo3forum_migration_journal', ['manifest_checksum' => $plan['checksum']]);
        self::assertContains('JOURNAL_MISSING', array_column($this->service->verify($plan)['problems'], 'code'));
        $this->connection->executeStatement('DROP TABLE tx_typo3forum_migration_journal');
        self::assertContains('JOURNAL_TABLE_MISSING', array_column($this->service->verify($plan)['problems'], 'code'));
    }

    public function testFreshNoOpIsRecheckedAndDoesNotFabricateJournalEvidence(): void
    {
        $plan = $this->service->plan();
        self::assertSame('ALREADY_MIGRATED', $plan['status']);
        self::assertSame('NO_MIGRATION_REQUIRED', $this->service->verify($plan)['status']);
        self::assertSame(0, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM tx_typo3forum_migration_journal'));

        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        self::assertSame('BLOCKED', $this->service->verify($plan)['status']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no-op plan');
        $this->service->apply($plan, $plan['checksum']);
    }

    public function testInconsistentNoOpPlanAndRemovedEvidenceAreRejected(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $ready = $this->service->plan();
        $ready['status'] = 'ALREADY_MIGRATED';
        unset($ready['checksum']);
        $ready['checksum'] = CanonicalJson::checksum($ready);
        try {
            $this->service->verify($ready);
            self::fail('An inconsistent no-op plan must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must not contain operations', $exception->getMessage());
        }

        $preflight = $this->preflight();
        $plan = $this->service->plan($preflight);
        $plan['source_evidence'] = null;
        unset($plan['checksum']);
        $plan['checksum'] = CanonicalJson::checksum($plan);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source-evidence assurance');
        $this->service->verify($plan);
    }

    public function testRechecksummedPreflightCannotOmitMandatoryIntegrityEvidence(): void
    {
        $preflight = $this->preflight();
        unset($preflight['integrity'], $preflight['checksum']);
        $preflight['checksum'] = CanonicalJson::checksum($preflight);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('structure is invalid');
        $this->service->plan($preflight);
    }

    public function testTargetOnlyAssuranceIsDisclosedAndVersionOneEvidenceIsRejected(): void
    {
        $check = $this->service->check();
        self::assertSame('target_only', $check['assurance']);
        self::assertContains('SOURCE_PREFLIGHT_NOT_SUPPLIED', array_column($check['findings'], 'code'));
        self::assertSame('target_only', $this->service->plan()['assurance']);

        $old = ['manifest_format' => 'typo3-forum-preflight/1.0', 'contract_version' => '1.0.0'];
        $old['checksum'] = CanonicalJson::checksum($old);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Version 1 evidence is insufficient');
        $this->service->check($old);
    }

    public function testPermissionOnlyWorkIsReadyWithAndWithoutListTypeColumn(): void
    {
        $this->connection->insert('be_groups', ['uid' => 1, 'subgroup' => '7', 'explicit_allowdeny' => 'tt_content:list_type:typo3forum_forum,tt_content:list_type:news_pi1']);
        self::assertSame('READY', $this->service->check()['status']);
        $plan = $this->service->plan();
        self::assertCount(1, $plan['operations']);
        self::assertSame('be_groups', $plan['operations'][0]['table']);
        self::assertSame('READY', $this->service->dryRun($plan)['status']);
        self::assertSame('SUCCESS', $this->service->apply($plan, $plan['checksum'])['status']);
        self::assertSame('tt_content:CType:typo3forum_forum,tt_content:list_type:news_pi1', $this->connection->fetchOne('SELECT explicit_allowdeny FROM be_groups WHERE uid = 1'));
        self::assertSame('ALREADY_MIGRATED', $this->service->check()['status']);

        $this->connection->update('be_groups', ['explicit_allowdeny' => 'tt_content:list_type:typo3forum_topiclist'], ['uid' => 1]);
        $this->connection->executeStatement('ALTER TABLE tt_content DROP COLUMN list_type');
        self::assertSame('READY', $this->service->check()['status']);
        $permissionPlan = $this->service->plan();
        self::assertSame('SUCCESS', $this->service->apply($permissionPlan, $permissionPlan['checksum'])['status']);
    }

    public function testPermissionSubgroupChangeAfterPlanningIsAConflict(): void
    {
        $this->connection->insert('be_groups', ['uid' => 1, 'subgroup' => '2', 'explicit_allowdeny' => 'tt_content:list_type:typo3forum_forum']);
        $plan = $this->service->plan();
        $this->connection->update('be_groups', ['subgroup' => '3'], ['uid' => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source conflict');
        $this->service->apply($plan, $plan['checksum']);
    }

    public function testWizardUsesSharedPermissionOnlyReadinessAndExecution(): void
    {
        $this->connection->insert('be_groups', ['uid' => 1, 'subgroup' => '', 'explicit_allowdeny' => 'tt_content:list_type:typo3forum_userlist']);
        $wizard = new ForumPluginMigrationUpdate($this->pool, $this->service, new MigrationContract());
        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());
        self::assertFalse($wizard->updateNecessary());
        self::assertSame('tt_content:CType:typo3forum_userlist', $this->connection->fetchOne('SELECT explicit_allowdeny FROM be_groups WHERE uid = 1'));
    }

    public function testPermissionParsingNeverTurnsDenyIntoGrantAndPreservesUnrelatedEntries(): void
    {
        $this->connection->insert('be_groups', ['uid' => 1, 'subgroup' => '2,3', 'explicit_allowdeny' => 'pages:doktype:1,tt_content:list_type:typo3forum_forum:DENY,tt_content:CType:text']);
        $check = $this->service->check();
        self::assertSame('BLOCKED', $check['status']);
        self::assertContains('OBSOLETE_ACCESS_MODE_PERMISSION', array_column($check['findings'], 'code'));
        self::assertSame('pages:doktype:1,tt_content:list_type:typo3forum_forum:DENY,tt_content:CType:text', $this->connection->fetchOne('SELECT explicit_allowdeny FROM be_groups WHERE uid = 1'));
    }

    public function testSourceEvidenceDetectsDeletedOrChangedForumDataAndPermissionChanges(): void
    {
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_domain_model_forum_forum (uid INTEGER PRIMARY KEY, title TEXT)');
        $this->connection->insert('tx_typo3forum_domain_model_forum_forum', ['uid' => 5, 'title' => 'Original']);
        $this->connection->insert('be_groups', ['uid' => 1, 'subgroup' => '', 'explicit_allowdeny' => 'tt_content:list_type:typo3forum_forum']);
        $preflight = $this->preflight();

        $this->connection->update('tx_typo3forum_domain_model_forum_forum', ['title' => 'Changed'], ['uid' => 5]);
        $this->connection->update('be_groups', ['subgroup' => '9'], ['uid' => 1]);
        $check = $this->service->check($preflight);
        self::assertSame('BLOCKED', $check['status']);
        self::assertContains('SOURCE_INTEGRITY_MISMATCH', array_column($check['findings'], 'code'));
        self::assertContains('SOURCE_PERMISSION_CHANGED_AFTER_PREFLIGHT', array_column($check['findings'], 'code'));

        $this->connection->delete('tx_typo3forum_domain_model_forum_forum', ['uid' => 5]);
        self::assertContains('SOURCE_INTEGRITY_MISMATCH', array_column($this->service->check($preflight)['findings'], 'code'));
    }

    public function testAdditiveSchemaFieldsDoNotReplaceOrInvalidateProtectedSourceProjection(): void
    {
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_domain_model_forum_forum (uid INTEGER PRIMARY KEY, title TEXT, legacy_extra TEXT)');
        $this->connection->insert('tx_typo3forum_domain_model_forum_forum', ['uid' => 1, 'title' => 'Protected', 'legacy_extra' => 'source-only']);
        $preflight = $this->preflight();

        $this->connection->executeStatement('ALTER TABLE tx_typo3forum_domain_model_forum_forum ADD COLUMN target_addition TEXT');
        $this->connection->update('tx_typo3forum_domain_model_forum_forum', ['target_addition' => 'new-default'], ['uid' => 1]);

        self::assertSame('ALREADY_MIGRATED', $this->service->check($preflight)['status']);
    }

    public function testSupportedCorePermissionPrerequisiteIsReconciledExplicitly(): void
    {
        $this->connection->insert('be_groups', ['uid' => 1, 'subgroup' => '4', 'explicit_allowdeny' => 'tt_content:list_type:typo3forum_forum']);
        $preflight = $this->preflight();

        $this->connection->update('be_groups', ['explicit_allowdeny' => 'tt_content:CType:typo3forum_forum'], ['uid' => 1]);
        $check = $this->service->check($preflight);

        self::assertSame('ALREADY_MIGRATED', $check['status']);
        self::assertContains('SOURCE_PERMISSION_PREREQUISITE_RESOLVED', array_column($check['resolutions'], 'code'));
        self::assertNotContains('SOURCE_PERMISSION_CHANGED_AFTER_PREFLIGHT', array_column($check['findings'], 'code'));
    }

    public function testSourceBlockerAndNullVersusEmptyRemainVisible(): void
    {
        $this->connection->executeStatement('CREATE TABLE tx_typo3forum_domain_model_forum_post (uid INTEGER PRIMARY KEY, topic INTEGER, text TEXT, author INTEGER, author_name TEXT)');
        $this->connection->insert('tx_typo3forum_domain_model_forum_post', ['uid' => 1, 'topic' => 0, 'text' => null, 'author' => 0, 'author_name' => 'anonymous']);
        $preflight = $this->preflight([['severity' => 'blocker', 'code' => 'PROJECT_SOURCE_BLOCKER']]);
        self::assertSame('BLOCKED', $this->service->check($preflight)['status']);
        self::assertContains('SOURCE_PROJECT_SOURCE_BLOCKER', array_column($this->service->check($preflight)['findings'], 'code'));

        $preflight = $this->preflight();
        $this->connection->update('tx_typo3forum_domain_model_forum_post', ['text' => ''], ['uid' => 1]);
        self::assertContains('SOURCE_INTEGRITY_MISMATCH', array_column($this->service->check($preflight)['findings'], 'code'));
    }

    public function testSourceAcceptanceWarningRemainsVisibleWithoutBlockingSupportedConversion(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $preflight = $this->preflight([['severity' => 'warning', 'code' => 'PROJECT_CONFIGURATION_REVIEW_REQUIRED', 'scope' => 'production_acceptance']]);

        $check = $this->service->check($preflight);

        self::assertSame('READY', $check['status']);
        self::assertContains('SOURCE_PROJECT_CONFIGURATION_REVIEW_REQUIRED', array_column($check['findings'], 'code'));
    }

    public function testDefaultFalStorageAndUnrelatedInfrastructureDoNotInventLegacyForumData(): void
    {
        $this->connection->executeStatement('CREATE TABLE sys_file_storage (uid INTEGER PRIMARY KEY, name TEXT)');
        $this->connection->executeStatement('CREATE TABLE sys_file (uid INTEGER PRIMARY KEY, identifier TEXT)');
        $this->connection->executeStatement('CREATE TABLE fe_users (uid INTEGER PRIMARY KEY, password TEXT)');
        $this->connection->insert('sys_file_storage', ['uid' => 1, 'name' => 'fileadmin']);
        $this->connection->insert('sys_file', ['uid' => 1, 'identifier' => '/unrelated.txt']);
        $this->connection->insert('fe_users', ['uid' => 1, 'password' => 'preserve']);
        $this->connection->executeStatement('ALTER TABLE tt_content DROP COLUMN list_type');
        self::assertSame('ALREADY_MIGRATED', $this->service->check()['status']);
    }

    public function testJournalFailureRollsBackTheProtectedRecordUpdate(): void
    {
        $this->insertContent(1, 'list', 'typo3forum_forum', '');
        $plan = $this->service->plan();
        $this->connection->executeStatement("CREATE TRIGGER reject_migration_journal BEFORE INSERT ON tx_typo3forum_migration_journal BEGIN SELECT RAISE(ABORT, 'journal rejected'); END");
        try {
            $this->service->apply($plan, $plan['checksum']);
            self::fail('Journal failure must abort apply.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('journal rejected', $exception->getMessage());
        }
        self::assertSame('list', $this->connection->fetchOne('SELECT CType FROM tt_content WHERE uid = 1'));
        self::assertSame(0, (int)$this->connection->fetchOne('SELECT COUNT(*) FROM tx_typo3forum_migration_lock'));
    }

    /** @param list<array<string, mixed>> $findings */
    private function preflight(array $findings = []): array
    {
        $check = $this->service->check();
        $preflight = [
            'manifest_format' => 'typo3-forum-preflight/2.0',
            'contract_version' => (new MigrationContract())->version(),
            'captured_at' => gmdate('c'),
            'status' => match (true) {
                $findings === [] => $check['status'],
                in_array('error', array_column($findings, 'severity'), true) => 'ERROR',
                in_array('blocker', array_column($findings, 'severity'), true) => 'BLOCKED',
                in_array('indeterminate', array_column($findings, 'severity'), true) => 'INDETERMINATE',
                default => $check['status'],
            },
            'source' => ['typo3_version' => '12.4', 'extension_version' => '12.x', 'database_driver' => 'sqlite'],
            'schema' => $check['schema'],
            'inventory' => $check['inventory'],
            'integrity' => $check['integrity'],
            'findings' => $findings,
            'scan_limits' => [],
        ];
        $preflight['checksum'] = CanonicalJson::checksum($preflight);
        return $preflight;
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
