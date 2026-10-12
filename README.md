# TYPO3 Forum — v14

Frontend forum extension for TYPO3. This README describes the `v14` branch of
[heikojentzsch/typo3_forum](https://github.com/heikojentzsch/typo3_forum/tree/v14).

## Contents

1. [Scope of this branch](#scope)
2. [Technical requirements and local testing](#requirements)
3. [Installation](#installation)
4. [Migration](#migration)
5. [Changes from v12 to v14](#changes)
6. [Historical README information](#history)

<a id="scope"></a>
## 1. Scope of this branch

`v14` targets TYPO3 14.3 and contains no v12/v13 runtime compatibility layer.
The branch modernizes the extension, provides an explicit existing-data
migration, and replaces the BBCode editor with TinyMCE and Unicode emoji.

The branch provides nine frontend content types:

| Frontend component | Identifier |
| --- | --- |
| Forum | `typo3forum_forum` |
| User Profile | `typo3forum_userprofile` |
| Moderation Reports | `typo3forum_moderationreports` |
| User List | `typo3forum_userlist` |
| Dashboard | `typo3forum_dashboard` |
| Tag List | `typo3forum_taglist` |
| Post List | `typo3forum_postlist` |
| Topic List | `typo3forum_topiclist` |
| Statistics Box | `typo3forum_statsbox` |

Repository checks and a synthetic DDEV installation are not production release
approval. The [release checklist](Documentation/Release/Index.rst) records the
remaining installation, migration, mobile-device and restore acceptance.
See also the [security review](Documentation/Security/Index.rst).

<a id="requirements"></a>
## 2. Technical requirements and local testing

| Component | Requirement |
| --- | --- |
| TYPO3 | Composer `^14.3` (14.x); extension key `typo3_forum` |
| PHP | Composer `^8.2`, plus the extensions required by TYPO3 and Composer |
| Database | TYPO3-supported database; MySQL/MariaDB forum/user text columns and connections must use `utf8mb4` |
| Upload inspection | PHP Fileinfo; configure the attachment MIME/size allowlist and FAL storage |
| Development fixture | Docker and DDEV with PHP 8.4 and MariaDB 10.11 |
| Editor build/tests | Node.js/npm; CI uses Node.js 24 and Playwright Chromium |
| Release packaging | Bash, PHP 8.2+ and ext-zip |

The extension's declared PHP minimum does not override a higher minimum of
other packages in the complete site's Composer dependency set.

### Local DDEV setup

From the repository root:

```bash
./Build/setup-ddev.sh
./Build/setup-ddev.sh --check
./Build/setup-ddev.sh --show-credentials
```

The setup starts DDEV, installs the local checkout, provisions synthetic data,
configures routing, ACLs, local FAL and Mailpit, and checks HTTP/login endpoints.
Credentials and state live in ignored files outside the web root. Repeated runs
preserve existing fixture identities and edited data. This is a **development
fixture**, never a migration tool for an existing installation.
Details: [DDEV guide](ddev/README.md).

### Checks and code style

For a standalone checkout with PHP/Composer available:

```bash
composer install
composer ci
composer audit
```

With the DDEV setup, run the commands inside its web container:

```bash
cd ddev
ddev exec --dir /var/www/html/packages/typo3_forum composer ci
```

`composer ci` runs metadata validation, PHP syntax, PHPUnit, PHPStan,
TypoScript lint and the non-mutating PHP style check. PHP uses the checked-in
PSR-12-based fixer configuration. Project-owned editor/frontend sources use
Prettier and EditorConfig; distributed third-party sources and generated bundles
are not manually reformatted.

```bash
cd Resources/Private/Editor
npm ci
npm run format:check
npm run build
npx playwright install --with-deps chromium
npm test
npm audit
```

`node site-check.mjs`, run in the DDEV web container from this directory, checks
the authenticated real topic form, emoji popup, quote rendering and original-post
route without submitting a post. Browser tests cover desktop/mobile viewports;
they do not replace acceptance on real iOS/Android devices.

### CI and release tooling

The repository contains authoritative GitLab QA/package/publication jobs and a
read-only GitHub verification workflow. Their configuration is not evidence that
remote CI passed for a particular commit. GitLab publication is manually gated;
local commits/pushes do not publish a release.

```bash
./build-release.sh 14.0.0-test
```

The deterministic allowlisted package includes production PHP, templates,
configuration, migration tools, documentation, TinyMCE assets and licenses. It
excludes DDEV, credentials, tests, development dependencies and `node_modules`.
See [development and publication](Documentation/Development/Index.rst).

<a id="installation"></a>
## 3. Installation

For a **new installation**, add this repository to the site's Composer
repositories (if it is not already provided by your package registry) and require
`pottkinder/typo3forum:dev-v14`. The package name and extension key differ.
Run TYPO3's extension setup and review the database schema changes.

1. Include the extension's static TypoScript and provide the site's Bootstrap
   layout/styles required by the shipped templates.
2. Create storage folders for forum records and frontend users/groups.
3. Set `plugin.tx_typo3forum.persistence.storagePid`, the relevant `settings.pids`
   and the forum record's read/write/moderation ACLs.
4. Create the dedicated **Forum** content type (`typo3forum_forum`), not a legacy
   `CType=list` plugin with switchable actions.
5. Configure frontend login and optional profile, reports, lists and statistics
   pages. Import `Configuration/Routing/Routing.yaml` into the site's route enhancers.
6. Configure FAL storage, allowed upload types/sizes and mail delivery. For
   access-restricted attachments, storage must also prevent direct public access;
   the controller's ACL check cannot protect an independently public file URL.
7. Clear caches and verify access as guest, member and moderator.

Guides: [installation](Documentation/BasicInstallation/Index.rst),
[users/groups](Documentation/UserAndGroups/Index.rst),
[moderation reports](Documentation/Report/Index.rst),
[notification settings](Documentation/Notifications/Index.rst).

<a id="migration"></a>
## 4. Migration

For an existing installation, follow the [migration runbook](Documentation/Migration/Index.rst).
Do not run the DDEV fixture provisioner against its database.

1. Back up the database/files and rehearse restoration. Capture the standalone
   read-only preflight **before** removing legacy columns or upgrading away the
   original plugin configuration.
2. Upgrade the site and apply additive schema changes. Preserve `list_type`,
   `pi_flexform`, source text and legacy formatting definitions until verification.
3. Establish `utf8mb4` columns and connections on MySQL/MariaDB. The extension
   checks this requirement; it does not automatically convert collations.
4. Run `forum:migration:check` and `forum:migration:plan` with the source preflight.
5. Resolve blocked/indeterminate findings, review the protected plan and run
   `forum:migration:apply --dry-run`. Apply requires its exact checksum.
6. Run `forum:migration:verify`, then the installation-specific acceptance checks.

The registered TYPO3 upgrade wizard uses the same migration service. Plans are
versioned and contain sensitive source/target content: store them outside the web
root with restricted permissions. Regenerate plans after migration-code changes.

The workflow converts known `list_type`/FlexForm and backend permission formats,
then fills separate HTML fields for posts, signatures and moderation comments.
Original BBCode fields are preserved by migration. Unknown custom BBCode,
smileys, restricted definitions and unsupported links block automated conversion.
A source baseline, row revalidation, locks and journals protect repeat/resume
operations; do not manually bypass an active migration lock.

A real v12-to-v14 upgrade on an anonymized copy, interrupted-run recovery and
backup restoration remain **open acceptance items**. The extension migration
alone does not validate a whole site's direct Core upgrade or third-party code.

<a id="changes"></a>
## 5. Changes from v12 to v14

This is the cumulative migration record, covering the original v12 baseline
[`f340a489`](https://github.com/heikojentzsch/typo3_forum/commit/f340a489359da1514fa0984727ca7d17fa56ea2a)
through the v14 implementation at
[`68d3ed92`](https://github.com/heikojentzsch/typo3_forum/commit/68d3ed923d43a10f24fe935b8ee052379338dec0).
It includes compatibility work, runtime fixes, data migration, tooling and the
subsequent editor/security changes. Earlier migration stages remain documented
when a later stage replaced their implementation. Repository implementation and
verification do not mean that production migration acceptance is complete.

Changes by area:

- [Platform, bootstrapping and content types](#changes-platform)
- [Controllers, CLI and upgrade APIs](#changes-controllers)
- [Repositories, DBAL and domain behavior](#changes-repositories)
- [Fluid, forms and frontend rendering](#changes-fluid)
- [TinyMCE, Unicode emoji and quotes](#changes-editor)
- [Installation and content migration](#changes-migration)
- [Subscription notifications and mail delivery](#changes-notifications)
- [Attachments, access control and CSRF](#changes-security)
- [Legacy cleanup and deliberately retained APIs](#changes-cleanup)
- [Tests, static analysis and code style](#changes-qa)
- [Composer, CI and release publication](#changes-tooling)
- [DDEV setup and demonstration frontend](#changes-ddev)
- [Remaining acceptance and follow-up work](#changes-acceptance)
- [Complete migration commit chronology](#changes-commits)

<a id="changes-platform"></a>
### Platform, bootstrapping and content types

- Set Composer and extension metadata to TYPO3 `^14.3` and PHP `^8.2`; v14 is a
  dedicated migration branch without v12/v13 runtime compatibility shims.
- Made extension registration, dependency injection and configuration bootable
  with the current TYPO3 services and APIs.
- Replaced the `static-info-tables` dependency and country lookups with the TYPO3
  Core Country API. Existing country values still require migration review;
  unknown values are not guessed or mass-converted.
- Replaced generic `CType=list` / `list_type` plugins with nine dedicated CTypes:
  Forum, User Profile, Moderation Reports, User List, Dashboard, Tag List, Post
  List, Topic List and Statistics Box. Their identifiers are listed in section 1.
- Updated plugin registration, backend configuration and persistence mappings for
  those content types. Existing plugin records and backend content-type grants
  have an explicit migration path, described below.
- Extended standard frontend user/group records, including type 0; a special
  forum subtype no longer has to be selected before the first save.
- Aligned metadata line endings and ignored generated TYPO3 runtime files.

<a id="changes-controllers"></a>
### Controllers, CLI and upgrade APIs

- Converted Extbase controller validation and model annotations to PHP attributes.
  Updated validator initialization for TYPO3 v14, including the TagValidator
  lifecycle and injected dependencies for post/tag validation.
- Removed frontend TSFE access and obsolete controller-context dependencies.
  The custom `CONTEXT_WEB`, `CONTEXT_AJAX`, `CONTEXT_CLI`, `$context` and
  `setContext()` layer was removed after checking callers.
- Updated action return values, redirects and nullable arguments for the current
  Extbase request/response contract. HTML redirects use Extbase behavior;
  non-HTML requests retain their rendered-response fallback.
- Replaced obsolete AJAX implementation, configuration and assets. Preview now
  uses `EXTBASEPLUGIN` instead of direct `Extbase\Core\Bootstrap->run` calls and
  returns a PSR-7 response. The existing `typeNum=43568275` and
  `tx_typo3forum_ajax[text]` request contract is retained; its content changed
  from BBCode to sanitized HTML during the later editor replacement.
- Corrected AJAX controller autowiring and the preview request contract in the
  real DDEV integration.
- Updated site-based CLI TypoScript loading and the counter-update,
  mark-forums-read and statistics commands for current TYPO3/DBAL APIs.
- Migrated `PostsWithoutAuthorNameUpdate` to current upgrade-wizard APIs and
  modern database access. This author-name wizard remains independent of the
  plugin/rich-text installation migration.
- Converted attachment downloads to PSR-7 responses so downloaded bytes are not
  wrapped in a rendered frontend page.
- Reset the URI builder after optional cache purging when creating a topic,
  preventing stale forum arguments from contaminating the redirect.
- Removed obsolete Signal/Slot remnants and completed migration TODOs while
  retaining active PSR-14 dispatches. The separate pending post-report event
  dispatch is still not implemented.

<a id="changes-repositories"></a>
### Repositories, DBAL and domain behavior

- Replaced removed/legacy Extbase repository calls, fixed invalid repository
  invocations and modernized persistence/query settings while preserving the
  existing storage-page behavior.
- Repository queries use Extbase domain property names rather than database
  column names, except for deliberately explicit DBAL/SQL queries.
- Modernized DBAL execution/fetch operations and integer parameter types,
  including slug lookup and maintenance commands. Unread-topic SQL now uses
  QueryBuilder and bound parameters rather than the legacy query construction.
- Added repository collection/generic type information and corrected missing
  type declarations/documentation exposed by static analysis.
- Fixed absent userfield values to return an empty array and applied solution
  point defaults before integer conversion.
- Corrected notification subscriber handling and associated service wiring.
  Parent-forum subscribers are checked against the actual content's access
  rules, and notification coverage checks duplicate-recipient handling.
- Replaced temporary-array-reference expressions such as
  `array_shift(explode(...))` and `array_pop(explode(...))` with local arrays,
  preserving first/last-element behavior.
- Changed `Report::getFirstComment()` so reading the first comment does not
  mutate its `ObjectStorage`.
- Fixed child-forum last-post display and explicit denied-read behavior:
  anonymous users can be sent to the configured login page, while authenticated
  users without access receive a clear forbidden response.

<a id="changes-fluid"></a>
### Fluid, forms and frontend rendering

The migration reviewed the shipped template/partial/layout graph, custom
ViewHelpers, registered actions, controller assignments, TypoScript view paths,
forms, links, pagination, preview, mail and local resource references.

- Replaced legacy static CObject/ViewHelper rendering with current rendering
  services and updated Fluid condition, argument and variable-provider APIs.
- Corrected pagination variable scopes, avatar dimensions/resource URLs and
  escaped user/rootline links with the proper plugin targets.
- Updated custom textarea/select ViewHelpers to use Core rendering, preserving
  Extbase field names, selected values and trusted-property registration.
- Added the missing `Report/NewUserReport` template, corrected type-specific
  moderation forms and exposed tag validation feedback.
- Repaired profile, tag, subscription and pagination links and stale
  frontend/report resource paths.
- Removed seven unused rendering files: backend `Update/Form`, `User/New`,
  `User/Edit`, `User/ListOnlineUsers`, `Post/Preview`, `Default/Error` and its
  exclusive `Exception` partial. The later editor cleanup also removed
  `Format/Quote.html` and the obsolete BBCode editor ViewHelper.
- Initially migrated quote rendering from removed `StandaloneView` usage to
  `ViewFactoryInterface` / `ViewFactoryData`, including settings and configured
  template paths. The later HTML editor replacement superseded that parser and
  partial with the current sanitized quote renderer and original-post links.
- Passed PSR-7 upload objects through validation and FAL using the current
  storage repository and duplication enum.
- Corrected attachment filename/download links and preserved raw download
  content. Missing avatars no longer produce broken image output.
- Added action labels/tooltips and compact attachment details in the local demo.
  POST-based action menus preserve horizontal button layout and wrap on narrow
  screens; forms are not nested inside report/delete forms.

The initial compatibility audit did not upgrade Bootstrap or redesign the whole
frontend. The subsequent editor and demo-layout changes are recorded separately.
Mail content still uses language strings and mailing services, not separate
Fluid mail templates.

<a id="changes-editor"></a>
### TinyMCE, Unicode emoji and quotes

- TinyMCE 8 is the sole editor and is bundled locally under GPL. No cloud account
  is needed; optional “Powered by Tiny” branding is disabled. CKEditor is not
  included and there is no editor selector in extension settings or profiles.
- Bold, italic, underline, strike-through, lists, links, quotes and code blocks
  use server-sanitized HTML. Arbitrary HTML, scripts and executable URLs are
  rejected. Backend edits, frontend submission, preview and rendering share
  the sanitization boundary; client-side editor filtering alone is insufficient.
- The original textarea remains available if JavaScript/editor loading fails.
  Loading and submission handling preserve the initial content.
- The local Emoji 17.0 catalog offers German/English search, categories and skin
  variants. It loads on demand and inserts real Unicode at the saved selection.
  The compact popup stays within the viewport, opens upwards when necessary
  and adapts to viewport changes. Only its grid scrolls after “Show more”.
  Appearance and newer glyph support depend on device fonts.
- Quotes have a visible frame. Quotes with a post reference get a localized link
  to the original post, resolving the topic page and anchor at render time.
- Links without a protocol receive HTTPS without a prompt. Targets are fixed to
  a new tab/window; the server enforces safe `rel` attributes as well.
- Attachments use a full-width file selector below the label “Anhänge:”.
- Added nullable HTML fields for posts, report comments and user signatures.
  NULL distinguishes unmigrated content from a migrated empty value. Migration
  preserves the original BBCode source; subsequent editing maintains HTML and
  the plain-text projection rather than an unlimited source revision history.
- MySQL/MariaDB text columns and connections require `utf8mb4`; incompatible
  character sets block migration/rich-text writes. Charset/collation conversion
  remains an administrator operation, not an automatic blanket ALTER.
- Removed markItUp, its ViewHelper/panels/parser pipeline and bundled GeSHi.
  Legacy definition records remain available for migration inspection.
- Removed the unused jQuery CDN inclusion; custom templates must declare their
  own dependencies. Editor assets/catalogs are self-hosted, with licenses and
  versions included in release packages. Node.js is only needed for build/tests.

Custom forms must adopt `mmf:form.richTextEditor`; the existing preview endpoint
now accepts HTML. Old parser service settings and `Format/Quote.html` overrides
no longer control rendering. See [editor and content migration](Documentation/Editors/Index.rst).

<a id="changes-migration"></a>
### Installation and content migration

- Added a TYPO3-independent, read-only PHP 8.1+ preflight in
  `Resources/Private/Migration/` for inventory before upgrading the source site.
- Added `forum:migration:check`, `forum:migration:plan`,
  `forum:migration:apply` and `forum:migration:verify`, plus a native TYPO3 v14
  wizard using the same validation/write path.
- Implemented the nine standard plugin mappings and historically proven pi1
  `Post->list` / `Topic->list` cases. Unknown pi1 semantics block conversion.
  Standard identifiers move from `list_type` to `CType`; obsolete `list_type`
  values are cleared while supported FlexForm data is retained/migrated.
- Included backend permissions in readiness/plans. Plain v12+ grants become
  `tt_content:CType:<identifier>` grants. Obsolete ALLOW/DENY suffixes and
  aggregate pi1/widget rights block for Core normalization and access review
  rather than silently changing effective permissions.
- Hardened legacy detection: a default FAL storage or unrelated TYPO3 records
  alone are not evidence of an old forum installation.
- Introduced versioned source baselines separate from the target snapshot taken
  immediately before apply. Older/incompatible artifacts must be regenerated;
  the rich-text extension of migration also requires fresh reviewed plans.
- Added dry-run, plan validation/checksum approval, a tokenized migration lock
  through final verification, row revalidation and MySQL/MariaDB row locking.
  Each write and its journal entry are committed together; repeat/resume paths
  use the recorded evidence.
- Verification rejects blocked, indeterminate or erroneous plans, requires
  journal evidence for planned writes and checks current scope. It distinguishes
  `NO_MIGRATION_REQUIRED` from completed `SUCCESS`; target-only verification
  explicitly provides less assurance than source/target comparison.
- Extended the same workflow to BBCode-to-HTML conversion, Unicode prerequisites,
  custom-tag/smiley review blockers and source preservation. Unknown formatting,
  group restrictions and unsupported URLs are not silently accepted.
- Documented additive schema updates, source-field retention, backups, recovery,
  exit codes and delayed destructive cleanup. Verification does not establish
  remote-storage reachability or whole-site upgrade acceptance.

See [section 4](#migration) and the [migration runbook](Documentation/Migration/Index.rst)
for the operational sequence. No v13-compatible forum runtime is required, but
both intervening Core change sets and every project integration still need review.

<a id="changes-notifications"></a>
### Subscription notifications and mail delivery

- Fixed mail delivery to call TYPO3's injected mailer.
- Added five independent global content switches for new-topic and new-reply
  subscription notifications, with per-forum inheritance:

| Setting | Default |
| --- | --- |
| `notifications.includeForumNameInSubject` | Off |
| `notifications.includePostText` | Off |
| `notifications.includeForumLink` | On |
| `notifications.includeTopicLink` | On |
| `notifications.includeUnsubscribeLink` | On |

- Each forum can use Inherit (`0`), Enabled (`1`) or Disabled (`2`). Resolution
  starts at the content forum; the nearest explicit parent value wins before
  the global fallback. Existing records inherit after the additive schema update.
  Invalid settings and parent cycles fail diagnostically.
- Full text contains the triggering reply or the initial post of a new topic.
  It remains off by default because content leaves the protected site and stays
  in the recipient's mailbox. Output is escaped readable text; frontend parsers,
  remote resource retrieval and attachment inclusion are not invoked.
- Content forum/topic determine configuration, subject and navigation links.
  A parent subscription determines only the unsubscribe target. Disabling a
  generated link does not terminate subscriptions or censor author-written URLs.
- Added structural language markers while preserving established value markers.
  Legacy overrides receive plain forum/topic names when links are disabled;
  disabled unsubscribe-marker lines are removed. Hard-coded custom HTML links
  cannot be controlled by these semantic switches.
- Added coverage for all 32 option combinations for both notification events,
  hierarchy, parent subscriptions and captured mail output.
- Email unsubscribe links now open an authenticated confirmation form; GET and
  mail-link scanners cannot silently change a subscription.

See the [notification guide](Documentation/Notifications/Index.rst) for examples
and the language-marker contract.

<a id="changes-security"></a>
### Attachments, access control and CSRF

- Explicitly handle a missing default FAL storage rather than failing later on
  a null storage object. Existing storage/attachment paths use current FAL APIs.
- Check read access to the parent post before reading attachment bytes or
  incrementing counters. Orphaned/missing references are rejected. Download
  responses use private/no-store and nosniff headers and safe filenames.
- Validate uploaded bytes with Fileinfo instead of trusting browser MIME data;
  check both declared and actual size, preserve upload-error handling and use
  cryptographically random stored filenames.
- Require read permission for quoted and supported posts and user-moderation
  rights for user-report lists, comments and status changes. Reporting requires
  an authenticated user; post reports also require read access.
- Protect explicit writes with POST and TYPO3's nonce-bound request token before
  argument mapping/controller effects. This includes subscriptions, helpful
  votes, solutions, mark-read, post/topic/tag writes, reports and moderation.
- Convert write-action links and the moderation status redirect into forms.
  Missing, forged or wrong-scope tokens are rejected. GET subscription URLs
  render confirmation instead of modifying data.
- Use HTTP 303 after mutations to avoid replaying POST on redirects. Constrain
  referrer redirects to the same origin and cache-purge destinations to the
  configured site address, with connection/request timeouts.
- Review SQL parameter binding, migration identifier allowlists and rich-text
  sanitization alongside dependency advisory audits and regression checks.

Custom write forms must include `requestToken="typo3_forum/mutation"` and use
POST. Restricted attachments need non-public FAL storage: controller checks
cannot protect a publicly served static URL. See the [security review](Documentation/Security/Index.rst).

<a id="changes-cleanup"></a>
### Legacy cleanup and deliberately retained APIs

- Removed proven unused utilities, old SCEditor assets, obsolete CSH resources,
  templates, service registrations and dependencies, followed by the old
  markItUp/GeSHi/parser runtime in the final cleanup.
- Repaired backend TCA icons: statistics summaries use Core
  `content-widget-chart.svg`, notifications use `content-message.svg`.
- Moved `IfSubscribedViewHelper` repository access to constructor injection and
  removed its unused forum repository dependency; parsed and compiled rendering
  paths are covered.
- Retained `LazyLoadingProxy`: it remains part of the TYPO3 v14.3 runtime.
- Retained the active `clearCachePostProc` hook after review; it still has a
  caller and was not replaced by a behaviorally different API.
- Retained `FrontendUser`'s rank-repository fallback and
  `ConfigurableEntityTrait`'s lazy configuration fallback. Extbase hydration may
  bypass constructors; manual entity creation also needs those paths.
  The unused `SettingsHydrator` was subsequently removed without removing those
  active fallback paths.
- Earlier quote-parser work deliberately retained
  `TypoScriptService::convertTypoScriptArrayToPlainArray()` because setup arrays,
  flat settings, internal AST APIs and the configuration builder did not preserve
  equivalent nested/scalar-child semantics. That decision describes an
  intermediate migration stage: the later editor replacement removed the quote
  parser entirely, so it is no longer a current quote-parser dependency.
- Keep the still-unimplemented post-report PSR-14 event and a broader entity
  hydration redesign as explicit follow-up work, not as completed migration.

<a id="changes-qa"></a>
### Tests, static analysis and code style

- Modernized unit/repository tests for PHPUnit 11 and added focused regression
  tests for CLI/wizards, repositories, validators, mail, userfields, slugs,
  solution points, Fluid rendering, attachments, migration and security.
- Added parsing/compilation of shipped Fluid files and behavioral checks for
  conditions, pagination, form mapping and rendering dependencies. Early failsafe
  CLI limitations are historical; they were not evidence of a passed site test.
- Adopted PHPStan 2 at level 6 with Composer autoloading, production scopes
  `Classes`/`Configuration`, generics/type fixes and no generated baseline.
  PHPDoc certainty is disabled for hydrated values; native types remain checked.
  Narrow documented exceptions cover deliberately retained runtime contracts.
- Removed obsolete Psalm/PHPMD dependencies/configuration after evaluating their
  runtime compatibility and usefulness; PHPStan is the maintained analyzer.
- Made Composer the QA entry point for metadata, PHP syntax, PHPUnit, PHPStan,
  TypoScript lint and a non-mutating PHP style check.
- Subsequently normalized project PHP with the checked-in PSR-12-based rules,
  added Prettier/EditorConfig for project frontend/build sources and YAML, and
  resolved the previous TypoScript style warnings. Generated/vendor assets are
  not manually reformatted. The formerly advisory style job is now blocking.
- Added desktop/mobile-viewport editor tests for Unicode, formatting, links,
  quotes, load failure and emoji popup behavior, plus real DDEV editor/route and
  CSRF checks. Real devices remain a separate acceptance gate.

Current commands are in [section 2](#requirements). Old test totals, unavailable
Docker notes and former style debt describe earlier stages, not the current
status. A skipped MariaDB locking test is not proof of row-lock correctness.

<a id="changes-tooling"></a>
### Composer, CI and release publication

- Retained the package name `pottkinder/typo3forum`, TER replacement,
  `.Build/vendor`, `.Build/bin`, supported `.Build/Web` location and required
  TYPO3 installer/alias-loader permissions. Removed the extra composer.typo3.org
  repository, stale `dev-master` alias and unused `cms-package-dir`; prefer dist.
- Added Fileinfo as an explicit runtime dependency for upload inspection.
  The extension still does not track a root Composer lockfile; each site resolves
  its own compatible dependencies. Editor builds do use a pinned npm lockfile.
- Replaced Travis and old release configuration with authoritative GitLab QA
  on PHP 8.2/8.4, CLI boot/asset smoke checks, style, editor, package and
  publication jobs. Composer is checksum-verified; vendor directories are not
  shared across PHP versions.
- Added read-only GitHub PR/v14-push verification. GitLab remains the configured
  packaging/publication pipeline; local results do not prove remote CI success.
- Added deterministic `build-release.sh`: validated semantic versions, isolated
  staging, sorted entries, fixed timestamps/permissions, stored ZIP entries,
  checksums, cleanup and rejected runtime symlinks. It neither changes source
  versions nor tags/pushes/publishes by itself.
- Allowlisted runtime code, configuration, assets/licenses, migration tools,
  documentation and metadata; excluded development fixtures, tests, credentials,
  build dependencies and CI configuration. Reproducibility concerns identical
  source inputs, not permanently frozen site dependency resolution.
- Added protected-tag internal Composer publication using `CI_JOB_TOKEN` and
  opt-in manual GitHub publication. Publication verifies checksums and v14 tag
  ancestry, pushes branch/tag atomically without force, and creates a draft
  release before publishing. Conflicting/diverged state requires resolution;
  existing releases are not silently overwritten.
- Kept version updates deliberate: `ext_emconf.php` is development fallback
  metadata; the release tag controls packaging. No public release is implied
  by a local build, commit or branch push.

See [development and publication](Documentation/Development/Index.rst),
`.gitlab-ci.yml` and `Build/publish-github.sh` for the maintained implementation.

<a id="changes-ddev"></a>
### DDEV setup and demonstration frontend

- Rebuilt the local setup for TYPO3 14.3, PHP 8.4 and MariaDB 10.11 and added the
  non-interactive `Build/setup-ddev.sh` / `ddev setup-forum` entry point.
- Verify that Composer resolves the mounted working checkout, recognize
  supported TYPO3 14 releases through the Core version, and configure the DDEV
  TCP database connection and generated site host correctly.
- Provision stable pages/storage folders, dedicated plugins, page-ID TypoScript,
  routing, forum/category/topic/post data, member/moderator accounts, scoped
  ACLs, default local FAL storage and Mailpit. Generated credentials are stored
  outside the webroot and shown only through the explicit credentials command.
- Track managed identities and completed phases. Repeats/resumes preserve UIDs,
  passwords, edited data, uploads and unrelated records. Guard against production,
  unexpected databases, identity collisions and unknown nonempty databases;
  repair recognized incomplete socket configuration with a backup.
- Correct frontend login storage/session verification, Mailpit URL resolution,
  generated page rendering, data access, HTTP failure diagnostics, statistics
  and page access. Added and corrected moderator-only forum/topic access.
- Corrected stored smiley resource identifiers during the earlier BBCode stage;
  that runtime was later superseded by Unicode emoji and the safe converter.
- Improved the demo post layout, action labels, absent-avatar handling, compact
  attachment information, flat attachment panels and horizontal action menus.
- Added a read-only check command and an isolated empty-project/interruption/
  repeatability harness. The development-only provisioner and generated state
  are excluded from production packages.

See the [DDEV guide](ddev/README.md). The running synthetic fixture has been
checked, but the separate fresh/interrupted bootstrap acceptance and an actual
production-data migration must still be signed off independently.

<a id="changes-acceptance"></a>
### Remaining acceptance and follow-up work

The [release checklist](Documentation/Release/Index.rst) retains the open gates:
full migration on an anonymized existing installation, interruption/resumption,
backup restoration, fresh installation, real mobile devices, permissions,
restricted FAL storage, notifications, moderation, routes, backend editing,
scheduled commands, cache invalidation and site-specific overrides. The optional
MariaDB concurrency test requires a dedicated disposable database.

The requested repository cleanup and documentation consolidation are implemented.
They do not replace these installation checks or certify absence of security
vulnerabilities. Broader entity-hydration redesign and the pending report event
remain follow-up work. Preserve this cumulative change record when updating
current verification results; later fixes should extend it rather than replace
it with only the most recent feature summary.

<a id="changes-commits"></a>
### Complete migration commit chronology

Every non-merge commit from the v12 baseline to `68d3ed92` is listed below in
history order, including documentation, test and intermediate fix commits.
Merge-only commits are omitted to avoid duplicating their constituent changes.
Titles describe the change at that point; the area descriptions above explain
which intermediate implementations were subsequently replaced.

<details>
<summary>Expand the complete v12 → v14 commit list</summary>

| Commit | Change recorded during migration |
| --- | --- |
| [`c9fff775`](https://github.com/heikojentzsch/typo3_forum/commit/c9fff7751562746327d744eb7dc6e636d84f4899) | [TASK] Replace static-info-tables with TYPO3 Country API |
| [`dff4a695`](https://github.com/heikojentzsch/typo3_forum/commit/dff4a6955e478e7b4c470c18e0151c2ebaec1c46) | [TASK] Set TYPO3 v14 platform requirements |
| [`97dfd04b`](https://github.com/heikojentzsch/typo3_forum/commit/97dfd04b7644c8fe91667851e2c7392600617463) | [TASK] Normalize metadata file endings |
| [`28b856fc`](https://github.com/heikojentzsch/typo3_forum/commit/28b856fc71ab704f2ae13afa642bf284dd031534) | [TASK] Make extension bootable on TYPO3 v14 |
| [`1a02d34f`](https://github.com/heikojentzsch/typo3_forum/commit/1a02d34f20bdce43daaa81b6b4b6cb170769be8a) | [TASK] Migrate plugins to dedicated TYPO3 v14 content types |
| [`f599f105`](https://github.com/heikojentzsch/typo3_forum/commit/f599f105298ed48567f340e0f9c46232dd3d85d9) | [TASK] Ignore TYPO3 runtime directory |
| [`155e957b`](https://github.com/heikojentzsch/typo3_forum/commit/155e957b0ec9ca25d6b07cc46ebf21efb25aa6f1) | [TASK] Migrate controller validation to PHP attributes |
| [`26c44c17`](https://github.com/heikojentzsch/typo3_forum/commit/26c44c179a430a8362d7ac8b49075009708c229c) | [TASK] Migrate model annotations to PHP attributes |
| [`27ca2cd2`](https://github.com/heikojentzsch/typo3_forum/commit/27ca2cd27f406e2ff5a2683f3e2432b2b7db48b2) | [TASK] Remove legacy TSFE usage from frontend components |
| [`d8a0185b`](https://github.com/heikojentzsch/typo3_forum/commit/d8a0185bc08d1d66b0a075722b19509b2f908e14) | [TASK] Remove obsolete AJAX implementation |
| [`ab6cd8c2`](https://github.com/heikojentzsch/typo3_forum/commit/ab6cd8c268cca2bcbd7bc64911d4eb73f6264fa2) | [TASK] Remove obsolete AJAX configuration and assets |
| [`63df9657`](https://github.com/heikojentzsch/typo3_forum/commit/63df9657dd849be0a0d3ee11498443daf7614f2d) | [TASK] Replace legacy CObject ViewHelper rendering |
| [`5b1f9210`](https://github.com/heikojentzsch/typo3_forum/commit/5b1f9210a17ad89dddbf716982a3bc72acbf68b0) | [TASK] Normalize TypoScript rendering service line endings |
| [`504b5e6e`](https://github.com/heikojentzsch/typo3_forum/commit/504b5e6efa58ae7f93fc21d3dbb19281b200aa7e) | [TASK] Remove legacy controller context dependencies |
| [`de68ca65`](https://github.com/heikojentzsch/typo3_forum/commit/de68ca65be907e65df8e8ab39f29a743134d71a2) | [TASK] Migrate quote rendering to TYPO3 v14 view factory |
| [`3197a4ed`](https://github.com/heikojentzsch/typo3_forum/commit/3197a4edd41a0a9b688e750a7f1d5b828a91573c) | [TASK] Remove obsolete text parser legacy code |
| [`075b664a`](https://github.com/heikojentzsch/typo3_forum/commit/075b664a7c0d228ec76f25e754a2ccf74a103495) | [TASK] Migrate repository APIs for TYPO3 v14 |
| [`262cd924`](https://github.com/heikojentzsch/typo3_forum/commit/262cd924bd476d7b85267e7f62bdcebb67120f5c) | [BUGFIX] Fix invalid repository calls |
| [`ec7ec679`](https://github.com/heikojentzsch/typo3_forum/commit/ec7ec6798b6fa9adea4bc9375719c1f17a6f1432) | [BUGFIX] Fix TagValidator lifecycle for TYPO3 v14 |
| [`8da335e2`](https://github.com/heikojentzsch/typo3_forum/commit/8da335e2ec0808917a6bc6a752693bb3a0cf6528) | [TASK] Modernize Extbase repositories for TYPO3 v14 |
| [`64bccde8`](https://github.com/heikojentzsch/typo3_forum/commit/64bccde821c26e11da910fa41139fe9ca1c28133) | [TASK] Modernize unit tests for TYPO3 v14 |
| [`8eee197b`](https://github.com/heikojentzsch/typo3_forum/commit/8eee197bedffaf20a360bdb018b39910bc795ba3) | [TASK] Modernize BBCode preview for TYPO3 v14 |
| [`59655866`](https://github.com/heikojentzsch/typo3_forum/commit/596558667b4831d694a24624ef7aef8d3a2baee0) | [TASK] Resolve TYPO3 v14 P0 compatibility issues |
| [`15d9d1c2`](https://github.com/heikojentzsch/typo3_forum/commit/15d9d1c21e5f5e2a0f151abd3324422bbba2592d) | [TASK] Resolve TYPO3 v14 P1 runtime and code issues |
| [`8b69faef`](https://github.com/heikojentzsch/typo3_forum/commit/8b69faef5dc93beb7b79f22d6717ce10a8e16683) | [TASK] Resolve TYPO3 v14 P2 legacy code issues |
| [`ad78c393`](https://github.com/heikojentzsch/typo3_forum/commit/ad78c393257854746330c6935400f2e973906b31) | [TASK] Remove dead code and obsolete assets |
| [`ada7e6d2`](https://github.com/heikojentzsch/typo3_forum/commit/ada7e6d265ff464b89a60a99a3aa6a5c083c906b) | [DOCS] Document TYPO3 v14 migration status |
| [`ee7103e0`](https://github.com/heikojentzsch/typo3_forum/commit/ee7103e0b860cc524e30b1b5a7523c86e9518905) | [TASK] Modernize Fluid rendering for TYPO3 v14 |
| [`817ba5d1`](https://github.com/heikojentzsch/typo3_forum/commit/817ba5d1f2b84e610945db6a78432c8ecb2133b0) | [TASK] Remove obsolete Fluid templates |
| [`72353379`](https://github.com/heikojentzsch/typo3_forum/commit/723533794ac6d4c5fd588779c2bef99d0144ca12) | [DOCS] Update TYPO3 v14 Fluid migration status |
| [`a358b760`](https://github.com/heikojentzsch/typo3_forum/commit/a358b760367384d497bcb648c6b36be883e81943) | [TASK] Remove obsolete controller context legacy code |
| [`17e157b3`](https://github.com/heikojentzsch/typo3_forum/commit/17e157b3ef75643b01da1783ab833fa94c37cf7c) | [TASK] Clean up remaining TYPO3 legacy patterns |
| [`abd9119d`](https://github.com/heikojentzsch/typo3_forum/commit/abd9119d153871b95cc44f77699e52c8a244ffd4) | [BUGFIX] Handle missing attachment storage |
| [`f0eff5e7`](https://github.com/heikojentzsch/typo3_forum/commit/f0eff5e79992402050cd95a4b3e1257d0204edf9) | [BUGFIX] Fix invalid backend asset references |
| [`aa08dd7d`](https://github.com/heikojentzsch/typo3_forum/commit/aa08dd7ddb43096edf3eb85b89d205bcacfd31e0) | [TASK] Remove obsolete dispatch TODOs |
| [`4728c751`](https://github.com/heikojentzsch/typo3_forum/commit/4728c751d776f655eb7d22b3d51900ecd9610181) | update Readme after PR7 |
| [`1440ef38`](https://github.com/heikojentzsch/typo3_forum/commit/1440ef38190797c54a048c3cfb0a4c113c1e1edd) | [BUGFIX] Send forum mail through TYPO3 mailer |
| [`0d83908a`](https://github.com/heikojentzsch/typo3_forum/commit/0d83908a25728edd001bdaae3ea9d948c3bd1bb2) | [BUGFIX] Fix defects exposed by static analysis |
| [`6a33fe1d`](https://github.com/heikojentzsch/typo3_forum/commit/6a33fe1d720582068301b31eee7c4471bf09aef9) | [TASK] Modernize static analysis for TYPO3 v14 |
| [`d5943098`](https://github.com/heikojentzsch/typo3_forum/commit/d594309854bda6a6591b320eeb7c5b0894b3c1e4) | [TASK] Modernize Composer QA tooling |
| [`bb075bbe`](https://github.com/heikojentzsch/typo3_forum/commit/bb075bbe358cad999bebe6c53aebe450214c40cf) | [TASK] Modernize release packaging |
| [`5d93a47e`](https://github.com/heikojentzsch/typo3_forum/commit/5d93a47ebd5a09f2859c08ccc1d3d2af480509b9) | [TASK] Modernize authoritative GitLab CI and publication |
| [`fb886790`](https://github.com/heikojentzsch/typo3_forum/commit/fb886790e235a52232415bce8999ecaf97f40cc7) | [TASK] Add minimal public GitHub verification |
| [`a2ef70c4`](https://github.com/heikojentzsch/typo3_forum/commit/a2ef70c449424ec61f1e5a6fa0303bbd99f5e00d) | [TASK] Rebuild the TYPO3 v14 DDEV environment |
| [`c1b24da3`](https://github.com/heikojentzsch/typo3_forum/commit/c1b24da3b7fdc81b8f83741411ebfb3069d29135) | [TASK] Finalize tooling checks and documentation |
| [`095a0d6a`](https://github.com/heikojentzsch/typo3_forum/commit/095a0d6a562cd0d0e29c069d274f47c6a32af10c) | [TASK] Add non-interactive DDEV bootstrap |
| [`a7504ac4`](https://github.com/heikojentzsch/typo3_forum/commit/a7504ac42ca5e866b5d2ee696beb14acca47848a) | [TASK] Provision the forum development fixture |
| [`bf02a215`](https://github.com/heikojentzsch/typo3_forum/commit/bf02a2155fa0e2d67e8247428ee577ed9c625bb8) | [TEST] Verify bootstrap idempotence and development smoke checks |
| [`4b9f2976`](https://github.com/heikojentzsch/typo3_forum/commit/4b9f2976cb7d7da46c51353844b3ff3396084d3a) | [DOCS] Document automated DDEV setup |
| [`b199dc05`](https://github.com/heikojentzsch/typo3_forum/commit/b199dc05d783485c2d075c704e73d80cf1647fb4) | [BUGFIX] Verify the mounted DDEV package path |
| [`796fc8a5`](https://github.com/heikojentzsch/typo3_forum/commit/796fc8a565ee5db81c1ea416b708ff9b527d0ec3) | [BUGFIX] Accept supported TYPO3 14 releases |
| [`6444142c`](https://github.com/heikojentzsch/typo3_forum/commit/6444142cc2e53f76f3a64c5da451749fa51fc59a) | [BUGFIX] Read the version from TYPO3 core |
| [`bd706fdf`](https://github.com/heikojentzsch/typo3_forum/commit/bd706fdf236af8b0084861763252dd31d3c815ed) | [BUGFIX] Trust the generated DDEV site host |
| [`b0d2cd50`](https://github.com/heikojentzsch/typo3_forum/commit/b0d2cd50da33c8979753cf4a23750e92213461f9) | [BUGFIX] Use stored smiley resource identifiers |
| [`bfaa2bfe`](https://github.com/heikojentzsch/typo3_forum/commit/bfaa2bfefcb27cb01553030f3522ab9f8d2d89b4) | [TASK] Improve DDEV HTTP failure diagnostics |
| [`7bcccf26`](https://github.com/heikojentzsch/typo3_forum/commit/7bcccf2697817c1dbe7a3904e82ff50994fa9041) | [BUGFIX] Autowire the Ajax controller |
| [`a056c459`](https://github.com/heikojentzsch/typo3_forum/commit/a056c45942f345ccd93da3411a46a3082ec5df4b) | [BUGFIX] Match the BBCode preview request |
| [`ae5286d1`](https://github.com/heikojentzsch/typo3_forum/commit/ae5286d1c91bfafb8bcc4b4c99acec2d495e7a3d) | [BUGFIX] Verify the frontend login session |
| [`8637704f`](https://github.com/heikojentzsch/typo3_forum/commit/8637704f12a464d09383cfa8e6da7b531b8de201) | [BUGFIX] Configure frontend login storage |
| [`a99e2dde`](https://github.com/heikojentzsch/typo3_forum/commit/a99e2dde71360709571e4266641bb0f017751768) | [BUGFIX] Resolve the DDEV Mailpit URL |
| [`9bf51062`](https://github.com/heikojentzsch/typo3_forum/commit/9bf51062090d8596b35e7be36ef8de41b54cd0a5) | [BUGFIX] Render generated frontend pages |
| [`99f6ff66`](https://github.com/heikojentzsch/typo3_forum/commit/99f6ff6660e9a6499d97e83c874b06109ac2ea37) | [TASK] Add read-only forum migration preflight |
| [`369432c3`](https://github.com/heikojentzsch/typo3_forum/commit/369432c3f48e4d02275dcf46287dabe042304cd0) | [TASK] Add validated and journaled migration plans |
| [`a6a3275b`](https://github.com/heikojentzsch/typo3_forum/commit/a6a3275b2a40528a4f5b7eda02e212faa9ea6ec3) | [TASK] Register forum migration commands and v14 wizard |
| [`6392d245`](https://github.com/heikojentzsch/typo3_forum/commit/6392d2457dc21353903be7f08d446a32821e9345) | [DOCS] Document the TYPO3 v14 migration path |
| [`069f7d3c`](https://github.com/heikojentzsch/typo3_forum/commit/069f7d3cc189798fe90002ef8008b255d831ad53) | [BUGFIX] Complete generated frontend data access |
| [`165fa9f5`](https://github.com/heikojentzsch/typo3_forum/commit/165fa9f508437cdf8c2c43968d7e5313fc44b7ab) | [BUGFIX] Complete DDEV frontend interactions |
| [`c4af6f7e`](https://github.com/heikojentzsch/typo3_forum/commit/c4af6f7e3800499427559f01429878d7d5d7f259) | [BUGFIX] Complete DDEV frontend interactions |
| [`4ede7df3`](https://github.com/heikojentzsch/typo3_forum/commit/4ede7df3677895958bb42f418a4250f21843fa03) | [BUGFIX] Allow local forum content during verification |
| [`bc866a02`](https://github.com/heikojentzsch/typo3_forum/commit/bc866a02a9af990822f66f59b843b19cc9981ad5) | [BUGFIX] Show child forum last post |
| [`937ae896`](https://github.com/heikojentzsch/typo3_forum/commit/937ae896abbff74fe0eec2a290f96bbdcbbb6df4) | [BUGFIX] Reset topic redirect URI state |
| [`39ae4590`](https://github.com/heikojentzsch/typo3_forum/commit/39ae4590d9841d43caf2402d3cb688471546df70) | [TEST] Avoid service construction in forum regression |
| [`391dd7f2`](https://github.com/heikojentzsch/typo3_forum/commit/391dd7f22405d1a5dffedfff6bfc5d56876204f4) | [TASK] Improve DDEV post layout |
| [`7e64400c`](https://github.com/heikojentzsch/typo3_forum/commit/7e64400c2bb108e85d540371e413b4c9af43c0a9) | [BUGFIX] Link attachment names to downloads |
| [`708655c2`](https://github.com/heikojentzsch/typo3_forum/commit/708655c2f916a9b986836f15d90dc292590599d3) | [BUGFIX] Preserve attachment download contents |
| [`6ff4b008`](https://github.com/heikojentzsch/typo3_forum/commit/6ff4b0080e510ef4330f8fe9a96799d94efc6b0d) | [TASK] Label and arrange post actions |
| [`c7dc9246`](https://github.com/heikojentzsch/typo3_forum/commit/c7dc92462340a19cb9ef86ec9be4b47e451ef5d0) | [TASK] Hide missing user avatars |
| [`b4c42db5`](https://github.com/heikojentzsch/typo3_forum/commit/b4c42db50518714b4af285871dc1b8381900e835) | [BUGFIX] Recognize generated DDEV site configuration |
| [`b189e49f`](https://github.com/heikojentzsch/typo3_forum/commit/b189e49f9c4275e46c98553bb59c8208b13a2095) | [TASK] Compact attachment details |
| [`2325fc20`](https://github.com/heikojentzsch/typo3_forum/commit/2325fc2027ab2cc99ca25bd8aac4c02118a50443) | [TASK] Flatten DDEV attachment panel |
| [`caac6ac0`](https://github.com/heikojentzsch/typo3_forum/commit/caac6ac06029eb7d57ee7929c55932bc5655d726) | [BUGFIX] Complete DDEV statistics and page access |
| [`b937e7f8`](https://github.com/heikojentzsch/typo3_forum/commit/b937e7f8fc1a1094549cd730d858d1857fd2102a) | [TASK] Add moderator-only DDEV forum |
| [`fbcdd4a5`](https://github.com/heikojentzsch/typo3_forum/commit/fbcdd4a5573ce680c65bfac24b77599d57e9a5a1) | [BUGFIX] Allow moderator forum topics |
| [`f7032b48`](https://github.com/heikojentzsch/typo3_forum/commit/f7032b48a9ad1d81dc007165c9eae9302403a3ac) | [FIX] Harden migration readiness and verification |
| [`65b14cdb`](https://github.com/heikojentzsch/typo3_forum/commit/65b14cdbd86eb05487e7868314285b06cb0f51d9) | [TASK] Correct migration QA coverage |
| [`acde609b`](https://github.com/heikojentzsch/typo3_forum/commit/acde609b7efd4f61f24f0c678cc7e3e53783c368) | [DOCS] Update migration guarantees and verification status |
| [`97cfaec0`](https://github.com/heikojentzsch/typo3_forum/commit/97cfaec03059fe412d1f32ac022d6d830ddd605c) | [BUGFIX] Handle denied forum read access |
| [`bdb65ab0`](https://github.com/heikojentzsch/typo3_forum/commit/bdb65ab04d3295be95116b0e9a64c92cb30db2c5) | [BUGFIX] Allow topics in managed moderator forum |
| [`89d1d3ac`](https://github.com/heikojentzsch/typo3_forum/commit/89d1d3ac1dac2eac03fb03c93884cd92ad238cff) | [FEATURE] Add hierarchical notification email settings |
| [`179654fc`](https://github.com/heikojentzsch/typo3_forum/commit/179654fc25b21dc830cac9d00afbe3b15ca3a30f) | [FEATURE] Compose configurable subscription notifications |
| [`27279bb4`](https://github.com/heikojentzsch/typo3_forum/commit/27279bb4104aee42fe9bb686b4162eae94e1189c) | [TEST] Cover notification options and forum inheritance |
| [`3fa9e4dd`](https://github.com/heikojentzsch/typo3_forum/commit/3fa9e4dd5d9c465fcecce7559585d3d0f5a425ff) | [DOCS] Document configurable notification emails |
| [`cc7d5088`](https://github.com/heikojentzsch/typo3_forum/commit/cc7d508862709b5adf1df8dde65b877ba292078d) | Replace BBCode editor with TinyMCE and Unicode emoji support |
| [`68d3ed92`](https://github.com/heikojentzsch/typo3_forum/commit/68d3ed923d43a10f24fe935b8ee052379338dec0) | Harden v14 security, remove legacy runtime and consolidate documentation |

</details>

<a id="history"></a>
## 6. Historical README information

The extension originated at Mittwald CM Service and was subsequently maintained
upstream by Agentur Pottkinder. Older documents and screenshots belong to those
versions; the current branch's code and guides above are authoritative here.
Historical upstream support contact: `support@agentur-pottkinder.de`.

Migration from `mm_forum` was historically supported from `mm_forum` 1.0 to
`typo3_forum` 1.1.0. It is separate from this branch's v12-to-v14 migration and is
not offered as an automated path by the v14 tooling.

Earlier audit counts and environment limitations are preserved in Git history,
not repeated as current guarantees. The README before this consolidation is
available at [commit cc7d5088](https://github.com/heikojentzsch/typo3_forum/blob/cc7d508862709b5adf1df8dde65b877ba292078d/README.md).
