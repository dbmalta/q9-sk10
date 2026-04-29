# Email

The Communications module can send emails to members or subsets of your membership.

## Composing an email

1. Go to **Communications → Email → Compose**
2. Choose recipients — all members, a section, or a custom selection
3. Enter a subject and body
4. Click **Send** or **Schedule** for later delivery

## Email queue

Emails are sent via a background queue processed by the cron job. Go to **Communications → Email → Queue** to see pending, sent, and failed messages.

## Requirements

Outgoing email requires SMTP to be configured in **Admin → Settings → Email**. If no SMTP settings are configured, the system will attempt to use PHP's `mail()` function, which may not work on all hosting environments.

!!! warning
    Always test email delivery with a single test message before sending to your full membership.
