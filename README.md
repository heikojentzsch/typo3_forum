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

## Remaining migration plan

The following work is still open. The order reflects the current migration plan.

### 1. v12 -> v14 content-type upgrade wizard – release blocker

Existing installations can contain TYPO3 Forum plugins stored as legacy `list_type` records. The `v14` branch uses dedicated content types:

- `typo3forum_forum`
- `typo3forum_userprofile`
- `typo3forum_moderationreports`
- `typo3forum_userlist`
- `typo3forum_dashboard`
- `typo3forum_taglist`
- `typo3forum_postlist`
- `typo3forum_topiclist`
- `typo3forum_statsbox`

A migration wizard based on TYPO3's list-type-to-CType upgrade infrastructure must be provided for existing installations.

Because installations must execute the conversion **before** running the TYPO3 v14-only extension, this will most likely need to be implemented and released on the v12 line first.

The migration must preserve the existing plugin-specific configuration and be safe to run repeatedly.

This is a **release blocker**.

### 2. Real upgrade and integration testing – release blocker

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

This is a **release blocker**.

### 3. Composer, CI and release tooling

Modernize the remaining project tooling after runtime compatibility is stable:

- review/update PHPStan, Psalm and PHPMD configuration/tool versions;
- decide on repository-wide coding-style normalization separately;
- modernize or remove obsolete `.travis.yml` configuration;
- review and modernize `.gitlab-ci.yml`;
- modernize `build-release.sh` and the release/build process;
- review DDEV / local TYPO3 v14 development setup if it is to be maintained in this repository.

Tooling changes must remain separate from functional TYPO3 migration changes.

### 4. Final cleanup

After compatibility and integration testing:

- remove newly confirmed dead files/assets;
- remove obsolete service definitions;
- re-run reverse-reference scans;
- review icons and language resources;
- review `SettingsHydrator` and other currently unreferenced infrastructure;
- review remaining application TODOs;
- clean remaining non-functional migration leftovers;
- perform final package-content and Composer validation.

### 5. Separate refactors / latent bug fixes

Architecture improvements and unrelated behavioral fixes should remain separate from the v14 compatibility migration unless they block runtime operation.

Known follow-up candidates include:

- evaluating whether `SettingsHydrator` is dead or should become part of a deliberate entity-hydration architecture;
- reconsidering entity service-locator fallbacks only as part of a complete Extbase hydration design;
- adding the currently pending report PSR-14 event if application behavior requires it;
- reconsidering the quote-settings conversion when TYPO3 provides an equivalent public API;
- broader coding-style/line-ending normalization;
- unrelated application or moderation-policy improvements identified during integration testing.

## Verification baseline

The migration has been repeatedly verified with the following checks during development:

```bash
composer validate
composer php-lint
.Build/bin/phpunit
.Build/bin/typo3 list -vvv
.Build/bin/typo3 asset:publish -vvv
.Build/bin/typoscript-lint Configuration/TypoScript/setup.typoscript
git diff --check
```

After the latest TYPO3 v14 legacy/deprecation cleanup, the full PHPUnit run reported:

```text
65 tests
769 assertions
0 skips
```

The suite includes:

- repository tests;
- isolated SQLite migration/query tests;
- Fluid parsing and compilation;
- all 59 shipped Fluid files through the existing rendering validation;
- parsed and compiled conditional ViewHelper behavior;
- controller redirect behavior after removal of the old context layer;
- BBCode first/last wrap semantics;
- non-mutating report comment access;
- missing-default-FAL-storage behavior;
- existing successful attachment/FAL behavior.

`composer validate`, `composer php-lint`, TYPO3 command listing, asset publication and `git diff --check` passed for the latest cleanup.

There is currently no GitHub Actions workflow providing independent server-side PR checks; these results were produced in the migration development/test environment.

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
