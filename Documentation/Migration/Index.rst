TYPO3 v14 forum migration
==========================

Purpose and responsibility boundaries
-------------------------------------

This runbook migrates an existing ``typo3_forum`` data set to the dedicated
TYPO3 v14 content types. It is not a new installation and never invokes the
DDEV demo provisioner. It does not upgrade the complete website, migrate
third-party extensions, publish a release, or approve a production migration.

The implementation status is:

* **IMPLEMENTED:** standalone preflight, validated plan, apply, journal,
  resume, verification, four TYPO3 CLI commands, and a native upgrade wizard.
* **AUTOMATICALLY TESTED:** synthetic SQLite cases in the PHPUnit suite,
  including readiness, evidence, permissions, journals and repeatability.
* **NOT EXECUTED IN THIS IMPLEMENTATION ENVIRONMENT:** DDEV, the opt-in
  MariaDB row-lock test, an actual TYPO3 v12 to v14 site upgrade, and
  browser/frontend acceptance.
* **PROJECT-SPECIFIC DECISION OPEN:** unknown pi1 actions, aggregate pi1
  permissions, Page TSconfig, Backend Layout, Content Defender/container,
  routes, Fluid/TypoScript overrides, remote FAL and third-party login/profile
  integrations.
* **PRODUCTION ACCEPTANCE OPEN:** backup restore rehearsal, maintenance window,
  the real installation run, and functional acceptance.

Supported source states
-----------------------

The migration recognizes mixed sets of hidden, deleted, translated and
workspace records:

* nine TYPO3 v12 list plugins stored as ``CType=list`` with an exact known
  ``list_type``;
* already migrated dedicated CTypes;
* the historical ``typo3forum_pi1`` configurations ``Post->list`` and
  ``Topic->list``;
* a fresh installation with neither legacy plugin records nor forum data.

Ordinary TYPO3 infrastructure (a default FAL storage, unrelated files or
frontend users) is not evidence of an old forum. Populated forum domain tables
without ``list_type`` or source evidence remain indeterminate, and explicitly
known legacy content UIDs remain blocking.

The historical pi1 rules originate from
``AgenturPottkinder/typo3_forum`` commit
``39c51ae1c406f2d3002596442a0e6b40eea8ee77``, file
``Configuration/FlexForms/Pi1.xml``. ``Post->list`` maps to
``typo3forum_postlist``. ``Topic->list`` maps to
``typo3forum_topiclist`` and renames ``settings.maxTopicItems`` to the current
``settings.maxItems``. The obsolete switchable-action field is removed; all
other allowed values, sheets and languages are preserved.

Other pi1 actions are deliberately blocked. Historical profile actions also
edited/disabled users, and other choices included private messages, favorites
or MyTags that have no proven v14 equivalent. The migration does not infer a
target from a page title, header or a similar individual action. A malformed
FlexForm, multiple language-dependent actions, an unknown non-empty field,
``typo3forum_widget``, or a project-specific signature also blocks execution.

The nine standard mappings are one-to-one:

.. code-block:: text

   typo3forum_forum             -> typo3forum_forum
   typo3forum_userprofile       -> typo3forum_userprofile
   typo3forum_moderationreports -> typo3forum_moderationreports
   typo3forum_userlist          -> typo3forum_userlist
   typo3forum_dashboard         -> typo3forum_dashboard
   typo3forum_taglist           -> typo3forum_taglist
   typo3forum_postlist          -> typo3forum_postlist
   typo3forum_topiclist         -> typo3forum_topiclist
   typo3forum_statsbox          -> typo3forum_statsbox

The identifiers intentionally stay equal; the migration changes their storage
and interpretation from ``tt_content.CType=list`` plus
``tt_content.list_type=<identifier>`` to
``tt_content.CType=<identifier>`` plus an empty ``list_type``. It is therefore
not an identity update even though the strings in the mapping are equal.

Source preflight before the target upgrade
------------------------------------------

The release archive contains
``Resources/Private/Migration/preflight.php`` and ``contract-v1.json``. Copy
those two files together to a protected administration host. The tool is
independent of TYPO3/vendor code and supports PHP 8.1 or newer with PDO MySQL
or PDO SQLite. MariaDB is accessed through PDO MySQL. It performs only metadata
and SELECT queries, streams table fingerprints, and writes only when an
explicit ``--output`` path is supplied. The destination directory must already
exist; the report is created with mode ``0600``.

Use a read-only database account and environment variables for credentials:

