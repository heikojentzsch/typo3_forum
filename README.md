# TYPO3 Forum

`typo3_forum` is a frontend discussion board extension for TYPO3. It was originally developed by Mittwald CM Service and is currently maintained by Agentur Pottkinder.

The extension provides nine frontend plugins:

- **Dashboard** – personal data, subscriptions, posts and notifications.
- **Forum** – forums, subforums, topics and posts.
- **Forum Statistics Box** – member, post and topic statistics.
- **Moderation: Manage reports** – moderation workflow for reported content.
- **Post List** – post listings including rendered previews.
- **Tag List** – topic tagging and tag-based navigation.
- **Topic List** – latest, popular and question-based topic listings.
- **User List** – user listings including helpful and currently online users.
- **User Profile** – user profiles, user fields, rank and activity.

## TYPO3 v14 migration

The `v14` branch is an active, **pure TYPO3 v14 migration**. It intentionally does not provide compatibility layers for TYPO3 v12 or v13.

### Target platform

- PHP `^8.2`
- TYPO3 CMS `^14.3`
- PHPUnit `^11.5`
- Extension key: `typo3_forum`

The Composer requirements and `ext_emconf.php` are aligned to TYPO3 14.3 and PHP 8.2+.

## Migration status

### Completed

- [x] Modernize Composer QA commands and adopt PHPStan 2 at level 6.
- [x] Replace authoritative GitLab CI and remove Travis.
- [x] Add deterministic release packaging and gated GitLab-to-GitHub publication.
- [x] Add minimal public GitHub verification.
- [x] Rebuild the minimal DDEV environment for TYPO3 14.3 / PHP 8.4.

- [x] Set TYPO3 v14 / PHP 8.2 platform requirements.
- [x] Make the extension bootable on TYPO3 v14.
- [x] Replace `static-info-tables` country handling with the TYPO3 Core Country API.
- [x] Migrate plugins from legacy `list_type` usage to dedicated content types (`CType`).
- [x] Migrate Extbase controller validation annotations to PHP attributes.
- [x] Migrate Extbase model annotations to PHP attributes.
- [x] Remove legacy TSFE usage from frontend components.
- [x] Remove obsolete controller-context dependencies.
- [x] Replace legacy static CObject/ViewHelper rendering with TYPO3 v14 rendering services.
- [x] Migrate quote rendering from removed `StandaloneView` usage to `ViewFactoryInterface` / `ViewFactoryData`.
- [x] Remove obsolete text parser legacy code.
- [x] Replace removed / legacy Extbase repository APIs with TYPO3 v14-compatible repository calls.
- [x] Fix invalid repository calls and modernize repository query handling.
- [x] Modernize repository persistence/query settings while preserving storage-page behavior.
- [x] Modernize the unit and repository test infrastructure for PHPUnit 11.
- [x] Modernize the active BBCode AJAX preview:
  - keep the existing `typeNum = 43568275` request contract,
  - replace direct `Extbase\Core\Bootstrap->run` usage with `EXTBASEPLUGIN`,
  - keep the existing `tx_typo3forum_ajax[text]` request namespace,
  - return a PSR-7 response from `AjaxController::previewAction()`.
- [x] Resolve identified TYPO3 v14 P0 compatibility issues, including DBAL API usage, CLI TypoScript loading, upgrade-wizard APIs and PSR-7 download responses.
- [x] Resolve identified P1 runtime/code issues, including controller responses, redirects, attachment handling, nullability, DI and subscriber notification behavior.
- [x] Resolve identified P2 legacy-code issues, including validators, unread-topic SQL, service usage and dead utilities.
- [x] Remove confirmed dead code and obsolete assets, including old SCEditor files, obsolete CSH files/templates and unused dependencies.
- [x] Complete the TYPO3 14.3 Fluid/rendering graph audit and implement the demonstrated rendering compatibility fixes.
- [x] Complete the broader repository-wide TYPO3 v14 legacy/deprecation scan and targeted cleanup.
- [x] Remove the obsolete custom controller context (`CONTEXT_WEB`, `CONTEXT_AJAX`, `CONTEXT_CLI`, `$context`, `setContext()`).
- [x] Remove obsolete Signal/Slot remnants while retaining active PSR-14 event behavior.
- [x] Replace invalid temporary-array-reference patterns such as `array_shift(explode(...))`.
- [x] Migrate `IfSubscribedViewHelper` from repository service-location to constructor DI.
- [x] Review Extbase entity service-locator fallbacks and retain those still required for hydration/manual construction.
- [x] Review the quote parser's internal TypoScript conversion dependency and retain it where no semantically equivalent public TYPO3 v14 API exists.
- [x] Fix broken backend TCA icon references.
- [x] Add explicit handling for installations without a default FAL storage.
- [x] Add a verified installation-migration path with standalone preflight, validated plans, four CLI commands, a native TYPO3 v14 wizard, journaled/repeatable writes and integrity verification.

