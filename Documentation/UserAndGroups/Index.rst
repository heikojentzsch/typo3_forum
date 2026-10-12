.. _user:

Users and groups
================

v14 extends standard ``fe_users`` and ``fe_groups`` records, including type 0.
Create frontend users/groups normally in the configured storage folders. No
special forum record subtype must be chosen before the first save. Historical
screenshots in Images describe the previous backend and are not v14 instructions.

Configure frontend login and group membership, then set forum ACL records for
read, topic/post creation and moderation. Test those rights with both authorized
and unauthorized accounts; hiding a menu entry is not an access-control boundary.

The group user-moderation flag grants user moderation and permission to create
tags. Forum/post moderation uses the relevant forum ACL. The user-report list,
comments and status changes require user-moderation rights server-side.

Existing users, groups and content must follow the migration guide; do not create
duplicate records to work around legacy subtype values. Signatures use sanitized
HTML with preserved legacy source during migration, as described in the editor guide.
