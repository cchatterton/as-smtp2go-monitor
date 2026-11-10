# AS SMTP2GO Monitor

An AlphaSys WordPress plugin that pauses SMTP2GO sending after a confirmed
`wp_mail()` failure, queues subsequent email, alerts the team, and releases the
queue only after an administrator starts recovery.

## Install

Install and enable the official SMTP2GO plugin first. Upload
`as-smtp2go-monitor.zip` through Plugins > Add New > Upload Plugin, then activate
it. Open Tools > AS SMTP2GO Monitor and save a Microsoft Teams Workflow webhook
URL. The plugin needs WordPress 7.0, PHP 7.4 or newer, and a MySQL-compatible
database with advisory lock support.

## Operation

1. Armed: WordPress mail uses the official SMTP2GO plugin. An SMTP2GO mailer
   failure saves the failed message, changes state to Tripped, and sends one
   webhook alert.
2. Tripped: New `wp_mail()` calls are saved. A `true` result means accepted for
   deferred delivery, not delivered. The SMTP2GO Test action remains available.
3. After fixing SMTP2GO, send a successful test using Settings > SMTP2GO > Test.
   At Tools > AS SMTP2GO Monitor, acknowledge the queue count and start recovery.
4. Recovery sends up to 10 messages per minute in order. A failure trips the
   circuit again. When the queue empties, the state returns to Armed.

If a worker stops after claiming an email, its outcome may be uncertain. Inspect
SMTP2GO before using **Return to queue**; a resend could duplicate it. Queued
emails may also be deleted individually by an administrator.

## Security and limits

Queue content is stored in the WordPress database, including base64 copies of
attachments. Protect and back up the database accordingly. The webhook receives
site details and an error summary, never email contents or the SMTP2GO API key.
The admin page requires `manage_options`; writes require nonces. The webhook
must be HTTPS and is sent through `wp_safe_remote_post()`.

WordPress Cron needs site traffic or a real server cron to run promptly. A `true`
return from SMTP2GO means the API processed the request, not that a recipient
received it. A network timeout may leave delivery uncertain, so the plugin
cannot guarantee exactly-once delivery. It monitors only WordPress `wp_mail()`
calls routed through the official SMTP2GO plugin.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the verified integration point.

## Updates

The plugin uses the AS Update Controller client contract. The controller is
optional for mail operation and provides managed GitHub updates when installed.
