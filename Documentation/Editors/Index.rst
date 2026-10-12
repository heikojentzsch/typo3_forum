Rich-text editors and Unicode in v14
===================================

Editor and emoji selection
--------------------------

TinyMCE is the only frontend editor, self-hosted and included in release
archives. No cloud account, CDN or runtime Node.js service is required.
Bundled versions are recorded in ``Resources/Public/Editor/VERSIONS.json``.
The GPL distribution, upstream license files and notices ship with the assets.
Commercial premium features are not included. No editor choice is stored in
the browser or user profile, and no extension setting selects an editor.

New-topic and reply/edit forms initialize TinyMCE automatically. While loading,
editing and submission are temporarily paused to preserve the initial text.
If loading fails or JavaScript is disabled, the original textarea remains
available for editing and submission.

The emoji dropdown works on desktop and mobile. It searches
German and English labels/keywords, filters by category, and includes skin-tone
variants and joined sequences from the pinned Emojibase 17.0.0 dataset (Emoji
17.0). Results are displayed in batches of 80; "Show more" makes all matches
available. The local JSON catalog loads only on first opening, without a CDN.
The popup stays inside the visible viewport, opens above its button when needed,
and is capped at 480 pixels high. Only the emoji grid scrolls; search, categories
and action buttons stay visible even after loading more results. Its bounds adapt
to viewport/virtual-keyboard changes.
The interface follows the page's German/English language. Escape, the close
button, or clicking outside dismisses the dropdown. Selecting an emoji inserts
real Unicode at the saved selection, including replacement of selected text.
UTF-8 is an encoding, not a fixed emoji catalog: future additions need a dataset
and asset rebuild. Device keyboards and pasted characters remain supported.
Glyph appearance and support for newer sequences depend on the OS/fonts;
unsupported glyphs are not silently removed from the catalog.

TinyMCE's optional status-bar branding is disabled for this self-hosted GPL
installation, as supported by its ``branding`` setting.
See the upstream `TinyMCE branding documentation
<https://www.tiny.cloud/docs/tinymce/latest/statusbar-configuration-options/#branding>`_.
All distributed license files and copyright notices are retained.

Storage and security
--------------------

The shared HTML vocabulary supports paragraphs, line breaks, bold, italic,
underline, strikethrough, ordered/unordered lists, links, quotations, code blocks
and existing HTTP(S) images. Attachments continue to use the forum attachment
workflow. Arbitrary styles, scripts, embedded documents and executable URLs
are not supported. The server sanitizes submitted HTML and rendered HTML;
the editor's own filtering is not a security boundary.

The link dialog automatically adds ``https://`` to links without a protocol,
without a confirmation prompt. Link targets are fixed to ``_blank`` and the
target selector is hidden. Server-side sanitization also enforces a new browsing
context and ``noopener noreferrer`` for pasted or manipulated links.

Quotes have a visible border and background in both the editor and rendered
posts. Quotes carrying ``data-post`` (including Quote-button and migrated
``[quote=ID]`` quotes) receive a localized link to the original post at render
time. The Post/show route resolves the topic page and anchor and applies the
normal read-access checks. URLs are not persisted in quote HTML. Plain quotes
without a post reference receive the same frame without a source link.

``post.text_html``, ``reportcomment.text_html`` and
``fe_users.tx_typo3forum_signature_html`` distinguish HTML from legacy content.
NULL means not migrated; an empty string is a migrated empty value. Never
guess the format from angle brackets. Migration fills these separate fields
without overwriting the BBCode source. Normal subsequent editing updates the
HTML and the post's plain-text ``text`` projection for search/notification use.
The original source is therefore retained through migration, not as an unlimited
revision history after edits. Take a database backup before migration.

Backend HTML-field edits are sanitized too. Source-only backend edits are
converted using the legacy converter. If both fields are submitted, HTML is
authoritative. Integrations writing directly to SQL must maintain both fields.

Existing templates must use ``mmf:form.richTextEditor`` with
``property="text" value="{post.editorText}"``. The old markItUp assets are no
longer loaded. The existing AJAX endpoint/argument name remains available, but
its input is now HTML, not BBCode. Custom templates, integrations and frontend
TypoScript overrides must be reviewed. Legacy database definitions are retained for migration inspection. The old
regex parser, markItUp runtime and bundled GeSHi library have been removed;
custom PHP integrations using those classes must be adapted.