.. code-block:: bash

   export FORUM_MIGRATION_DSN='mysql:host=db.example.invalid;port=3306;dbname=site;charset=utf8mb4'
   export FORUM_MIGRATION_DB_USER='forum_preflight_reader'
   read -rs FORUM_MIGRATION_DB_PASSWORD
   export FORUM_MIGRATION_DB_PASSWORD
   php Resources/Private/Migration/preflight.php \
     --output=/srv/secure/forum-preflight.json \
     --project-root=/srv/site \
     --expected-legacy-uids=41,73 \
     --typo3-version=12.4 \
     --extension-version=12.0.0

Never put a password in the DSN or process arguments. Keep the JSON outside the
webroot. It contains record/FlexForm migration details and therefore must be
handled as sensitive even though passwords and password hashes are not emitted.

Exit codes are stable: ``0 READY``, ``10 ALREADY_MIGRATED``, ``20 BLOCKED``,
``30 INDETERMINATE``, and ``40 ERROR``. SQL/permission failures are errors, not
empty inventories. A missing ``list_type`` is reported separately from a
verified empty source. Possible renamed columns are inventory evidence only;
they are never adopted as migration sources automatically.
``--expected-legacy-uids`` is optional, but should be supplied when an earlier
inventory proves that specific legacy content records existed. If
``list_type`` is now missing, those UIDs make the result ``BLOCKED`` instead
of allowing a false success. The option does not authorize use of a renamed
column.

The four target commands use the same status exits: ``0`` for
``READY``/``SUCCESS``, ``10`` for ``ALREADY_MIGRATED`` or the verifier's
``NO_MIGRATION_REQUIRED``, ``20`` for ``BLOCKED``, ``30`` for
``INDETERMINATE`` and ``40`` for ``ERROR``/unknown status. Console argument or
bootstrap exceptions remain command failures rather than migration statuses.

The current envelope is ``typo3-forum-preflight/2.0`` with migration contract
``2.0.0``. Version 1 evidence and plans lack the required protected projections
and permission evidence. They are rejected and must be regenerated; their
checksums, approvals and existing journal rows are never silently rewritten.

The preflight records a versioned source baseline. Planning on v14 additionally
records an immediate pre-apply target baseline. The source baseline detects
loss or unexpected changes during the website upgrade; the target baseline
protects the interval between planning and applying. Additive target columns
outside the defined projections do not conflict, while null, empty and changed
protected values remain distinguishable. A supported Core conversion of a
plain list-type grant can be recorded as an explicit resolution. Other changed
source findings or permissions remain blocking. A target-only plan is labelled
``target_only`` and is not end-to-end proof of a source upgrade.

The project scan is deliberately bounded to static PHP, TypoScript, TSconfig,
YAML, XML, HTML and JSON files up to 2 MB, excluding generated/dependency
directories. It can locate references but cannot prove that dynamic database
configuration, imported configuration or external services are complete.

Backup and maintenance gate
---------------------------

Before changing the website, create and test a coherent backup of:

* the database;
* all local files and FAL storages;
* project code, Composer lock/dependency state and environment configuration;
* site configuration, routes, TypoScript, TSconfig and extension configuration.

Plan a write freeze and maintenance window covering the final plan, apply and
verification. A Git checkout is not a database rollback. Rollback means
restoring the matching code, database and file/storage snapshot. Do not use the
migration journal as a general undo log after new production writes.

Website/core upgrade ordering
-----------------------------

The forum v14 runtime is TYPO3 v14-only. The standalone preflight exists so the
v14 extension need not be loaded in TYPO3 v12. Preserve ``tt_content.list_type``
and ``pi_flexform`` through the core upgrade until the forum conversion is
verified. Follow all TYPO3 major-upgrade changes and documented Core upgrade
steps. The forum migration does not require installing a v13-compatible forum
runtime, but it also does not prove that deploying a complete website directly
from v12 code to v14 code is safe. A v12-to-v14 deployment still has to account
for both intervening Core change sets and validate all third-party/project
dependencies separately.

Under TYPO3 14.3, first run additive schema updates so the two journal/lock
tables exist, but do not run destructive schema cleanup that removes
``list_type``. ``DatabaseUpdatedPrerequisite`` performs blocking additive
operations. The forum conversion must run before later destructive cleanup:

.. code-block:: bash

   vendor/bin/typo3 extension:setup --extension=typo3_forum
   vendor/bin/typo3 upgrade:list

Check the exact commands offered by the installed TYPO3 version before the
maintenance window. Relevant public APIs were verified against TYPO3 14.3.7:
``TYPO3\\CMS\\Core\\Upgrades\\AbstractListTypeToCTypeUpdate``,
``UpgradeWizardInterface``, ``UpgradeWizard`` and
``DatabaseUpdatedPrerequisite``. The public Core namespaces are used; removed
Install-namespace aliases are not used.

