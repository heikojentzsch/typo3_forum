.. _basic:

Installation on v14
===================

Requirements
------------

Use TYPO3 Composer ``^14.3`` and PHP compatible with the entire site's dependency
set (extension requirement ``^8.2``), including Fileinfo. MySQL/MariaDB connections
and forum/user text columns require utf8mb4. See the root README and editor guide.

Setup
-----

1. Install ``pottkinder/typo3forum:dev-v14`` using the VCS repository in the root
   README. Extension key: ``typo3_forum``. Pin a reviewed revision for deployment.
2. Apply TYPO3 database schema updates. For existing sites follow the migration
   guide before accepting writes; a schema update alone does not convert content.
3. Include the extension's TypoScript and configure the forum/user storage PIDs,
   page PIDs, frontend login, FAL storage and permissions.
4. Create the forum data folder and forum records, including parent/child forums
   and explicit read/write/moderation ACLs for the intended frontend groups.
5. Add the dedicated forum content element. v14 uses dedicated CType plugins;
   do not follow the old generic list-plugin/switchableControllerActions setup.
6. Include ``Configuration/Routing/Routing.yaml`` in the site's route configuration
   and configure the target pages. Verify detail links and post anchors.
7. Check anonymous/member/moderator access, topic/post creation, attachments,
   emoji storage, mail capture/delivery and token-bearing POST forms.

Local development
-----------------

Use ``Build/setup-ddev.sh`` from the repository root and ``ddev/README.md`` for
the repeatable synthetic fixture. It is not a production installer or migrator.
Old screenshots retained under Images are historical and do not describe v14 UI.
