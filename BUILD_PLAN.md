# 1262 Bryn Mawr Association portal

Planning baseline: September 19, 2026. Address: 1262 W. Bryn Mawr Ave, Chicago, IL 60660.

## Implementation status

The first local implementation is complete: Laravel portal, passwordless codes, household access, initial imports, recurring dues, assessments, payment allocation/reversal, bank checkpoints, directory/documents, administrative imports and audit history. DDEV runs PHP 8.4 and MySQL 8.0 with Mailpit for local email. Deployment instructions and environment variables are in DEPLOYMENT.md. Data has been initialized locally; production remains to be configured and deployed. Recurring billing and invoice email delivery remain disabled pending final cutover settings.

First-release limits and operational behavior are documented in README.md. Future work includes an ownership-transfer wizard, automated reminder cadence, and richer bank reconciliation; original FreshBooks PDFs are not present in the supplied export.

## Recommended implementation

Build a mobile-friendly Laravel application with Blade templates, Tailwind CSS, and Resend for passwordless email login. Keep the application and administrative screens in one codebase. Deploy a dedicated site on the user's existing DigitalOcean server through Laravel Forge, with HTTPS, a dedicated database and database user, deployment migrations, health checks, backups, and private environment configuration. Inspect the server's existing database engine and supported PHP version before finalizing dependencies; use its existing MySQL or PostgreSQL service where suitable.

Confirmed hosting: Laravel Forge on the user's existing DigitalOcean server. The user owns 1262bryn.com and has selected it for launch. Configure HTTPS for this domain and verify a sending subdomain such as mail.1262bryn.com in Resend. Identify the target Forge server, available capacity, deployment access, and DNS provider before deployment. Configure the application's scheduler and queue worker, isolate its files and database from other hosted applications, and establish off-server backups. No hosting changes or email messages have been made as part of planning.

## First release

1. Passwordless login: existing residents enter their email and receive a single-use code that expires after 10 minutes. Store a hash of the code, limit requests and attempts, invalidate consumed codes atomically, rotate the session on login, and use secure HTTP-only cookies. Give the same public response for known and unknown emails. No public registration. Administrators can deactivate access and revoke sessions.
2. Dashboard: unit dues, outstanding invoice total, recent invoices, association bank balance with an explicit as-of date, last import time, and document shortcut. Use clear empty states when a verified balance is unavailable.
3. My invoices: paid and outstanding filters, invoice number, issue/due/paid dates, line items, special assessments, totals, and printable details. The export contains invoice data, not original FreshBooks PDF files. Import FreshBooks history, then replace FreshBooks with portal-generated monthly invoices and administrator-created special assessments. Support payment recording and partial payments. Online payment processing remains outside the first release.
4. Association finances: posted bank transactions, search, date filters, credits/debits, monthly inflows/outflows, and balance history where source coverage supports it. Display the bank balance as cash on hand; do not imply that all cash is formally designated reserves. An administrator can separately record a reserve allocation if needed.
5. Resident directory: names, unit numbers, and supplied email addresses; optional phone numbers can be added later. Directory access requires login.
6. Documents: authenticated portal link to the supplied Google Drive folder. Google controls folder permissions independently; portal login does not grant Drive access. Folder contents and sharing could not be verified during planning.
7. Administration: CSV import preview and confirmation, validation errors, client-to-unit mapping, balance checkpoints, resident access, dues changes with effective dates, and import/change history.

## Residents and access

| Unit | Residents | Monthly dues |
| --- | --- | ---: |
| 1 | Harrison Long / Erica Lysaught | $310 |
| 2 | Adam Ferguson | $430 |
| 3 | Roy Palondikar / Kelly Pompili | $430 |
| 4 | Aaron Shaw / Hannah Cuts | $430 |
| 5 | Kit Cudahy | $430 |

Seed the eight supplied email addresses through private initialization data. Expected monthly dues across five units are $2,030. Preserve supplied names and email spellings.

Confirmed: Roy is the sole administrator. Each person has a separate login, and co-residents share authorized invoice access for their unit and ownership period. Residents see the directory and association bank activity; Roy sees all invoices and import records. Bank descriptions can identify payers, so association-wide transaction visibility also exposes some payment information. Mask account identifiers where present.

Enforce permissions on every server request, including detail views, searches, totals, and downloads. Retain ownership/membership dates so a future owner does not automatically inherit a former owner's personal invoices. Confirmed: John Pappas previously owned Unit 1. Map his five historical invoices to his former Unit 1 household, with no active login and administrator-only access to those invoices. Harrison and Erica's household remains separate. The exact ownership transition date is unknown; preserve explicit client mappings without inventing dates.

## Source data findings

- Confirmed move-in date for Harrison and Erica: June 3, 2025 (user corrected the initially supplied year). The export bills John Pappas for January-May 2025 and first bills Harrison on June 23, 2025 for June dues. Preserve the explicit historical client mappings; the move-in date does not independently establish the legal ownership transfer date.

- FreshBooks export: 133 rows and 133 unique invoice numbers, issued January 2, 2025 through September 2, 2026. Statuses: 130 paid and three overdue. The overdue rows total $1,089.44 according to this snapshot. Includes special assessments as well as monthly dues.
- Bank export: 282 rows, January 2, 2025 through September 18, 2026; 282 unique FI transaction references. Signed amounts, descriptions, currency, transaction type, and credit/debit fields are present. Net movement is +$240.51 over the supplied rows; this is not the account balance.
- Historical FreshBooks statuses and bank activity reflect their imported snapshots. Show when each source was last imported; portal invoice balances update when Roy records payments.
- A verified posted balance at an exact end-of-day date is needed. Compute subsequent balances from that checkpoint and complete posted transactions after it. Flag gaps or discrepancies instead of presenting an unverified balance. Do not substitute an available balance containing pending transactions.

