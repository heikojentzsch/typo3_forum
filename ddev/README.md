# TYPO3 14 development installation

Requires Docker and a current DDEV release supporting PHP 8.4 and MariaDB 10.11.
This is a fresh development site, not an upgraded production fixture.

```bash
cd ddev
ddev start
ddev composer install
ddev exec vendor/bin/typo3 setup
ddev exec vendor/bin/typo3 asset:publish
ddev launch /typo3
```

The setup command is interactive: choose your own administrator credentials.
Use DDEV's database connection (`db`, database/user/password `db`, port 3306).
Generated system configuration is ignored; no credentials are committed.

The existing bind mount exposes the repository at `packages/typo3_forum` inside
the web container. Composer symlinks that local package as `dev-v14`, so the
checked-out working branch is used immediately. Run Composer through DDEV;
the container mount does not exist for a host-side Composer installation.
Bootstrap Package, femanager and the obsolete TYPO3 Console dependency are
not required for this minimal environment and have been removed.

After setup, create a root page in the backend and set its actual UID in
`config/sites/forum-dev/config.yaml` (the supplied value 1 is a placeholder).
Create a root TypoScript template, include **Fluid Content Elements** and
**TYPO3 Forum**, and add a minimal page renderer:

```typoscript
page = PAGE
page.10 = CONTENT
page.10 {
    table = tt_content
    select.pidInList = this
    select.orderBy = sorting
}
```

Create the desired forum/plugin pages and storage folder, then configure the
forum's page IDs and persistence storage PID in the template constants. Add a
dedicated **Forum** content element to the chosen page. Configure a default FAL
storage for attachment testing. No forum records, users or production dataset
are fabricated by this setup.

Container startup was not tested in the Codex environment because Docker is
unavailable. Composer metadata and YAML were statically validated. Real
v12-to-v14 installation acceptance remains a separate release blocker.
