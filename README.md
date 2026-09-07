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

### Deliberate TYPO3 v14 decisions

- `TYPO3\CMS\Extbase\Persistence\Generic\LazyLoadingProxy` is **intentionally retained**. TYPO3 v14.3 still contains and uses it; replacing it belongs to a later TYPO3 v15 migration, not to this branch.
- The BBCode preview continues to use the existing frontend `PAGE` / `typeNum` endpoint. The migration removes the legacy direct Extbase bootstrap call without introducing a new routing subsystem or changing the public request contract.
- Repository query property names use Extbase **domain property names**, not raw database column names, except where deliberately using QueryBuilder / SQL.
- TYPO3 v14-specific APIs are preferred over backwards-compatibility shims.
- Refactors and unrelated latent bug fixes are kept separate from migration work whenever possible.

## Remaining migration plan

The following work is still open. The order reflects the current migration plan.

### 1. Fluid templates and frontend rendering – next

Perform a complete audit of the current Fluid layer:

- all templates, partials and layouts,
- controller action to template mapping,
- Fluid namespaces and ViewHelpers,
- form/link/URI generation,
- asset/resource ViewHelpers,
- argument contracts and nullable values,
- obsolete or unreferenced Fluid files,
- TYPO3 v14 / Fluid compatibility,
- runtime-sensitive frontend rendering paths.

The result should classify findings as **must change**, **should change**, **can remove** or **keep as-is** before modifications are made.

### 2. Cache-clear hook migration

The extension still registers the legacy `clearCachePostProc` hook in `ext_localconf.php`.

This must be reviewed and migrated to the appropriate TYPO3 v14 event / PSR-14 mechanism while preserving the existing `typo3forum_main` cache behavior.

### 3. Broader TYPO3 v14 legacy/deprecation scan

Run a repository-wide audit for remaining migration-relevant APIs and patterns, including:

- deprecated or removed TYPO3 APIs,
- legacy Extbase controller patterns,
- legacy service-locator usage,
- old DBAL patterns,
- frontend/backend globals,
- obsolete configuration conventions,
- PHP 8.4 deprecations that are worth cleaning up without changing behavior.

### 4. v12 -> v14 content-type upgrade wizard – release blocker

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

This is a **release blocker**.

### 5. Real upgrade and integration testing – release blocker

Static checks, unit tests and TYPO3 CLI boot tests do not prove that a real upgraded installation works end-to-end.

Before releasing the v14 branch, test at least:

- upgrade of an existing v12 installation and database,
- frontend forum rendering,
- forum/topic/post CRUD flows,
- BBCode preview,
- quote rendering,
- attachments upload/download,
- user profiles and user fields,
- subscriptions and notifications,
- moderation/report workflows,
- scheduled commands,
- cache invalidation,
- multi-site / TypoScript configuration,
- mail delivery paths,
- backend editing of the dedicated content types.

This is a **release blocker**.

### 6. Composer, CI and release tooling

Modernize the remaining project tooling after runtime compatibility is stable:

- review/update PHPStan, Psalm and PHPMD configuration/tool versions,
- decide on repository-wide coding-style normalization separately,
- modernize or remove obsolete CI configuration,
- modernize the release/build process,
- review DDEV / local TYPO3 v14 development setup if it is to be maintained in this repository.

### 7. Final cleanup

After compatibility and integration testing:

- remove newly confirmed dead files/assets,
- remove obsolete service definitions,
- re-run reference scans,
- review icons and language resources,
- clean remaining non-functional migration leftovers.

### 8. Separate refactors / latent bug fixes

Architecture improvements and unrelated behavioral fixes should remain separate from the v14 compatibility migration unless they block runtime operation. Known candidates should be handled in focused commits after the migration baseline is stable.

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

After the latest dead-code/asset cleanup, the local PHPUnit run reported **40 tests / 376 assertions**, with **2 expected skips when `pdo_sqlite` is not available**. The SQLite-backed tests run normally in environments providing that extension.

The TypoScript lint currently has four pre-existing style warnings in unchanged configuration sections; no migration-related TypoScript syntax error is known.

A successful TYPO3 CLI boot is considered a smoke test only. It does **not** replace frontend or upgraded-installation integration testing.

## Development workflow for the v14 branch

- Keep migration commits narrowly scoped.
- Do not add TYPO3 v12/v13 compatibility code to `v14`.
- Preserve existing application behavior unless a TYPO3 v14 incompatibility requires a behavioral change.
- Keep release blockers explicit.
- Prefer verified TYPO3 v14 APIs over assumptions based on earlier TYPO3 versions.
- Run the relevant unit/static/boot checks before each completed migration step.

## Migration from mm_forum

Migration from `mm_forum` was historically supported only from `mm_forum` 1.0 up to typo3_forum 1.1.0. This historical migration path is separate from the current TYPO3 v12 -> v14 extension migration.

## Contact

This project was originally built by Mittwald and is now maintained by Agentur Pottkinder in Bochum, Germany.

Support: support@agentur-pottkinder.de
