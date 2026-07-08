# Whisper

Whisper is a Dentolize to Qoyod finance-sync middleware. This repository currently implements the safe local hybrid version: it runs end to end with fake Dentolize and Qoyod adapters, while live financial integration remains blocked behind the Phase 0 decisions in the design spec.

## What Works Locally

- Dentolize webhook receiver at `POST /webhooks/dentolize`.
- Durable inbox, sync map, audit log, Dentolize mirror, checkpoints, and admin access tables.
- Idempotent patient, invoice, and payment handlers.
- Fake Qoyod client with create and lookup by `DENTO-*` reference.
- Fake Dentolize client for reconciliation.
- Reconciliation command: `php artisan whisper:reconcile`.
- Authenticated audit console at `/admin`.
- Feature and unit tests for schema, utilities, fake clients, webhooks, handlers, reconciliation, and admin console.

## Local Setup

```bash
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Open `http://127.0.0.1:8000/admin`.

Local credentials:

- Email: `admin@example.com`
- Password: `password`

## Try The Sync

Run mocked reconciliation:

```bash
php artisan whisper:reconcile
```

Or post a fake patient webhook:

```bash
curl -X POST http://127.0.0.1:8000/webhooks/dentolize \
  -H 'Content-Type: application/json' \
  -H 'X-Dentolize-Verify-Token: local-secret' \
  -d '{"event_id":"evt-patient-1","event_type":"New Patient","data":{"id":"patient-1","firstName":"Sara","lastName":"Patient","phoneNumber":"051 234 5678"}}'
```

Refresh `/admin` to see sync status and audit data.

## Tests

```bash
php artisan test
```

## Phase 0 Live Gates

Do not enable live Dentolize or Qoyod writes until these are resolved:

- ZATCA system of record.
- Real Qoyod credentials or an approved live-test process.
- Dentolize exact webhook payload schemas.
- Generic product ID.
- Branch to inventory map.
- Treasury to account map.
- Backfill source choice.
- Insurance and tax edge-case policy.

The default `WHISPER_ADAPTER_MODE=fake` is intentional. It protects the live books while the local service is built and tested.