### Deliberate TYPO3 v14 decisions

- `TYPO3\CMS\Extbase\Persistence\Generic\LazyLoadingProxy` is **intentionally retained**. TYPO3 v14.3 still contains and uses it; replacing it belongs to a later TYPO3 v15 migration, not to this branch.
- The BBCode preview continues to use the existing frontend `PAGE` / `typeNum` endpoint. The migration removes the legacy direct Extbase bootstrap call without introducing a new routing subsystem or changing the public request contract.
- Repository query property names use Extbase **domain property names**, not raw database column names, except where deliberately using QueryBuilder / SQL.
- TYPO3 v14-specific APIs are preferred over backwards-compatibility shims.
- Refactors and unrelated latent bug fixes are kept separate from migration work whenever possible.
- `clearCachePostProc` was reviewed against TYPO3 14.3 and is **intentionally retained**. TYPO3 v14 still invokes this hook and no semantically equivalent migration is required.
- `ConfigurableEntityTrait` and `FrontendUser` retain selected `GeneralUtility::makeInstance()` fallbacks because Extbase hydration can instantiate entities without their constructors and later call `initializeObject()`. Removing these fallbacks would change currently supported construction paths.
- `QuoteParserService` intentionally retains `TypoScriptService::convertTypoScriptArrayToPlainArray()`. The API is internal, but the available public TYPO3 14.3 alternatives do not provide equivalent conversion semantics for the evaluated `plugin.tx_typo3forum.settings` subtree. This dependency should be reconsidered in a future TYPO3 major-version migration rather than replaced with another internal API or an incomplete custom converter.

## Completed Fluid / frontend-rendering audit

The audit covered all **65 pre-audit Fluid files** (33 templates, 31 partials, one layout), all **24 custom ViewHelpers**, every registered plugin/action, controller view assignments, TypoScript view paths, manual quote views, mail rendering, AJAX preview, forms, links, pagination and local resource references.

The shipped graph now contains **59 Fluid files**: 28 templates, 30 partials and one layout. The missing `Report/NewUserReport` template was added. Seven proven dead files were removed: the backend `Update/Form` scaffold, `User/New`, `User/Edit`, `User/ListOnlineUsers`, `Post/Preview`, `Default/Error` and its exclusive `Exception` partial. All public custom ViewHelpers were retained.

Implemented fixes include:

- current Fluid condition, argument and variable-provider APIs;
- safe pagination variable scopes;
- avatar dimensions/resource URLs;
- escaped user/rootline links using the correct plugin targets;
- core textarea/select rendering from the custom form ViewHelpers;
- correct Extbase field names, selections and form-token registration;
- preservation of the existing markItUp editor and AJAX preview contract, including empty/zero text handling;
- missing user-report rendering;
- type-specific moderation forms;
- tag validation feedback;
- broken profile/tag/subscription/pagination links;
- quote ViewFactory settings and configured template paths while preserving intentionally parsed HTML;
- PSR-7 upload objects through validation and FAL;
- TYPO3 v14 storage repository and duplication enum usage;
- stale frontend/report resource paths.

No frontend redesign, Bootstrap upgrade, pagination redesign, repository/domain refactor or LazyLoadingProxy migration was performed. Mail subjects/bodies use language strings and mailing services, not separate Fluid mail templates.

