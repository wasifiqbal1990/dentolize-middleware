# Whisper Runbook

## Local Start

1. Copy environment values:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
2. Create the SQLite database:
   ```bash
   touch database/database.sqlite
   ```
3. Migrate and seed:
   ```bash
   php artisan migrate --seed
   ```
4. Start the app:
   ```bash
   php artisan serve
   ```
5. Open `http://127.0.0.1:8000/admin`.

Default local admin:

- `admin@example.com`
- `password`

## Run Reconciliation

```bash
php artisan whisper:reconcile
```

This pulls deterministic fake Dentolize patient, invoice, and payment records, mirrors them locally, syncs them into fake Qoyod storage, and updates `sync_maps`.

## Test A Webhook

```bash
curl -X POST http://127.0.0.1:8000/webhooks/dentolize \
  -H 'Content-Type: application/json' \
  -H 'X-Dentolize-Verify-Token: local-secret' \
  -d '{"event_id":"evt-invoice-1","event_type":"New Invoice","data":{"id":"invoice-1","invoiceId":"#21038","patient":{"id":"patient-1","firstName":"Sara","lastName":"Patient","phoneNumber":"051 234 5678"},"subtotal":"249.00","total":"286.35","taxPercent":"15","discount":"0","createdAt":"2026-07-08T10:00:00+03:00"}}'
```

## Replay Failed Items

1. Open `/admin`.
2. Open the failed item detail.
3. Use the JSON endpoint as an operator/admin:
   ```bash
   curl -X POST http://127.0.0.1:8000/admin/items/{id}/retry
   ```

Retry uses the mirrored Dentolize payload and the same idempotent handler as webhooks and reconciliation.

## Live Integration Checklist

Before switching away from fake adapters:

- Confirm whether Dentolize or Qoyod is the ZATCA system of record.
- Confirm Qoyod invoices can be created without double-reporting to ZATCA.
- Capture exact Dentolize webhook payloads for patient, invoice, payment, expense, expense payment, and treasury events.
- Capture or obtain approved Dentolize read/export access for reconciliation and backfill.
- Fill `QOYOD_API_KEY`, `QOYOD_GENERIC_PRODUCT_ID`, `BRANCH_INVENTORY_MAP`, and `TREASURY_ACCOUNT_MAP`.
- Decide how insurance splits, zero-value invoices, non-15% VAT, voids, and refunds should appear in Qoyod.

## Safe Qoyod API Smoke Test

After adding `QOYOD_API_KEY` to the ignored local `.env`, you can create a contact-only smoke test:

```bash
php artisan whisper:qoyod-test-contact
```

The default name is `Wasif Test - DELETE`. This command creates only a Qoyod customer/contact and records the API response in `audit_logs`. It does not create invoices, payments, bills, products, or ZATCA-related records.

To delete a known test contact by Qoyod ID:

```bash
php artisan whisper:qoyod-delete-test-contact 24
```

If Qoyod does not expose physical deletion for the contact endpoint, the command scrubs the contact fields, marks the contact with `status=Deleted`, disables POS visibility, and records that fallback in `audit_logs`.

## Import Dentolize Customers To Qoyod

After adding both `DENTOLIZE_SESSION_COOKIE` and `QOYOD_API_KEY` to the ignored local `.env`, import a small customer batch with API calls only:

```bash
php artisan whisper:import-dentolize-customers --limit=5
```

This command fetches Dentolize patients through the GraphQL API and creates Qoyod contacts only. It does not use the browser and does not create invoices, payments, bills, products, or ZATCA-related records.

## Verification

```bash
php artisan test
```

The local build is healthy when all tests pass and `/admin` shows synced records after `php artisan whisper:reconcile`.
