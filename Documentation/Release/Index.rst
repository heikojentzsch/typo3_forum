v14 release acceptance checklist
===============================

All unchecked items are release gates, not claims of completed testing.
Record the tester, date, exact v14 commit, environment, outcome and evidence for
each item. Release approval remains pending until applicable gates are signed off.

Automated and installation checks
--------------------------------

* [ ] ``composer ci`` and editor build/tests pass for the release commit.
* [ ] Composer/npm audits reviewed; remaining advisories explicitly assessed.
* [ ] Install the generated archive in a clean supported TYPO3 installation.
* [ ] Confirm utf8mb4 connections/columns, routing, login, permissions and mail.
* [ ] Review overridden templates: token-bearing POST forms replace action links.
* [ ] Verify denied downloads and direct-file access for restricted attachments.
* [ ] Run optional MariaDB locking/concurrency integration test on a disposable DB.

Migration rehearsal -- OPEN acceptance item
-------------------------------------------

A full rehearsal on an anonymized copy of the existing installation has not been
performed. An appropriate copy is required; the synthetic fixture cannot replace
it. Include database and matching files/configuration without live credentials.

* [ ] Run the documented preflight/check, inspect the plan and resolve blockers.
* [ ] Run apply/verify and compare counts, ACLs, FAL references, routes and content.
* [ ] Interrupt and resume migration; verify idempotency and journal handling.
* [ ] Review converted BBCode, custom tags, smilies, nested quotes, source links,
      signatures, reports, supplementary Unicode and long/malformed content.
* [ ] Record runtime, maintenance window and unresolved manual conversion cases.

Real mobile devices and desktop
-------------------------------

* [ ] iOS Safari and Android Chrome: type, select, format, paste, undo and submit.
* [ ] Soft keyboard, rotation, zoom and narrow viewport: toolbar and emoji popup
      remain reachable; only the emoji grid scrolls after repeated Show more.
* [ ] Desktop keyboard navigation, focus visibility, emoji search and selection.
* [ ] Upload attachments, edit/delete posts, quote links, new-window links, report
      workflows and subscription confirmation, including session expiry.

Backup and restore
------------------

* [ ] Take a consistent pre-migration DB/files/configuration backup in maintenance.
* [ ] Restore that backup into an isolated environment and prove login, attachments
      and original content work. Record recovery time and responsible operator.
* [ ] Rehearse rollback with matching extension version, schema, files and caches.
* [ ] Agree release go/no-go, maintenance communication and post-release monitoring.