This completes the repository-level Fluid audit, **not real-site acceptance testing**.

## Completed TYPO3 v14 legacy/deprecation scan

A repository-wide follow-up scan was performed after the Fluid migration.

The scan covered:

- removed and deprecated TYPO3 APIs;
- Extbase controller patterns;
- service-locator usage;
- DBAL patterns;
- frontend/backend globals;
- obsolete Signal/Slot remnants;
- PHP temporary-reference patterns;
- backend resource references;
- FAL storage assumptions;
- custom ViewHelper dependency handling;
- intentionally retained internal TYPO3 dependencies.

### Controller context

The old custom controller context layer was fully removed:

- `CONTEXT_WEB`
- `CONTEXT_AJAX`
- `CONTEXT_CLI`
- `$context`
- `setContext()`

No production callers or configuration references remained. HTML redirects continue to use Extbase's redirect behavior while non-HTML requests preserve their existing rendered-response fallback.

### Signal/Slot remnants

Obsolete commented Signal/Slot dispatcher calls and already-completed migration TODOs were removed.

Active PSR-14 dispatches remain unchanged.

One separate `ReportController` TODO remains intentionally because the corresponding event dispatch has **not** yet been implemented. Adding a new application event would be a behavioral change and was outside the cleanup scope.

### PHP temporary-array patterns

Invalid or undesirable patterns such as:

```php
array_shift(explode(...));
array_pop(explode(...));
```

were replaced with local arrays while preserving existing first-/last-element semantics.

`Report::getFirstComment()` was similarly changed so that reading the first comment does not mutate the underlying `ObjectStorage`.

### ViewHelper dependency injection

`IfSubscribedViewHelper` now receives `FrontendUserRepository` through constructor injection.

The previous `GeneralUtility::makeInstance()` repository lookup and an unused `ForumRepository` dependency were removed.

Parsed and compiled Fluid rendering paths are covered by tests.

### Entity dependency fallbacks

Service-locator-style fallbacks in domain entities were reviewed but **not blindly removed**.

Extbase's DataMapper can instantiate persisted entities without running their constructors and invoke `initializeObject()` afterwards. `FrontendUser` therefore still requires its `RankRepository` fallback when no dependency has already been assigned.

`ConfigurableEntityTrait` also keeps its lazy `ConfigurationBuilder` fallback. The trait is used by `FrontendUser`, `Topic` and `Attachment`, and not every direct/manual construction path guarantees that injected settings have already been supplied.

`SettingsHydrator` currently has no repository consumer or registration that guarantees hydration for every entity. Any redesign of these paths belongs in a separate architecture task.

### Quote parser TypoScript configuration

`QuoteParserService` still uses:

```php
TypoScriptService::convertTypoScriptArrayToPlainArray()
```

after explicit review.

The following TYPO3 14.3 alternatives were examined and were not equivalent:

- `FrontendTypoScript::getSetupArray()` provides the source setup but retains dotted TypoScript-array representation.
- `FrontendTypoScript::getFlatSettings()` has different source and flattening semantics.
- Extbase `ConfigurationManagerInterface` is itself internal and depends on Extbase request/plugin state.
- Frontend TypoScript AST accessors are internal.
- `ConfigurationBuilder::getSettings()` does not perform the required plain-array conversion.

The current converter preserves nested values including scalar-plus-child `_typoScriptNodeValue` semantics. It therefore remains as a consciously accepted internal TYPO3 v14 dependency rather than being replaced with another internal API or a behaviorally different custom implementation.

### Backend resources

The two previously missing TCA icon references were repaired:

- statistics summary records use TYPO3 Core `content-widget-chart.svg`;
- notification records use TYPO3 Core `content-message.svg`.

The backend configuration resource scan now resolves all **17 literal image references**.

### Attachment storage

`AttachmentService` now explicitly checks the result of:

```php
StorageRepository::getDefaultStorage()
```

before processing a real upload.

If no default FAL storage exists, the service throws a clear `RuntimeException` instead of failing later through a null dereference.

Empty uploads and `UPLOAD_ERR_NO_FILE` continue to work without requiring a configured storage.

## Development and release tooling

