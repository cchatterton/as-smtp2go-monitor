# AS SMTP2GO Monitor architecture

The monitor integrates with SMTP2GO 1.17.0 without modifying it. In that version,
`SMTP2GO\App\WordpressPlugin::initMailer()` attaches to `wp_mail` and installs
`SMTP2GOMailer`. The mailer makes the API request in `mailSend()` and throws a
PHPMailer exception for a reported SMTP2GO error. WordPress catches the exception
and emits `wp_mail_failed`. The monitor records the original `wp_mail` arguments,
then queues that email and trips on a failure from an SMTP2GO mailer.

While tripped or recovering, `pre_wp_mail` persists each new message and returns
`true` to the caller. Here `true` means accepted for deferred delivery. Recovery
calls `wp_mail()` with a request-local bypass, so the official SMTP2GO delivery
path remains in use. The official Test Ajax action checks its own nonce and
`manage_options`, then defines `SMTP2GO_TEST_MAIL`; the monitor allows that exact
administrator test request through while tripped.

The queue uses one WordPress-prefixed table because it must atomically claim
ordered messages across cron workers. Attachments and embeds are stored as
base64 in the row so temporary source files can disappear and no web-accessible
queue directory is needed. A MySQL advisory lock serialises recovery batches;
each row also has an atomic queued-to-sending claim. A sending row left by an
interrupted worker requires manual review because its delivery outcome is
uncertain. The circuit state is held in a WordPress option and changed using a
conditional SQL update.

WordPress Cron schedules one batch of at most 10 messages per minute. Cron
depends on site traffic or a server scheduler. The queue remains intact if cron
does not run. The administrator must initiate recovery; the plugin never does so
solely because SMTP2GO becomes reachable.

Compatibility assumptions: WordPress 7.0, PHP 7.4 or newer, MySQL advisory locks,
the official SMTP2GO plugin 1.17.0, and mail sent through `wp_mail()`.
