Security review and operating requirements
==========================================

This review covers application code and dependency checks on branch v14. It is
not a penetration-test certificate or a guarantee that no vulnerabilities exist.
Repeat audits and acceptance checks for the exact release commit.

Boundaries reviewed
-------------------

* Extbase/DBAL parameter binding and migration table/field allowlists; custom
  identifiers must never be interpolated from untrusted request input.
* Strict rich-text sanitization on write/render/preview, URL schemes, legacy
  conversion without executing configured regular-expression replacements.
* Attachment authorization before reading files or incrementing download counts,
  server-side MIME inspection with Fileinfo, actual/declarative size limits,
  random stored filenames, private/no-store and nosniff download headers.
* Read access for quoted/supported posts and moderator rights for user reports.
* POST plus TYPO3 request-token validation before argument mapping for explicit
  mutations. Missing/invalid/wrong-scope tokens return 403; unsupported methods
  return 405. Email subscription links open a confirmation form on GET.
* Same-origin referrer redirects, site-configured cache-purge destinations and
  303 redirects after mutations to prevent resubmitting a POST.

Integrator responsibilities
--------------------------

Custom templates must use ``method="post" requestToken="typo3_forum/mutation"``
on Fluid forms for write actions. Extbase trusted-property fields alone are not
CSRF protection. Browser cookies must be enabled; after session/token expiry,
reload the page before retrying. Core middleware validates the nonce-bound JWT;
the extension checks its scope before invoking mutation actions.

Reading posts/notifications still updates implicit read/view bookkeeping. Explicit
mark-read, subscriptions, votes, writes and moderation use protected POST forms.

For restricted attachments use non-public FAL storage and verify the web server
cannot serve their files directly. A controller ACL cannot protect a public static
file URL. Configure allowed MIME types/sizes conservatively, TLS, trusted hosts,
web-root isolation and restrictive migration plan/journal file permissions.
Do not expose credentials, backups, migration artifacts or development fixtures.

Audit evidence and remaining acceptance
--------------------------------------

Composer and npm advisory audits on 2026-10-12 reported no known advisories in the
installed dependency sets at review time. These are snapshots, not permanent
assurances. Automated regression tests cover malformed content and security
boundaries; real deployment configuration must be tested separately.

Full anonymized production migration, real mobile devices, backup restoration
and restricted attachment storage acceptance remain tracked in
``../Release/Index.rst``. No production migration or external penetration test
was performed as part of this cleanup.
