# Whisper Project Tour

## Entry Points

- `routes/web.php` defines the webhook and admin console routes.
- `app/Http/Controllers/DentolizeWebhookController.php` validates incoming Dentolize events and writes to `inboxes`.
- `app/Jobs/ProcessInboxEvent.php` routes inbox rows to the correct handler.
- `app/Console/Commands/RunReconciliation.php` runs the local reconciliation worker.

## Sync Core

- `app/Sync/Handlers/PatientHandler.php` maps Dentolize patients to Qoyod customers.
- `app/Sync/Handlers/InvoiceHandler.php` maps Dentolize invoices to draft Qoyod invoices with one summary line.
- `app/Sync/Handlers/PaymentHandler.php` maps Dentolize payments to Qoyod invoice payments and parks payments until the invoice exists.

All handlers are idempotent. They first check `sync_maps`, then fake Qoyod by reference, before creating anything.

## External Adapters

- `app/Sync/Clients/FakeDentolizeClient.php` supplies local source data.
- `app/Sync/Clients/FakeQoyodClient.php` stores target records in `fake_qoyod_records`.
- `app/Providers/AppServiceProvider.php` binds the interfaces to fake clients for now.

## Audit Console

- `app/Http/Controllers/Admin/*` handles login, summary, item list/detail, retry, and reconciliation.
- `resources/views/admin/*` contains the simple local Blade console.
- Admin auth is custom and server-side, backed by `admin_users`.

## Database

The main schema is in `database/migrations/2026_07_08_000001_create_whisper_tables.php`.

Important tables:

- `inboxes`: raw webhook events.
- `sync_maps`: source-to-target ledger and current status.
- `audit_logs`: fake Qoyod request/response evidence.
- `dentolize_mirrors`: local source copy for reconciliation and retry.
- `fake_qoyod_records`: local target-system stand-in.

## Local Demo Flow

1. Run `php artisan migrate --seed`.
2. Run `php artisan whisper:reconcile`.
3. Log in at `/admin`.
4. Inspect the synced patient, invoice, and payment rows.
5. Open an item detail to see the audit body.

## What Comes Next

The local skeleton is ready for the next implementation slices:

- Live Qoyod client expansion beyond the contact-only smoke test.
- Expense and expense-payment handlers.
- Dentolize payload capture and live reader/backfill source.
- ZATCA-safe invoice status behavior.
- Daily report and alert delivery.
