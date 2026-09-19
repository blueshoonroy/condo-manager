# Forge deployment

Use a dedicated site for `1262bryn.com` with the web root set to `/public`. The application uses Laravel 13; select PHP 8.4 or newer and Node 22.12+ (or a supported newer LTS). Use a dedicated MySQL/PostgreSQL database and user. Do not reuse another site's database.

## Environment

Start with `.env.example`. Production settings:

```dotenv
APP_NAME="1262 Bryn Mawr Association"
APP_ENV=production
APP_KEY=base64:GENERATE_ON_SERVER
APP_DEBUG=false
APP_URL=https://1262bryn.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=condo_manager
DB_USERNAME=condo_manager
DB_PASSWORD=SET_IN_FORGE

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=resend
RESEND_API_KEY=SET_IN_FORGE
MAIL_FROM_ADDRESS=portal@mail.1262bryn.com
MAIL_FROM_NAME="1262 Bryn Mawr Association"

BILLING_ENABLED=false
BILLING_START_MONTH=
BILLING_ISSUE_DAY=1
BILLING_DUE_DAYS=30
INVOICE_EMAILS_ENABLED=false
PAYMENT_INSTRUCTIONS="Contact Roy for payment instructions."
```

Generate `APP_KEY` once with `php artisan key:generate --force`. Keep it stable across deployments and store a secure backup. Verify `mail.1262bryn.com` in Resend and add the DNS records Resend provides. Point the site's DNS to your server and install HTTPS in Forge.

Billing defaults are placeholders, not an adopted association policy. Set the start month (`YYYY-MM`), issue day (1–28), due days, and payment instructions before enabling billing. Turn off FreshBooks recurrence and reconcile the final export first. Invoice emails have a separate switch. Login email works independently.

## Deployment script

See `deploy/forge.sh`. Adapt the path and PHP/Composer binaries to the Forge site's settings. Install locked dependencies, build assets, apply migrations, cache configuration/routes/views, and restart queue workers. Do not seed or import on every deployment.

Keep Forge's normal checkout/pull step (or its release/activation wrappers for zero-downtime sites), then run `bash deploy/forge.sh` from the checked-out application directory. This script deliberately does not switch branches or pull code itself. Deploy `main` only once the environment and database are configured.

## Private initial data

Resident roster and source CSVs are deliberately excluded from Git. Upload them privately outside `/public`, then run the initialization/import commands documented in README. Never place CSVs in public storage. Back up the database before re-importing production history.

For example, upload the supplied `data/` files into the site's private directory and run the following once, adapting paths:

```bash
php artisan association:initialize /private/path/residents.json
php artisan association:import invoices "/private/path/FreshBooks - Invoices Export - 2025-01-01 - 2026-09-19.csv" --apply
php artisan association:import bank "/private/path/Bank Transactions.csv" --apply
```

Sign in as Roy and record the verified bank balance/date in Administration. Account initialization does not send invitations or generate invoices.

## Background processes and operations

Configure a Forge scheduler to run `php artisan schedule:run` every minute in the site directory. Configure a queue worker running `php artisan queue:work --sleep=3 --tries=3 --timeout=60`. Schedule and queue processes must use the same PHP version and environment as the site.

Back up the database and private source uploads to off-server storage, and verify a restore. Monitor `/up`, failed jobs, storage use, and application errors. Restrict logs and backups to server administrators. Before enabling billing, test login with Roy, import reconciliation, household isolation, invoice generation, and payment recording.
