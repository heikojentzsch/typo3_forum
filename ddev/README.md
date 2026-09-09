# Automated TYPO3 14 development installation

This directory contains the disposable local TYPO3 site for the checked-out
forum extension. Docker and DDEV are the only host prerequisites. DDEV must
support PHP 8.4 and MariaDB 10.11; host PHP and Composer are not used.

From the repository root, run:

```bash
./Build/setup-ddev.sh
```

The command verifies Docker and DDEV, starts the `typo3forum` project, installs
the development dependencies in the web container and resolves
`pottkinder/typo3forum` to the mounted working checkout. It then initializes a
fresh TYPO3 14.3 installation over TCP (`mysqli`, host `db`, port `3306`,
database/user/password `db`), provisions the fixture, publishes assets, clears
caches and checks the rendered site. No prompts or `FIRST_INSTALL` file are
used.

DDEV's TYPO3 settings writer is disabled for this project because it owns and
rewrites `config/system/additional.php`. The bootstrap instead lets TYPO3 setup
persist the verified TCP database connection in `settings.php` and manages a
marked Mailpit block in the ignored `additional.php`. A pre-existing
`#ddev-generated` file is preserved and extended; an unrecognized developer
override is refused rather than overwritten.

From this directory the equivalent convenience command is:

```bash
ddev setup-forum
```

Both entry points call the same implementation. Useful read-only operations
are:

```bash
./Build/setup-ddev.sh --check
./Build/setup-ddev.sh --show-credentials
```

`--check` starts stopped containers but does not install, repair, seed or update
anything. It verifies TYPO3 14.3, the Development context, database target,
local Composer package, managed records and relationships, TypoScript, site
routing, users, ACLs, FAL, Mailpit, backend/login endpoints, forum and topic
rendering, the BBCode preview contract and a published CSS or JavaScript asset.

## Provisioned fixture

The generated page tree has a site root and pages for the forum, login, profile,
user list, dashboard, tags, topics, posts, moderation reports and statistics.
Dedicated storage folders hold forum data and frontend users. Every forum
plugin uses its current TYPO3 14 `CType`; the login page contains the current
frontend-login content type.

The generated root TypoScript template includes Fluid Styled Content and TYPO3
Forum, supplies a minimal navigation and page renderer, and uses the generated
page UIDs for all forum and frontend-login settings. The generated site YAML
uses the actual DDEV URL and imports the extension route enhancer.

The forum data consists of a category, a public readable forum, a sample topic
and a sample post containing `DDEV-FORUM-SAMPLE`. Guests receive read access.
The member group receives only topic/post creation access. The moderator group
receives the scoped moderation/edit/delete/solution permissions and its group
has the extension's moderator flag. ACLs remain enabled.

Synthetic accounts are created with `example.invalid` addresses:

- backend administrator: `forum_admin`
- frontend member: `forum_member`
- frontend moderator: `forum_moderator`

Passwords are generated once with secure randomness. Frontend and backend
database values use TYPO3's configured password hashers. Cleartext development
credentials live in `.bootstrap/credentials.json`, outside `public/`, with
restrictive permissions; this directory is ignored by Git. Normal reruns never
replace this file or reset a password. If a matching unmanaged identity or an
unknown credential state makes ownership ambiguous, provisioning stops instead
of adopting or overwriting it.

The setup reuses an existing writable, online default FAL storage. If none
exists, it creates a managed local `fileadmin/` storage and writable
`fileadmin/user_upload` and asset directories without world-writable modes.
Development mail uses DDEV Mailpit on `localhost:1025`; the final output prints
the actual Mailpit UI URL.

## Repeat runs and recovery

The default operation is an idempotent provision/update. Managed records have
stable logical keys and UIDs in dedicated ownership tables. Completed phases
are recorded only after success, so an interrupted run resumes. Inserts and
ownership records use transactions. A second run preserves passwords,
developer-edited fixture text, user-created pages, forum posts, uploads and
unrelated records.

TYPO3's force setup is used only when the database is completely empty. Any
recognized partial settings file is backed up and completed or repaired with
the DDEV TCP connection first. If TYPO3 tables already exist, setup is not
rerun; missing schema is applied with `extension:setup` and the synthetic admin
is created directly with TYPO3's backend password hasher. A nonempty database
without recognizable TYPO3 setup state, unexpected database target, production
context or foreign fixture ownership is refused.

The bootstrap never runs Git commands, fetches another extension copy, deletes
the database, truncates tables or changes the checked-out application source.
Generated system settings, site YAML, Composer lock/vendor data, credentials and
state remain ignored. The DDEV helper extension and every bootstrap/fixture file
are outside the production release allowlist.

## Smoke-test harness

`Build/test-ddev-bootstrap.sh --isolated-empty-project` exercises an empty
database with stdin closed, injects an interruption after TYPO3 initialization,
resumes it, runs setup again, and verifies stable UIDs/passwords plus preservation
of an unmanaged page and developer-edited sample content. It deliberately
refuses a nonempty database and never resets one.

A DDEV executable is installed, but the Docker client and daemon were unavailable
in the implementation environment. Bash syntax, command help, the missing-Docker
failure path, Composer JSON syntax, PHP parsing, LF/final-newline rules and
`git diff --check` were run. Container-based Composer validation and CI, the
fresh/repeat DDEV bootstrap and real HTTP acceptance were **not executed**. Run
the smoke harness in a disposable checkout before treating runtime acceptance
as complete.
