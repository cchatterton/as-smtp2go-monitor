=== AS SMTP2GO Monitor ===
Tags: smtp2go, email, queue, monitoring
Requires at least: 7.0
Stable tag: 0.1.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pause SMTP2GO mail after a failure, queue later messages, and recover manually.

== Description ==

AS SMTP2GO Monitor watches mail sent through the official SMTP2GO plugin. After a
failure, it queues messages and alerts AlphaSys through a configured Microsoft
Teams Workflow webhook. An administrator tests SMTP2GO and starts gradual recovery.

== Installation ==

1. Install and enable the official SMTP2GO plugin.
2. Upload and activate AS SMTP2GO Monitor.
3. In Tools > AS SMTP2GO Monitor, save an HTTPS Teams Workflow webhook URL.

== External services ==

The plugin sends email through SMTP2GO using the official SMTP2GO plugin. See
https://www.smtp2go.com/terms/ and https://www.smtp2go.com/privacy/.
On a circuit trip it posts site name, site URL, failure reason, queue count,
time, and an admin link to the configured Microsoft Teams Workflow webhook.
No email body, attachment, or API key is posted. See
https://www.microsoft.com/servicesagreement/ and
https://privacy.microsoft.com/privacystatement.

== Changelog ==

= 0.1.1 =
* Initialise a separate queue and state on each multisite subsite before handling its mail.

= 0.1.0 =
* Initial release.