Check, plan, approve and apply
------------------------------

Transfer the protected preflight manifest to the upgraded installation, still
outside the webroot. All output paths below are explicit and protected:

.. code-block:: bash

   vendor/bin/typo3 forum:migration:check \
     --preflight=/srv/secure/forum-preflight.json \
     --output=/srv/secure/forum-check.json

   vendor/bin/typo3 forum:migration:plan \
     --preflight=/srv/secure/forum-preflight.json \
     --output=/srv/secure/forum-plan.json

Read every finding and review the operations. ``READY`` is required for a
write plan. ``ALREADY_MIGRATED`` means that neither supported content nor
permission work is pending and no unresolved blocker exists. Permission-only
work is ``READY`` and keeps the native wizard necessary. ``BLOCKED``,
``INDETERMINATE`` and ``ERROR`` can never verify as success merely because
their operation list is empty. A checksum
detects accidental modification but is not authentication; protect the plan
and validate its provenance. The implementation accepts only known tables,
fields, rule versions and target CTypes, rebuilds the current scope before
writing, and never evaluates code or SQL from a manifest.

Run a non-mutating apply validation:

.. code-block:: bash

   vendor/bin/typo3 forum:migration:apply \
     --plan=/srv/secure/forum-plan.json \
     --dry-run \
     --output=/srv/secure/forum-dry-run.json

After the maintenance/write freeze starts, pass the exact plan checksum as the
explicit approval:

.. code-block:: bash

   vendor/bin/typo3 forum:migration:apply \
     --plan=/srv/secure/forum-plan.json \
     --confirm='<exact-plan-checksum>' \
     --output=/srv/secure/forum-apply.json

The process owns a tokenized global migration lock through final verification.
Each source row is re-read under a transaction and ``FOR UPDATE`` on the
supported MySQL/MariaDB platform before the approved values are written. This
prevents a stale overwrite after planning; it does not make an online migration
safe, so the maintenance write freeze remains mandatory. Each record change and its fingerprint-only
journal row commit in the same database transaction; configurations that route
the journal to another database connection are rejected. A rerun skips only a
target record with the matching journal entry. Missing journals, changed
sources, new scope, unsupported operations and partial/unexpected states stop
the run. No DDL transaction guarantee is claimed.

An abrupt process termination deliberately leaves the lock in place. First
prove independently that no migration process is still running and that the
maintenance write freeze remains active. Only the same protected plan and
checksum may then recover the lock and resume journaled steps:

.. code-block:: bash

   vendor/bin/typo3 forum:migration:apply \
     --plan=/srv/secure/forum-plan.json \
     --confirm='<exact-plan-checksum>' \
     --resume-interrupted \
     --output=/srv/secure/forum-resume.json

The recovery flag never steals a lock belonging to another checksum or owner.
A lock younger than one hour is treated as active and cannot be recovered.
After independently proving the process dead, a stale same-plan lock is claimed
with a compare-and-swap update. Only the claiming owner can remove it. Do not
use recovery merely because a concurrent run is slow.

Native wizard
-------------

The wizard identifier is ``typo3ForumVerifiedPluginMigration``. It extends the
TYPO3 v14 ``AbstractListTypeToCTypeUpdate`` infrastructure for the verified
mapping and uses the same planner/executor as the CLI. Its check is read-only.
Running the wizard is the explicit write approval; it cannot bypass pi1,
permission, fingerprint, scope, journal or integrity safeguards. Blocked or
indeterminate remnants keep the wizard necessary and prevent successful
registry completion.

Permissions and project configuration
-------------------------------------

Standard plain v12+ ``be_groups.explicit_allowdeny`` entries such as
``tt_content:list_type:typo3forum_forum`` are converted one-to-one to the valid
v14 representation ``tt_content:CType:typo3forum_forum``. Unrelated entries
and subgroup relations stay unchanged. The obsolete fourth ``ALLOW``/``DENY``
tuple component is not valid v14 authorization semantics. Such entries block
with an instruction to run/review the appropriate Core access normalization;
``DENY`` is never stripped and reinterpreted as a grant. A historical aggregate pi1/widget
permission is not expanded to all new plugins because that would broaden
access; it requires a documented project decision. ``subgroup`` relationships
are inventoried and are not rewritten.

