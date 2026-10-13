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

Inline pictures in posts
-----------------------

Use TinyMCE's image toolbar button to select one or more pictures, paste an image
from the clipboard, or drag files into the editor. On narrow screens the button
may be in the toolbar overflow. Selection uses the device's normal file/photo
picker. JPEG, PNG and WebP are accepted; HEIC, GIF and SVG are not supported by
this inline workflow. The separate attachment selector retains its configured
allowlist. Camera/gallery capabilities depend on the browser and device.

Previews stay local until the post is submitted. Original file bytes, dimensions,
format and filename are retained: no browser/server resizing or re-encoding of
the attachment takes place. This also retains EXIF/GPS and other metadata in the
original download. The server independently checks MIME, size and actual GD
decodability. JPEG, PNG and WebP originals are supported. At most 10 new images,
16 megapixels per image and the configured attachment size limit (default 4 MiB,
hard ceiling 20 MiB) apply. Larger files are rejected instead of silently reduced.
Adjust PHP/web-server limits for aggregate uploads; individual image limits do
not override ``post_max_size``, ``upload_max_filesize`` or ``max_file_uploads``.

Persisted posts and reopened editors use separate FAL-processed PNG thumbnails
fitting within 800 x 600 pixels, without upscaling. TYPO3 creates them on demand
and reuses the processed files. Both the original and the processing storage
must remain private and outside the public web root for local drivers. Use the
private storage's own ``_processed_`` folder; do not redirect processing to public
fileadmin. The image endpoint checks permissions before accessing either variant.

Click a thumbnail in a post or its inline-image attachment entry to open the
bundled gallery. It groups images by post, avoids duplicate entries for the same
image and supports Previous/Next, arrow keys, swipe, Escape, a fit-to-window view,
a scrollable original-pixel-size view and original download. The gallery loads
original bytes only when opened; without JavaScript the link opens the original.
Custom layouts must load ``gallery.js`` and ``gallery.css`` from the default
layout. Ordinary non-inline attachments keep their existing download workflow.

Pictures appear in the post and remain downloadable as attachments. Remove an
inline picture in the editor to remove its owned attachment when saving an edit;
ordinary attachments still use the keep checkboxes. Removing the source image
also makes references in other posts/quotes unavailable. A quoted image does not
grant access to its source post. Image-only posts are supported; their plain-text
projection contains ``[Image]`` for search and notifications.

Required one-time setup:

1. Install PHP GD with JPEG/PNG/WebP support and Fileinfo (declared Composer
   dependencies), and configure TYPO3's image processing (ImageMagick/GraphicsMagick)
   for JPEG/PNG/WebP. Check the TYPO3 environment image-processing tests.
2. Apply TYPO3's additive schema update for
   ``tx_typo3forum_domain_model_forum_attachment.inline_image_key`` and its index.
3. In the TYPO3 backend, create an online, writable FAL storage with **Is public**
   disabled. For the Local driver use an absolute, persistent path outside the
   public web root, writable by the PHP process. Local public-root paths, including
   symlinks resolving into it, are rejected. Remote storage must deny anonymous
   access at the storage provider as well.
4. In Settings / Extension Configuration / typo3_forum, set
   ``inlineImages.storageUid`` to that storage UID. Zero accepts only an already
   private default storage. Public fileadmin is never used as an inline fallback.
5. Clear caches and verify creation, editing and denied image access. Back up both
   the database/FAL references and private storage directory together.

For the synthetic DDEV installation, a suitable directory is
``/var/www/html/var/forum-inline-images`` (create it first and configure an absolute
Local storage as above). This is separate from the fixture's public fileadmin.
Do not expose or point production storage at development directories.

The post HTML contains only an opaque ``data-forum-image`` reference and alt text;
no base64 bytes, local blob URLs or permanent file URLs are persisted. File bytes
live in FAL. Rendering generates a Post/inlineImage URL on PAGE type 43568276
with ``thumbnail=1`` for the preview and without it for the original;
that action checks source-post read permissions on every request and returns
``private, no-store`` and ``nosniff`` headers. It does not increment download counts.
Existing images previously uploaded with resizing cannot regain their original
resolution: upload the original file again if needed. Existing BBCode/HTTP(S)
image URLs are unchanged; migration does not fetch remote
images or import them into private storage.

Custom post forms need multipart encoding, ``mmf:form.richTextEditor``, and the
existing ``newAttachments`` multiple-file field. Images are unavailable in forms
without that upload field. New-topic and topic rendering stay uncached so request
tokens belong to the current browser. Include these routes in deployment cache
reviews and exclude the protected image endpoint from shared proxy/CDN caching.

Files are not retained across a page reload or server-side validation rejection;
new images must then be selected again. A missing image preview reports this in
the editor. Navigation away from an unsent draft does not create permanent files.

Storage and security
--------------------

The shared HTML vocabulary supports paragraphs, line breaks, bold, italic,
underline, strikethrough, ordered/unordered lists, links, quotations, code blocks
and inline picture references plus existing HTTP(S) images. Pictures and
attachments use the forum attachment relationship. Arbitrary styles, scripts, embedded documents and executable URLs
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
   HTML columns and the attachment ``inline_image_key`` column/index. Do not remove
   source columns or legacy parser definitions.
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
repeatability, concurrent edits and tampered plans. Playwright exercises the
editor, emoji selection, image selection/paste/drop, submission and loading failure at desktop
and mobile viewport sizes. ``node site-check.mjs`` inside the DDEV container
checks the authenticated real TYPO3 topic form without submitting a post.
After configuring private storage, ``node image-site-check.mjs`` runs a mutating
acceptance test only against the managed DDEV fixture: it creates a temporary
moderator topic, checks image-only posting, byte-identical original storage,
thumbnail generation, gallery, quote/edit, denied anonymous
and member access, removal, and deletes the test topic on success. A failed run
may leave a topic named "Inline image acceptance ..." for inspection.
These checks do not replace acceptance on actual iOS/Safari and Android/Chrome
devices, especially selection, virtual keyboards, IME input and long posts.

Submission feedback
-------------------

The default forum layout loads a separate submit module and stylesheet for all
forum POST forms, including forms without TinyMCE. The first valid submission
disables submit controls and shows a loading indicator. Native validation and
editor loading cancellation do not lock the form. Browser Back restores controls.
Submit-button name/value pairs are preserved. Reduced-motion preferences disable
spinner motion. Custom layouts must include the default layout's submit assets.

This is a browser UX guard against repeated clicks on the same page, not a
server-side idempotency guarantee across tabs, manual retries or replayed requests.