**Private GitLab is the authoritative development repository and CI/CD source of truth. GitHub is the public Open Source publication mirror. GitLab CI is authoritative; GitHub Actions provides public verification only.**

### Composer and static analysis

Run `composer install` first. Composer is the canonical local and CI interface:

| Command | Purpose / last local result |
| --- | --- |
| `composer validate` | Valid metadata. |
| `composer php-lint` | 217 PHP files pass syntax checks, including root metadata, application, configuration, tests and build helpers. |
| `composer test` | 145 tests, 1,215 assertions, one explicitly gated MariaDB test skipped. |
| `composer phpstan` | PHPStan 2.2.13, level 6, zero findings. |
| `composer typoscript-lint` | Four real files scanned; exit 0 with 12 existing warnings. |
| `composer cs-check` | PHP-CS-Fixer 3.95.25, non-mutating dry run; exit 8, findings in 139 of 206 files. |
| `composer ci` | Runs all of the above, with style last; currently exits 8 because of style debt. |

All newly added PHP helpers/tests pass the style rules. Existing application files have not been globally reformatted; normalization remains separate work. GitLab reports the style job as advisory only for exit 8. Other style tool/configuration failures and all core QA failures still block the pipeline.

PHPStan uses Composer autoloading, scans `Classes` and `Configuration`, and caches only in `.Build/phpstan`. The broad `.Build` source scan is removed. Tests and build helpers are covered by PHPUnit and syntax checks rather than being included in the production static-analysis scope. No baseline was generated. Collection/repository generics and missing type information were corrected. PHPDoc certainty is disabled for hydrated Extbase values; native types remain checked. Eight line-specific exceptions document the UID-zero virtual entities, retained lazy-loading/hydration paths, existing storage-PID string contract, merged userfield-array contract and intentionally disabled editor cache.

Psalm 4 failed under PHP 8.4 before analysis and had no demonstrated distinct coverage; its dependency/configuration were removed. PHPMD 2.15 ran but predominantly reported historical naming, size and design noise; it was removed rather than suppressing a large report. PHPStan remains the maintained analyzer.

The separately authorized PHPStan follow-up also fixes confirmed defects: mail delivery now calls TYPO3's injected mailer; missing userfield values return an empty array; slug queries use DBAL 4's integer parameter enum; solution-point defaults are applied before casting. Targeted regression tests cover these cases. Existing storage-PID behavior is preserved.

Composer metadata decisions: retain `pottkinder/typo3forum`, `typo3-ter/typo3_forum` replacement, `.Build/vendor`, `.Build/bin`, the TYPO3 installer/alias-loader plugin permissions and supported `.Build/Web` web-dir. All dependencies resolve through Packagist, so the additional composer.typo3.org repository was removed. Prefer dist archives; remove the stale `dev-master` alias and unused `cms-package-dir` extra. As before, this extension does not track a root lock file: each runtime resolves compatible dependencies. Archive reproducibility refers to identical source bytes, not permanently frozen dependency resolution. The verified local install uses TYPO3 14.3.7; Composer audit reports no advisories.

### CI and publication

`.gitlab-ci.yml` runs core QA and TYPO3 CLI smoke checks on PHP 8.2 and 8.4, with a separate style job, a package stage and publication jobs. Official PHP CLI images are bootstrapped with the needed extensions and checksum-verified Composer 2. Composer downloads are cached by PHP version; vendor directories are not shared between runtimes. Travis and its historical release configuration are removed.

Protected release tags publish internal Composer metadata through `${CI_API_V4_URL}/projects/${CI_PROJECT_ID}/packages/composer` using `CI_JOB_TOKEN`; the project's Package Registry must be enabled. Internal publication does not require GitHub.

Public publication is opt-in and manual in GitLab. Configure protected/masked `GITHUB_TOKEN` (only the chosen public repository; Contents write and Workflows write if mirroring workflow changes), `GITHUB_REPOSITORY` (`owner/repository`) and `PUBLIC_RELEASE_ENABLED=true`. Protect release tags and permit the maintainer identity to push the public `v14` mirror. No credentials belong in repository files.