## Import behavior

FreshBooks: preserve leading zeros in invoice numbers, parse dates as dates, store amounts in integer cents, and validate USD and required columns. Group line items by invoice number even though this export has one row per invoice. Explicitly map client names to historical household memberships. Re-imports update existing invoices and payment statuses without creating duplicates; preview changed totals and mappings. Missing invoices in a later export are not automatically deleted. Reject inconsistent rows within an invoice. The current export lacks partial-payment amounts, so flag unfamiliar payment statuses rather than inventing an outstanding balance.

Bank: identify transactions by account plus FI transaction reference. Validate signed amount against credit/debit direction. Ignore exact repeat records, flag changed records sharing a reference, and queue missing-reference rows for review without collapsing legitimate equal-value transactions. Preserve original descriptions and source provenance. Do not automatically mark an invoice paid just because a deposit has a matching amount or name.

Both: validate before applying, show counts and totals for administrator review, commit each import atomically, record checksum and actor, and retain an audit trail. Store source files privately with a retention policy. Provide a backup/export procedure before major corrections.

## Portal invoicing and FreshBooks transition

- Confirm the first portal billing month, issue day, due-date rule, and existing payment instructions before enabling recurring billing. Do not infer a new billing policy from historical export dates. Stop FreshBooks recurring invoices at the agreed cutover and check the final export for overlapping billing periods.
- Generate one monthly dues invoice per unit using its effective dues rate, in America/Chicago time. Enforce a unique unit/period/dues constraint and use transactional generation so retries and concurrent jobs cannot duplicate charges. Run scheduling through a configured hosting scheduler and recover missed periods safely.
- Use a distinct portal invoice-number sequence; retain historical FreshBooks numbers and source identifiers. Co-residents share one household invoice. Add special assessments explicitly with an administrator preview of each unit's charge.
- Snapshot issued line items and recipient membership; later dues or ownership changes must not rewrite issued invoices. Record voids, credits, and corrections with an audit trail rather than deleting issued history.
- Record payments and allocations to invoices, supporting partial payments, one payment covering several invoices, and unapplied credit. Roy can confirm suggested matches to imported bank deposits. Prevent allocating more than the payment amount or counting the same bank receipt twice. Reversals restore outstanding balances through audited entries.
- Imported paid invoices retain their historical settlement without creating new cash receipts; migration must not count bank deposits a second time. Review later FreshBooks updates against portal payment records instead of overwriting reconciled history.
- Prepare invoice notification templates and a durable delivery queue with retry tracking. Launch delivery and reminders only after the schedule and recipients are configured; avoid duplicate notifications on repeated scheduler runs. Do not add late fees automatically.
- Verify generation retries, cutover overlap detection, dues changes, partial payments, credits, reversals, household access, and notification retry behavior before enabling production billing.

## Data model

Association, units, residents, dated unit memberships, effective-dated dues, external client mappings, invoices, invoice line items, billing schedules, payments, payment allocations, credits, notification deliveries, bank accounts, bank transactions, balance checkpoints, import batches, login challenges, sessions, and audit events. Use database uniqueness constraints for imported identifiers and recurring billing periods. Keep invoice ownership distinct from a unit's current occupants.

## Build sequence and completion criteria

1. Foundation: scaffold the application, migrations, private seed data, authentication, roles, and responsive navigation. Verify code expiry, replay protection, attempt limits, and resident/admin authorization.
2. Imports and invoicing: implement both CSV pipelines, client mapping, checkpoint calculations, recurring invoice generation, special assessments, payment allocation, and notification jobs. Verify all source rows are accounted for, repeated uploads and billing runs are harmless, ambiguous records require review, and monetary calculations and payment reversals are exact.
3. Resident experience: dashboard, invoices, bank activity, directory, and Drive link. Verify both residents in a household see authorized invoices and direct requests cannot retrieve another household's invoices. Check mobile layout, keyboard use, empty states, and freshness labels.
4. Administration and launch: complete upload previews, audit history, Forge deployment configuration, backups and restoration checks. Deploy once the existing server and DNS access are available. Verify HTTPS, production session settings, migrations, scheduler and queue operation, authenticated access, and email delivery to a designated test recipient before inviting residents.

Implementation can proceed locally with placeholder balance and email captured locally while hosting/domain details are resolved. Keep CSVs, resident seed files, secrets, and database exports out of Git and public build assets. Configure the supplied Resend credential only through private environment configuration; never embed it in code, documentation, browser bundles, or logs.

## Outstanding decisions

- First portal billing month, monthly issue day, due-date rule, and payment instructions; coordinate disabling FreshBooks recurring invoices.
- Verified bank balance and exact as-of date; whether this account's full balance is intended as the reserve figure.
- Target Forge server and deployment access, plus DNS access for 1262bryn.com and its Resend sending subdomain.
- John Pappas's Unit 1 ownership transition date, if available; explicit historical client mapping allows import work to proceed without it.

## References

- Laravel mail and Resend support: https://laravel.com/framework/docs/13.x/mail
- Resend sending-domain verification: https://resend.com/docs/dashboard/domains/introduction
- Association documents: https://drive.google.com/drive/folders/1XMLYnDQRZmxbejG50KfWQVxFJvpuuNjc
