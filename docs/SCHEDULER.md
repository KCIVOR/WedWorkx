# Scheduler (invite expiry and reminders)

The app has no always-on worker. One cPanel cron job runs Laravel's scheduler every minute, and the scheduler runs `invites:process`.

## cPanel cron job

In cPanel go to **Cron Jobs** and add one job, "Once Per Minute" (`* * * * *`):

```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

Replace `/path/to/project` with the folder that holds `artisan` (the Laravel folder, not `public_html`). If your host needs the full PHP path, use for example `/usr/local/bin/php artisan schedule:run`.

## What `invites:process` does

Every minute it:

1. **Expires lapsed invitations.** A supplier in `invited`, `details_submitted` or `payment_pending` whose `invite_expires_at` has passed becomes `expired`, a `status_history` row is written (actor `system`), and the "Invitation expired" email is sent once.
   - Exception (decision D6): a supplier with a payment that was started before the deadline and is still `pending` is skipped until `grace_minutes` (30) after the deadline.
2. **Sends the payment reminder.** About 2 hours before `invite_expires_at` (`CAMPAIGN_REMINDER_HOURS`, default 2), unpaid suppliers get the "Payment reminder" email once.

Slots are never freed or re-assigned and waitlisted suppliers are never promoted (decision D9).

Emails are protected by `email_logs`, so running the command twice, or two runs overlapping, never sends the same email twice.

## Checking it works

```
php artisan invites:process
php artisan schedule:list
```

`invites:process` prints how many invitations it expired and how many reminders it sent. `schedule:list` should show it as `* * * * *`.