After QA/package success, a maintainer runs `publish-github`. It checks the artifact checksum, verifies that the tagged commit belongs to `v14`, then atomically pushes `v14` and the exact tag without force. Divergence or existing conflicting tags fail for human resolution. It creates a draft GitHub Release with the ZIP/checksum, then publishes it (marking prerelease versions accordingly). A failure after draft creation leaves a draft for inspection; an existing release is never silently overwritten. Only `v14` and release tags are mirrored, not private development branches. No public release was executed during this task.

`.github/workflows/ci.yml` runs on pull requests and pushes to `v14`: PHP 8.4 installation, Composer validation, syntax, PHPUnit, PHPStan and TypoScript lint. It has read-only permissions and no packaging/publication role. GitLab/GitHub YAML and shell syntax were validated locally; no private GitLab execution or GitHub Actions success is claimed here. PHP 8.2 is configured in CI, not locally executed.

### Release archive

```bash
./build-release.sh 14.0.0
```

Requires Bash, PHP 8.2+ and ext-zip, without Composer dependencies. Outputs `dist/typo3_forum_14.0.0.zip` and `.zip.sha256`. The builder validates semantic versions, stages in a unique temporary directory, sorts names, fixes ZIP timestamps/permissions and uses stored entries to avoid compression-version differences. Temporary files are cleaned up. It does not modify tracked sources, create tags/branches, push or call publication APIs.

The allowlist contains `Classes`, `Configuration`, `Resources`, `Documentation`, Composer metadata, extension metadata/configuration, SQL schemas, the extension icon, license and README. Development roots such as `.git`, `.github`, `.Build`, `Tests`, `Build` and `ddev`, plus CI/analyzer configs, are excluded. Symlinked runtime inputs are rejected. Tests verify exact contents, checksums, reproducibility despite source mtime changes, invalid versions and missing metadata. A real `14.0.0-test` build also succeeded without changing the tracked source diff.

The Git tag supplies the release version. `ext_emconf.php` remains fallback extension metadata (`14.0.0-dev` on this development branch): update it deliberately in a reviewed release-preparation commit before tagging. The builder never rewrites it. Keep both separately owned release blockers open until acceptance is completed.

### DDEV

The TYPO3 `^14.3`, PHP 8.4 and MariaDB 10.11 development environment now has one non-interactive entry point from the repository root:

```bash
./Build/setup-ddev.sh
```

Docker and DDEV are the only host prerequisites. The command starts DDEV, installs Composer dependencies in the container, verifies that the extension resolves to the mounted working checkout, initializes TYPO3 through the DDEV TCP database connection and provisions a usable development forum. `ddev setup-forum` calls the same implementation from the `ddev/` directory.

The deterministic fixture provides the current plugin content types, page and storage folders, generated page-ID TypoScript, site routing, a forum/category with sample topic and post, synthetic member/moderator accounts, scoped ACLs, default local FAL storage and Mailpit delivery. Credentials are generated once outside the webroot in an ignored file and are available with `./Build/setup-ddev.sh --show-credentials`. `./Build/setup-ddev.sh --check` performs non-mutating database, configuration and HTTP verification.

Managed records and completed phases are tracked with stable logical identifiers. Repeat and interrupted runs preserve UIDs, credentials, edited content, uploads and all unrelated records. The bootstrap refuses production context, an unexpected database target, unknown identity collisions and an unrecognized nonempty database. It never resets the database or the Git workspace. A recognized incomplete DDEV socket configuration is backed up and repaired without rerunning force setup over existing TYPO3 tables.

The provisioner is a development-only extension required solely by `ddev/composer.json`; DDEV configuration, fixture code, generated settings, state and credentials remain outside the production release allowlist. See [the DDEV guide](ddev/README.md) for the fixture model, recovery behavior and isolated smoke harness.

Docker is unavailable in the implementation environment, so fresh/repeat container startup and real frontend/backend/preview requests were **not executed**. That verification gap remains explicit and the included isolated DDEV smoke harness must run before local runtime acceptance is claimed.

