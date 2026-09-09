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
* **AUTOMATICALLY TESTED:** synthetic SQLite cases in the PHPUnit suite.
* **NOT EXECUTED IN THIS IMPLEMENTATION ENVIRONMENT:** DDEV, MariaDB, an actual
  TYPO3 v12 to v13 to v14 site upgrade, and browser/frontend acceptance.
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
verified. Follow TYPO3's supported major-upgrade path and all documented core
upgrade steps; this forum tool does not approve an arbitrary direct 12-to-14
jump or upgrade other extensions.

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

Read every finding and review the operations. ``READY`` is required. A checksum
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

Every source fingerprint is checked immediately before writing. A single-row
lock prevents concurrent writers. Each record change and its fingerprint-only
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

The recovery flag never steals a lock belonging to another checksum. Do not
use it merely because a concurrent run is slow.

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

Standard ``be_groups.explicit_allowdeny`` list-type restrictions are converted
one-to-one, preserving ``ALLOW``/``DENY`` suffixes and unrelated tokens.
Contradictory target tokens block the plan. A historical aggregate pi1/widget
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

The verifier checks exact migrated values and journals, ordered fingerprints of
forum tables, frontend-user password hashes, forum FAL reference identities,
and defined relations among forums, topics, posts, authors, attachments,
subscriptions, read status and moderation records. Counts alone are not used
as proof. Remote storage availability remains ``NOT_CHECKED`` and must be
tested without uncontrolled downloads or counter changes. The separate
``PostsWithoutAuthorNameUpdate`` wizard remains independent.

On a disposable clone, then test forum/topic/post rendering and CRUD, existing
login, permissions, profiles/lists, moderation, attachments, URLs/routes,
BBCode preview, mail capture, scheduler commands, languages and workspaces.
Never send real mail. Compare countries with TYPO3's current expected codes but
do not guess or mass-convert unknown values. Only after these checks may an
operator consider destructive schema cleanup.

Official references
-------------------

* `TYPO3 14.3 update-wizard examples <https://docs.typo3.org/m/typo3/reference-coreapi/14.3/en-us/ExtensionArchitecture/HowTo/UpdateExtensions/UpdateWizards/Examples.html>`__
* `TYPO3 v14 upgrade-wizard namespace change <https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/14.0/Deprecation-106947-MoveUpgradeWizardRelatedInterfacesAndAttributeToEXTcore.html>`__
* `Plugin/content-element subtype deprecation <https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/13.4/Deprecation-105076-PluginContentElementAndPluginSubTypes.html>`__
* `TYPO3 14.3 major upgrade guide <https://docs.typo3.org/m/typo3/reference-coreapi/14.3/en-us/Administration/Upgrade/Major/Index.html>`__
