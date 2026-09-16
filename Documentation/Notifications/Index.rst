================================
Subscription notification emails
================================

TYPO3 Forum sends an HTML notification when a new topic is created in a
subscribed forum or when a new reply is created in a subscribed topic. The
recipient selection, author exclusion, access checks and subscription records
are independent of the content options described here.

Global defaults and forum overrides
===================================

The five checkboxes are available in :guilabel:`Admin Tools > Settings >
Extension Configuration > typo3_forum`, in the ``Notifications / Content``
category. They are the installation-wide fallback values for both
subscription notification types.

================================================  =======  ================================
Key                                               Default  Effect
================================================  =======  ================================
``notifications.includeForumNameInSubject``      off      Prefix subject with content forum
``notifications.includePostText``                 off      Include complete triggering post
``notifications.includeForumLink``                on       Link to the content forum
``notifications.includeTopicLink``                on       Topic or reply deep link
``notifications.includeUnsubscribeLink``          on       Complete unsubscribe explanation
================================================  =======  ================================

The defaults apply even before an administrator first saves the extension
configuration. After changing extension configuration, flush TYPO3's system
configuration cache if the installation does not already request this.

Every forum also has an :guilabel:`Email notifications` section with an
independent ``Inherit``, ``Enabled`` or ``Disabled`` choice for each option.
The stored values are ``0`` (inherit), ``1`` (enabled) and ``2`` (disabled).
An additive database schema update creates the five fields with ``0`` as the
default, so existing forums inherit without a data migration wizard.

Effective values are resolved from the forum that contains the new content.
For each option separately, the nearest explicit value wins: content forum,
then each parent, then the global default. ``Inherit`` at a root forum means
the global default. The hierarchy is traversed once for all five values.
Invalid stored values or a parent cycle produce a diagnostic error instead of
silently enabling content or accepting a guessed default.

For example, consider these independent overrides:

* global: forum name off, post text off, forum/topic/unsubscribe links on;
* root: forum name and post text enabled, topic link disabled;
* parent: forum link disabled;
* child: post text disabled.

For content in the child, the result is forum name on (root), post text off
(child), forum link off (parent), topic link off (root), and unsubscribe on
(global).

The full-text option deliberately defaults to off. If enabled, the complete
post leaves the protected website context and is stored in recipient
mailboxes. Existing read-access checks still apply, including subscribers of a
parent forum; the setting itself grants no access. The mail renderer uses a
safe readable text representation: user HTML is escaped, line breaks remain
readable, and BBCode stays text instead of invoking frontend parsers that can
load quoted posts or remote resources. Attachments are never added to the
email.

Content forum and subscription target
=====================================

The configuration, subject and generated forum/topic links always use the
forum and topic that contain the new content. The subscribed forum never
supplies configuration overrides. An unsubscribe link instead identifies the
single subscription that caused that particular message. For example, a user
subscribed only to parent forum ``1`` may receive a message about topic
``14548`` in child forum ``7``. The message links to forum ``7`` and topic
``14548`` and resolves settings from forum ``7`` upwards, while its
unsubscribe action addresses forum subscription ``1``.
A topic notification unsubscribes that topic subscription. The wording does
not claim that one link removes other parallel subscriptions.

Hiding the unsubscribe block only changes the email. It does not modify or
end a subscription, and the normal forum action remains available. Likewise,
the forum/topic switches affect automatically generated navigation links;
links written by an author remain visible when full post text is enabled.

Language and message overrides
==============================

English and German ship complete fragments. Other installed languages fall
back through TYPO3's normal localization chain. The body labels
``Mail_Subscribe_NewPost_Body`` and ``Mail_Subscribe_NewTopic_Body`` now use
these structural markers:

* ``###GREETING###``
* ``###EVENT_DESCRIPTION###``
* ``###POST_TEXT_BLOCK###``
* ``###FORUM_LINK_BLOCK###``
* ``###TOPIC_LINK_BLOCK###``
* ``###UNSUBSCRIBE_BLOCK###``
* ``###SIGNATURE###``

The established value markers (for example ``###RECIPIENT###``,
``###FORUM_NAME###``, ``###FORUM_LINK###``, ``###TOPIC_LINK###``,
``###POST_LINK###`` and ``###UNSUBSCRIBE_LINK###``) remain supported. For a
legacy body override, disabling a navigation link substitutes its plain text
name, and disabling unsubscribe removes the complete line containing its
marker. Enabled post text is appended when the old override has no post-text
marker. Migrate custom overrides to the structural block markers to control
spacing and wording precisely. Arbitrary hard-coded URLs or HTML in an
operator override cannot be identified semantically and are not removed by
these switches.

Marker replacement is non-recursive. Marker-looking strings in user content,
usernames or forum names remain literal content and cannot inject another
template instruction.

Examples
========

With the defaults, a new-topic message contains the greeting and event, links
to the forum and topic, the unsubscribe explanation and the team signature;
it contains neither a forum prefix in the subject nor the post text.

With all options enabled, synthetic output can look like this::

   Subject: [General discussion] New topic in one of your subscribed forums!

   Hello Alex,
   Taylor created the new topic "Welcome" in forum "General discussion".
   Post text:
   This is the complete initial post.
   Open forum: General discussion
   Open topic: Welcome
   This link unsubscribes only the forum subscription that triggered this message: unsubscribe
   Yours sincerely,
   Your Example Forum team

With both navigation-link options and unsubscribe disabled, the event still
names the forum and topic as plain text. No empty anchors, orphan unsubscribe
sentence, or alternative hidden full-text part is generated.

Verification
============

Automated tests cover every combination of the five options for both events,
escaping, marker safety, initial/triggering post selection, parent-forum
subscriptions, access checks, MIME delivery through the injected TYPO3 mailer,
and release-package contents. A real backend-checkbox and Mailpit exercise is
still part of installation acceptance when Docker/DDEV is available; it must
not send to real recipients.