utf8mb4 prerequisite
--------------------

For MySQL/MariaDB, all textual columns in the forum's migration integrity tables
and ``fe_users``, and the relevant connections' client/connection/result character
sets must use ``utf8mb4``. ``utf8``/``utf8mb3`` is insufficient for supplementary
Unicode characters. Set TYPO3's connection ``charset`` to ``utf8mb4`` and have
the database administrator convert existing columns with a compatible collation,
after backup and review of indexes. Set database/table defaults accordingly
so future columns inherit utf8mb4. The extension does not run blanket ALTER
TABLE character-set conversions automatically.

``forum:migration:check`` reports ``UTF8MB4_CONNECTION_REQUIRED`` and
``UTF8MB4_COLUMN_REQUIRED`` with the affected table/column. These block the
migration. Frontend post writes and backend rich-text writes also check the
requirement. SQLite/PostgreSQL do not use MySQL's utf8mb3/utf8mb4 naming; the
MySQL-specific check is skipped there. Unicode transport/storage is still required.

Migration procedure
-------------------

Follow ``Documentation/Migration/Index.rst`` for source preflight, backup,
maintenance mode, plan permissions, checksum confirmation and recovery.

1. Capture the source preflight before upgrading the old installation.
2. Upgrade code and apply additive schema changes, including the three nullable
   HTML columns. Do not remove source columns or legacy parser definitions.
3. Satisfy the utf8mb4 prerequisite. Run ``forum:migration:check`` with the
   protected source preflight and an output report.
4. Resolve all ``BBCODE_REVIEW_REQUIRED`` and
   ``LEGACY_DEFINITION_REVIEW_REQUIRED`` findings. They identify unknown tags,
   unbalanced markup, unsupported URLs, customized expressions, custom smileys
   or group-restricted formatting. Implement/test an explicit conversion rule
   or correct the source with an audit trail; never suppress the finding to
   approve a lossy conversion. No regex stored in the database is executed.
5. Generate a new ``forum:migration:plan``. Existing plans must be regenerated
   because rich-text writes extend the migration scope. Review source/target
   content in the protected plan and run ``forum:migration:apply --dry-run``.
6. Apply with the exact plan checksum, then run ``forum:migration:verify``.
   The native repeatable wizard uses the same service and includes rich text.
7. Clear frontend caches and check migrated display, editing, quotations,
   lists, code, links, signatures, moderation comments and notifications.

The converter supports ``b``, ``i``, ``u``, ``s``, ``quote``/``quote=ID``,
``list``/``list=1`` with ``[*]``, ``url``/``url=URL``, ``img``, and
``code``/``code language=LANG``/``code=LANG``. Quote IDs remain in
``data-post``; their original-post link is generated when rendered.
Bare ``www.example.org`` links are normalized to HTTPS; other unsupported URL
schemes require review. Known smiley shortcuts map to Unicode in text; URLs and code blocks
are protected. Unknown definitions block migration instead of being discarded.
The built-in mappings are ``:)``, ``;)``, ``:|``, ``>:(``, ``:(`` and ``:/``;
additional site-specific shortcuts require an explicit mapping.
Until migration, the read path can display supported legacy content through the
safe converter, without accessing smiley image files. Unknown tags stay literal.

Each HTML write participates in the existing journal, row lock and source/target
fingerprint checks. Original text, relationships, passwords and attachments stay
unchanged during conversion. Interrupted runs can resume under the existing
lock-recovery rules. Plans/reports can contain complete post text and signatures:
store them outside the web root with the required 0600 permissions.

Build and verification
----------------------

The committed assets are built from ``Resources/Private/Editor``::

    npm ci
    npm run build
    npx playwright install --with-deps chromium
    npm test

Node.js is a development/build dependency only. The package lock pins editor,
build-tool and test-tool versions. Keep source, lockfile, generated assets and
upstream license notices together when updating. Release packaging excludes
``node_modules`` and includes the build source and bundled runtime assets.

PHPUnit covers sanitization, Unicode, conversion, migration source preservation,
repeatability, concurrent edits and tampered plans. Playwright exercises both
editor, emoji selection, submission and loading failure at desktop
and mobile viewport sizes. ``node site-check.mjs`` inside the DDEV container
checks the authenticated real TYPO3 topic form without submitting a post.
These checks do not replace acceptance on actual iOS/Safari and Android/Chrome
devices, especially selection, virtual keyboards, IME input and long posts.