Backend Layouts, Page TSconfig, container/content-defender rules, routes,
slugs, old namespaces, scheduler configuration and Fluid/TypoScript overrides
are reported for manual review. There is no repository-wide regex rewrite.
Third-party login/profile extensions such as ``sr_feuser_register`` are outside
the forum migration.

Verification and acceptance
----------------------------

Run integrity verification before any write-capable functional test:

.. code-block:: bash

   vendor/bin/typo3 forum:migration:verify \
     --plan=/srv/secure/forum-plan.json \
     --output=/srv/secure/forum-verify.json

The verifier first rejects non-executable plan statuses and rechecks the
current supported scope, so new legacy content or permission work cannot hide
behind an old no-op plan. For every planned write it requires both exact target
values and the matching plan journal; a missing journal table or row is a
failure. A genuine fresh installation needs no fabricated journal. Successful
results distinguish ``NO_MIGRATION_REQUIRED`` from completed ``SUCCESS``.

It also checks ordered, versioned projections of forum tables, frontend-user
password hashes, forum FAL reference identities,
and defined relations among forums, topics, posts, authors, attachments,
subscriptions, read status and moderation records. Counts alone are not used
as proof. Remote storage availability remains ``NOT_CHECKED`` and must be
tested without uncontrolled downloads or counter changes. The separate
``PostsWithoutAuthorNameUpdate`` wizard remains independent.

These checks prove only the declared projection and relationship scope. They do
not prove remote-storage reachability, project configuration, historical URL
compatibility, frontend behavior, the whole TYPO3 upgrade or production
acceptance. Unsupported comparison evidence is reported as indeterminate, not
silently replaced with a new baseline.

On a disposable clone, then test forum/topic/post rendering and CRUD, existing
login, permissions, profiles/lists, moderation, attachments, URLs/routes,
BBCode preview, mail capture, scheduler commands, languages and workspaces.
Never send real mail. Compare countries with TYPO3's current expected codes but
do not guess or mass-convert unknown values. Only after these checks may an
operator consider destructive schema cleanup.

Current verification status
---------------------------

The current local baseline used PHP 8.4.25, TYPO3 14.3.7 and PHPUnit 11.5.56.
The full suite passed 145 tests with 1,215 assertions and one skip. The skip is
the database-specific locking case because no disposable MariaDB/MySQL service
was available; SQLite coverage is not presented as row-lock proof. On an
explicitly disposable InnoDB database, run it with two independent connections:

.. code-block:: bash

   FORUM_MIGRATION_MARIADB_TEST_CONFIRM=disposable \
   FORUM_MIGRATION_MARIADB_TEST_DSN='mysql://test_user:local_password@127.0.0.1:3306/forum_migration_test?charset=utf8mb4' \
   vendor/bin/phpunit --testsuite 'Migration integration'

The standalone preflight was exercised with PDO SQLite on PHP 8.4.25. A PHP
8.1/8.2 binary was not available in this environment, so the documented PHP
8.1 minimum was not separately runtime-tested here.

Composer validation, syntax checks (217 PHP files), PHPStan and TypoScript lint
passed. TypoScript lint scanned ``Configuration/TSconfig/pageTS.txt``,
``Configuration/page.tsconfig`` and both files under
``Configuration/TypoScript``; it reported 12 pre-existing warnings. The full
``composer ci`` aggregate still exits 8 solely at the final PHP-CS-Fixer dry
run because 139 of 206 files contain existing style debt. No repository-wide
formatting was performed.

The previous single PHPUnit deprecation was traced to this extension's smiley
parser calling the deprecated internal Core
``PathUtility::getPublicResourceWebPath()``. It now uses TYPO3 14.3's system
resource factory/publisher, and the full PHPUnit run reports no deprecations.
No production migration or real v12-to-v14 installation test was executed.

Official references
-------------------

* `TYPO3 14.3 update-wizard examples <https://docs.typo3.org/m/typo3/reference-coreapi/14.3/en-us/ExtensionArchitecture/HowTo/UpdateExtensions/UpdateWizards/Examples.html>`__
* `TYPO3 v14 upgrade-wizard namespace change <https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/14.0/Deprecation-106947-MoveUpgradeWizardRelatedInterfacesAndAttributeToEXTcore.html>`__
* `Plugin/content-element subtype deprecation <https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/13.4/Deprecation-105076-PluginContentElementAndPluginSubTypes.html>`__
* `TYPO3 14.3 major upgrade guide <https://docs.typo3.org/m/typo3/reference-coreapi/14.3/en-us/Administration/Upgrade/Major/Index.html>`__
* `TYPO3 v12 simplified access-mode system <https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/12.0/Breaking-97265-SimplifiedAccessModeSystem.html>`__
