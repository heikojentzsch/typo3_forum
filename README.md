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

The nine frontend content types are Forum, User Profile, Moderation Reports,
User List, Dashboard, Tag List, Post List, Topic List and Statistics Box.
Their identifiers are `typo3forum_forum`, `typo3forum_userprofile`,
`typo3forum_moderationreports`, `typo3forum_userlist`, `typo3forum_dashboard`,
`typo3forum_taglist`, `typo3forum_postlist`, `typo3forum_topiclist` and
`typo3forum_statsbox`.

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

### TinyMCE, Unicode emoji and quotes

- TinyMCE 8 is the sole editor and is bundled locally under GPL. No cloud account
  is needed; optional “Powered by Tiny” branding is disabled.
- Bold, italic, underline, strike-through, lists, links, quotes and code blocks
  use server-sanitized HTML. Custom HTML/scripts are not accepted.
- The local Emoji 17.0 catalog offers German/English search, categories and skin
  variants. The compact popup stays within the viewport; only its emoji grid
  scrolls, including after “Show more”. Appearance depends on device fonts.
- Quotes have a visible frame. Quotes with a post reference get a localized link
  to the original post, resolving the correct topic page and anchor at render time.
- Links entered without a protocol receive HTTPS without a prompt. Targets are
  fixed to a new tab/window; the server enforces safe `rel` attributes as well.
- Attachments use a full-width file selector below the label “Anhänge:”.
- The unused jQuery CDN inclusion is removed; custom templates must declare their own dependencies.
- markItUp, its old ViewHelper/panels/parser pipeline and bundled GeSHi are removed.
  Legacy definition records remain available for migration inspection.

Custom forms must adopt `mmf:form.richTextEditor`; the existing preview endpoint
(`type=43568275`, `tx_typo3forum_ajax[text]`) now accepts HTML. Old parser service
configuration and `Format/Quote.html` overrides no longer control rendering.
See [editor and content migration](Documentation/Editors/Index.rst).

### TYPO3 APIs and content types

Dedicated CTypes replace legacy `list_type` plugins. Controller/model validation
uses attributes, requests/responses use the current APIs, and removed TSFE,
controller-context and Signal/Slot paths have been replaced. Repositories use
current Extbase/DBAL APIs and parameter binding. Country handling uses the Core
Country API. Fluid rendering, form mapping, pagination and resource URLs have
been updated for v14.

Entity hydration fallbacks, `LazyLoadingProxy` and the active cache hook remain
where current code still needs them. Unused `SettingsHydrator` has been removed;
this does not replace the active entity hydration paths.

### Notifications and attachment protection

Subscription mail has five global content switches with per-forum inheritance:
forum name in subject and full post text default off; forum, topic and unsubscribe
links default on. Full post text can expose protected content in a recipient's
mailbox, so enable it deliberately. See the [notification guide](Documentation/Notifications/Index.rst).

Downloads now check read access to the parent post before reading files or changing
counters. Upload type checks inspect bytes rather than trusting browser MIME
metadata, and generated storage names use cryptographic randomness.

### Maintenance and verification

Composer QA, PHPStan, PHPUnit, browser tests, DDEV bootstrap, migration tooling and
deterministic release packaging are provided. Dead runtime dependencies and stale
documentation have been cleaned up. The [security review](Documentation/Security/Index.rst)
records reviewed boundaries and remaining risks; it is not a penetration-test
certificate or a guarantee that no vulnerabilities exist.

### Security and custom templates

Explicit write actions now require POST and TYPO3 request tokens. Convert custom
write-action links to Fluid forms with `requestToken="typo3_forum/mutation"`.
Email unsubscribe links show a confirmation form. Attachment downloads check read
access, uploads inspect actual MIME/size, and user-report moderation checks rights.
Restricted attachments need non-public FAL storage; see the security guide.

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
