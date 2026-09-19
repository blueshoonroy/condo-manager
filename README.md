# 1262 Bryn Mawr Association

Private resident portal for five Chicago condominium units. Laravel 13, PHP 8.4+, Blade/Tailwind, MySQL or PostgreSQL, and Resend. Features passwordless email codes, household invoice history, recurring dues, special assessments, payments and unapplied credit, bank CSV imports, balance checkpoints, directory, and a Google Drive document link.

## Local development with DDEV

```bash
ddev start
ddev setup
```

Open https://condo-manager.ddev.site. DDEV configures the local database and routes email to Mailpit. Run `ddev mailpit` or open https://condo-manager.ddev.site:8026 to retrieve sign-in codes. Use your registered resident email on the login screen. Local mail does not go to residents' inboxes.

The supplied private data has already been initialized in this workspace's DDEV database. For a fresh database:

```bash
ddev exec php artisan association:initialize data/residents.json
ddev exec php artisan association:import invoices "data/FreshBooks - Invoices Export - 2025-01-01 - 2026-09-19.csv" --apply
ddev exec php artisan association:import bank "data/Bank Transactions.csv" --apply
```

Omit `--apply` for an import preview. The initialization command runs once; subsequent resident changes are made in Administration. Source files and the resident roster are excluded from Git. A fresh clone needs those files transferred privately. Never upload them under `public/`.

The private roster is an array of households with `unit`, `dues_cents`, `client_name`, optional `move_in`, optional `active`, and `residents` (each with `name`, `email`, and optional `is_admin`). A former owner can have an inactive household with no residents. Initial dues become effective at the start of the initialization month; imported historical invoices retain their exported totals.

## Checks

```bash
ddev exec php artisan test --compact
ddev npm run build
ddev exec php artisan route:list --except-vendor
```

Tests use isolated in-memory SQLite and fake email delivery. The actual local environment uses MySQL 8.0. For interactive frontend development, run `ddev npm run dev`; for the simplest workflow rebuild with `ddev npm run build` after changes.

## Billing and accounting

- Billing and invoice emails start disabled. Confirm the FreshBooks cutover, first billing month, issue day, due-day interval, and payment instructions before enabling them.
- One dues invoice per unit and month, with database uniqueness and transactions protecting retries. The scheduled command catches up configured missing periods. `php artisan association:bill 2026-10` generates a specific eligible month.
- Roy records payments, optionally linking an imported bank credit. Allocations can cover multiple household invoices or remain unapplied credit; the allocation screen applies that credit later. Reversals restore outstanding amounts. Bank deposits never automatically mark invoices paid.
- Historical paid invoices use the exported settlement total; they do not create another bank receipt. Imports refuse to overwrite invoices that have portal allocations, have been voided, or have conflicting household ownership.
- Zero-dollar bank verification rows are preserved. Duplicate bank references with different content require correction before the batch can be applied. The first release supports one BMO account.
- Cash balances require a verified end-of-day checkpoint. Later posted transactions are added to that balance. Upload complete bank periods: a transaction-only CSV cannot prove that omitted days have no activity. This is cash visibility, not a full general ledger or a formal reserve-fund ledger.
- Portal invoice emails have persistent delivery records and Resend idempotency keys. Ambiguous attempts older than 23 hours require manual review in Resend. SMTP development delivery cannot offer the same provider-level guarantee.

## Production

See [DEPLOYMENT.md](DEPLOYMENT.md) for Forge environment variables, private data initialization, scheduler, queue worker, backups, and launch steps. [deploy/forge.sh](deploy/forge.sh) is the deployment build/migration segment to insert into the site's Forge script after its checkout step.

Production needs a verified Resend sender, the bank balance checkpoint, and final billing settings. Set PHP 8.4+ and Node 22.12+. Use `/public` as the web root. Do not run local setup commands or initialization on every deployment.

First release boundaries: manual bank imports and payment confirmation; no online card/ACH checkout, automatic bank feed, automatic refunds, reminder cadence, invoice PDF originals, or ownership-transfer wizard. Invoice details are printable. Historical household mappings remain intact.

Local verification: 27 automated tests cover resident/admin authorization, passwordless login, import previews and conflicts, billing retries and cutover overlap, payments/credits/reversals, balance calculations, and email retry behavior. Browser checks also exercised login via Mailpit, authenticated pages, a 390px mobile viewport, and logout.
