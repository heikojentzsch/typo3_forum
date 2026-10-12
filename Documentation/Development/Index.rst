Development and release tooling
===============================

Work on branch ``v14``. The root README documents prerequisites, DDEV setup,
Composer checks, editor builds and browser tests. ``Build/setup-ddev.sh`` creates
synthetic development data; never run it against a production installation.

Code conventions
----------------

Use the root EditorConfig and PSR-12-based ``.php-cs-fixer.php`` for project PHP.
Run ``.Build/bin/php-cs-fixer fix`` and then ``composer ci``. Retain PHPDoc that
supplies types for legacy methods; removing those annotations loses analysis.
Run ``npm run format`` and ``npm run format:check`` in
``Resources/Private/Editor`` for project-owned frontend/build JavaScript, CSS and YAML.
Fluid templates use tabs and LF. Do not reformat upstream vendor code or edit
generated editor bundles: rebuild them with ``npm run build``.

Validation
----------

``composer ci`` includes syntax, PHPUnit, PHPStan, TypoScript lint and style.
Editor Playwright tests cover rich text, links, quotes and emoji popup layout.
``site-check.mjs`` checks the real authenticated DDEV form without creating posts.
``security-check.mjs`` additionally checks CSRF in the local fixture, temporarily
toggling and restoring a subscription. The optional MariaDB concurrency test requires a dedicated disposable database;
see the migration guide. A skipped test is not a passed acceptance gate.

Packaging and publication
-------------------------

``./build-release.sh <version>`` creates the deterministic allowlisted release
archive. Review its contents and checksums. Production editor assets and their
licenses are included; local credentials, fixtures and development dependencies
are excluded. Private GitLab configuration owns the full QA/release pipeline;
GitHub runs verification. Release publication is a manual pipeline action.
Neither a local test run nor a Git push proves remote CI success.

Before publication complete ``../Release/Index.rst`` and record the exact commit,
artifact checksum, environment and evidence for each acceptance gate.
