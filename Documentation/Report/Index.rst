Reports and moderation
======================

Logged-in users can report users or posts; reporting a post requires read access.
Post reports use the affected forum's moderation ACL. User reports require the
user-moderation group flag. Server-side authorization also protects direct requests.

Create workflow-status records in the forum storage folder. Configure an initial
status, a final status and the allowed follow-up relationship between them. These
are statuses, not separate workflows. Additional allowed transitions can be added.

Add the dedicated moderation/reports content element and configure its storage
and target pages. The old generic plugin dropdown with switchable actions is not
the v14 installation path. Existing content elements are handled by migration.

Moderators can inspect reports, add sanitized rich-text comments and select an
allowed follow-up status. Status changes and comments submit POST forms with
TYPO3 request tokens. Custom templates must preserve these fields. Old screenshots
under Images are retained only as historical illustrations.