Implementation references: [GitLab Composer publication](https://docs.gitlab.com/user/packages/composer_repository/), [TYPO3 14 DDEV setup](https://docs.typo3.org/m/typo3/tutorial-getting-started/14.3/en-us/Installation/Install.html), [PHPStan setup](https://phpstan.org/user-guide/getting-started).

## Existing-installation migration

The migration tooling is implemented in this branch. Existing installations can contain TYPO3 Forum plugins stored as legacy `list_type` records. The `v14` branch uses dedicated content types:

- `typo3forum_forum`
- `typo3forum_userprofile`
- `typo3forum_moderationreports`
- `typo3forum_userlist`
- `typo3forum_dashboard`
- `typo3forum_taglist`
- `typo3forum_postlist`
- `typo3forum_topiclist`
- `typo3forum_statsbox`

The release package includes a TYPO3-independent PHP 8.1+ read-only preflight under `Resources/Private/Migration/`, followed on TYPO3 v14 by `forum:migration:check`, `forum:migration:plan`, `forum:migration:apply` and `forum:migration:verify`. The registered native wizard uses the same safety path. Nine standard mappings and the historically proven pi1 `Post->list` and `Topic->list` cases are automated; unknown pi1 semantics and non-equivalent permissions block instead of guessing. In the standard mapping the identifier stays equal, but moves from `CType=list` / `list_type=<identifier>` to `CType=<identifier>` / empty `list_type`.

Readiness includes both content and backend-permission operations. Plain v12+ grants are converted to the three-part v14 form (`tt_content:CType:<identifier>`); obsolete ALLOW/DENY suffixes and aggregate pi1/widget rights block for Core normalization and access review. A default FAL storage or unrelated TYPO3 records alone are not legacy-forum evidence.

Preflight and plan format 2.0 retain a versioned source baseline separately from the target snapshot captured immediately before apply. Older v1 artifacts must be regenerated and are not silently re-approved. Apply owns a tokenized migration lock through final verification, locks and revalidates each MySQL/MariaDB row before updating, and commits the journal with the record. Verification rejects blocked/indeterminate/error plans, requires journal evidence for every planned write, rechecks current scope, and distinguishes `NO_MIGRATION_REQUIRED` from a completed `SUCCESS`. Target-only checks disclose their reduced assurance.

The source information must be inventoried before the upgrade and `list_type`/`pi_flexform` retained until conversion. Additive TYPO3 schema updates may run first; destructive source cleanup must wait until verification. No v13-compatible forum runtime is required, but this does **not** by itself validate deployment of a complete website directly from TYPO3 12 to 14: both intervening Core change sets and every third-party/project integration still require review and real installation testing.

See [the complete migration runbook](Documentation/Migration/Index.rst) for commands, exit codes, approval/checksum handling, backups, permissions, recovery, test status and production acceptance.

### Real upgrade and integration testing – release blocker

Static checks, unit tests and TYPO3 CLI boot tests do not prove that a real upgraded installation works end-to-end.

Before releasing the v14 branch, test at least:

- upgrade of an existing v12 installation and database;
- execution and repeatability of the `list_type` -> `CType` data migration;
- frontend forum rendering;
- forum/topic/post CRUD flows;
- BBCode preview in a real browser;
- quote rendering with real TypoScript overrides;
- attachments upload/download;
- FAL storage configuration and permissions;
- user profiles and user fields;
- subscriptions and notifications;
- moderation/report workflows;
- moderation authorization;
- scheduled commands;
- cache invalidation;
- multi-site / TypoScript configuration;
- mail delivery paths;
- configured routes and page IDs;
- backend editing of the dedicated content types.

This is a **release blocker**, handled separately and outside this tooling workstream.

### 3. Final cleanup

After compatibility and integration testing:

- remove newly confirmed dead files/assets;
- remove obsolete service definitions;
- re-run reverse-reference scans;
- review icons and language resources;
- review `SettingsHydrator` and other currently unreferenced infrastructure;
- review remaining application TODOs;
- clean remaining non-functional migration leftovers;
- perform final package-content and Composer validation.

### 4. Separate refactors / latent bug fixes

Architecture improvements and unrelated behavioral fixes should remain separate from the v14 compatibility migration unless they block runtime operation.

Known follow-up candidates include:

- evaluating whether `SettingsHydrator` is dead or should become part of a deliberate entity-hydration architecture;
- reconsidering entity service-locator fallbacks only as part of a complete Extbase hydration design;
- adding the currently pending report PSR-14 event if application behavior requires it;
- reconsidering the quote-settings conversion when TYPO3 provides an equivalent public API;
- broader coding-style/line-ending normalization;
- unrelated application or moderation-policy improvements identified during integration testing.

## Historical verification notes

The single current repository verification baseline is the command/result table
under “Composer and static analysis” above. Counts elsewhere in this section
describe earlier focused audits and are not the current full-suite result.

For the DDEV bootstrap change, Bash syntax, command help, the missing-Docker
failure path, Composer JSON syntax, PHP parsing, LF/final-newline rules and
`git diff --check` were verified locally. A DDEV executable is installed, but
no Docker client or daemon is available in this environment. The container-based
`composer validate`, `composer ci`, fresh/repeat provisioning and HTTP checks
were therefore **not executed** for this change; the tracked isolated smoke
harness remains the required runtime follow-up.

The read-only public GitHub Actions workflow provides PR verification. Its
result is reported separately from local QA and does not replace authoritative
private GitLab CI or real migration acceptance.

### Fluid verification

Fluid verification used the installed TYPO3 14.3.6 / Fluid code.

During the Fluid audit:

- `fluid:analyze --help` and `fluid:namespaces` were checked.
- Since automatic discovery only considers `*.fluid.*`, each of the 59 `.html` files was supplied individually to `fluid:analyze --stdin --json`.
- **43 files passed** without errors/deprecations.
- **16 files were blocked** by the local failsafe CLI container because required frontend infrastructure such as frontend TypoScript request state, the `typo3forum_main` cache or `FlexFormTools` was unavailable.
- JSON-mode exit status alone was not treated as evidence of success.
- The same `TemplateValidator` and actual Fluid compiler successfully checked **all 59 files** with site/database collaborators isolated.
- Behavioral tests cover compiled conditions and pagination.
- `fluid:cache:warmup` was attempted but could not be completed in the failsafe bootstrap because the full frontend/site container was unavailable.

These limitations are explicitly integration-test items rather than silently classified as successful checks.

### TypoScript

The TypoScript lint currently has four known pre-existing style warnings in unchanged configuration sections around:

- common `if` paths;
- common `fontawesome` paths.

No migration-related TypoScript syntax error is known.

### Remaining integration risks

Repository-level tests do not yet prove:

- a real v12 database upgrade;
- `list_type` -> `CType` migration on production data;
- configured frontend routing/page IDs;
- real FAL storage permissions;
- browser-side markItUp behavior;
- real frontend TypoScript overrides;
- moderation authorization and workflows;
- mail delivery;
- scheduled task behavior in a configured installation;
- backend editing against a migrated database.

A successful TYPO3 CLI boot is considered a smoke test only. It does **not** replace frontend or upgraded-installation integration testing.

## Development workflow for the v14 branch

- Keep migration commits narrowly scoped.
- Do not add TYPO3 v12/v13 compatibility code to `v14`.
- Preserve existing application behavior unless a TYPO3 v14 incompatibility requires a behavioral change.
- Keep release blockers explicit.
- Prefer verified TYPO3 v14 APIs over assumptions based on earlier TYPO3 versions.
- Do not replace a known internal API with another internal API unless there is a demonstrated compatibility benefit.
- Reverse-reference-check code and assets before deletion.
- Preserve existing copyright notices exactly.
- Keep unrelated refactors and bug fixes out of migration commits.
- Run the relevant unit/static/boot checks before each completed migration step.
- Update this README after every completed migration phase so that the repository documents the actual migration state and the next planned task.

## Migration from mm_forum

Migration from `mm_forum` was historically supported only from `mm_forum` 1.0 up to typo3_forum 1.1.0. This historical migration path is separate from the current TYPO3 v12 -> v14 extension migration.

## Contact

This project was originally built by Mittwald and is now maintained by Agentur Pottkinder in Bochum, Germany.

Support: support@agentur-pottkinder.de
