# Laravel Cloud deployment

Production is deployed at **https://1262bryn.com** in `us-east-1`, using the existing Cloud environment's PHP 8.5 and Node 24 selections, managed MySQL 8.4, one 512 MB App instance and one database queue worker. The scheduler is enabled; daily database snapshots retain seven days. Initial data is loaded (133 invoices, 282 transactions), and recurring billing/invoice email remain disabled pending cutover. The following instructions also serve as a rebuild guide.

Create a production application for GitHub repository `blueshoonroy/condo-manager`, branch `main`, on [Laravel Cloud](https://cloud.laravel.com). Select PHP 8.4 and Node 22.12+ (or Node 24), using the standard PHP runtime. Start with one small App instance and a dedicated managed MySQL database in the same region, preferably the nearest available US region to Chicago. Attach the database as the default. Review Cloud's monthly resource estimate before creating resources.

Enable push-to-deploy from `main`. Disable the previous Forge site's auto-deploy if enabled.

## Build and deploy commands

Build command:

```bash
bash deploy/cloud-build.sh
```

Deploy command:

```bash
bash deploy/cloud-deploy.sh
```

The build installs locked dependencies, builds assets and caches configuration/routes/views. Deployment runs migrations only. Cloud restarts workers automatically; filesystem changes made by deploy commands do not persist. Do not use `deploy/forge.sh` on Cloud. See [Cloud environments](https://laravel.com/cloud/docs/environments).

## Environment variables

Keep Cloud's generated `APP_KEY` stable and backed up. Let the attached database inject its connection values; do not copy local `DB_*`, `DATABASE_URL` or SQLite settings into Cloud. Add:

```dotenv
APP_NAME="1262 Bryn Mawr Association"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR-ENVIRONMENT.laravel.cloud
LOG_CHANNEL=stderr
LOG_LEVEL=warning
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=resend
RESEND_API_KEY=YOUR_PRIVATE_RESEND_KEY
MAIL_FROM_ADDRESS=portal@mail.1262bryn.com
MAIL_FROM_NAME="1262 Bryn Mawr Association"
BILLING_ENABLED=false
BILLING_START_MONTH=
BILLING_ISSUE_DAY=1
BILLING_DUE_DAYS=30
INVOICE_EMAILS_ENABLED=false
PAYMENT_INSTRUCTIONS="Contact Roy for payment instructions."
```

Use the assigned Cloud URL initially. Set sensitive values in Cloud's environment settings or linked secrets, never in Git. Verify `mail.1262bryn.com` with Resend's provided DNS records before testing login. Redeploy after changing variables.

Billing dates are placeholders. Confirm the start month (`YYYY-MM`), issue day (1–28), due days and payment instructions before enabling billing. Disable FreshBooks recurrence and reconcile the final export first. Login email works independently of the invoice-email switch.

## Scheduler and queue

Enable Cloud's Laravel scheduler on the App cluster. Add one background queue worker:

```bash
php artisan queue:work database --sleep=3 --tries=3 --timeout=60
```

Initially keep production compute awake (disable scale to zero) so database-backed email jobs process promptly. A separate worker cluster and Redis are unnecessary for this five-unit portal. Review the monthly estimate in Cloud. See [workers](https://laravel.com/cloud/docs/workers) and [scheduled tasks](https://laravel.com/cloud/docs/scheduled-tasks).

## Private initialization

Roster and CSVs are excluded from Git. New CSV uploads are stored as base64 bytes in the database (base64 is not encryption), remain administrator-only, and are included in database backups. No object-storage bucket is needed for this release. Older local import batches remain readable on DDEV but are not transferred to Cloud.

For first-time initialization, copy the local roster to your clipboard with PowerShell:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes((Resolve-Path 'data/residents.json'))) | Set-Clipboard
```

Paste into a temporary Cloud variable named `ASSOCIATION_INITIAL_ROSTER_BASE64`, then deploy. In Cloud's Commands panel run:

```bash
php artisan association:initialize --from-env
```

This refuses to run against an initialized association and sends no email. Remove the temporary variable and redeploy immediately afterward; clear your clipboard. Do not put roster contents in public issues, source files or deploy commands.

Sign in as Roy and use Administration to preview/apply the FreshBooks and BMO CSVs. Expected initial totals: 133 invoices and 282 transactions. Record the verified end-of-day bank balance and date. Never initialize or import on every deployment.

## Domain and launch verification

Add `1262bryn.com` in Cloud's Domains settings and apply the exact DNS records provided. Cloud provisions HTTPS after verification. Change `APP_URL` to `https://1262bryn.com` and redeploy.

Verify `/up`, Roy's login email, admin access, household isolation, import totals, queue processing and scheduler operation. Configure database backups and verify a restore; backups contain uploaded sources as well as accounting records. Monitor application errors and failed jobs. Keep billing disabled until the cutover settings and opening bank checkpoint are confirmed.

## CLI access

The development dependency `laravel/cloud-cli` is installed. On a machine with PHP 8.3+ and the sockets extension, run `php vendor/bin/cloud auth -n` for browser authorization. Alternatively configure `LARAVEL_CLOUD_TOKEN` securely in your shell; do not send it in chat. Discover commands with `php vendor/bin/cloud -h`. DDEV can run `ddev exec vendor/bin/cloud`, but its browser callback is inside the container; use token authentication there unless port forwarding is configured.

On this Windows installation, use `php -d extension=sockets vendor/bin/cloud` to enable the sockets extension for the CLI without modifying the system PHP configuration. Cloud credentials are stored outside the repository.
