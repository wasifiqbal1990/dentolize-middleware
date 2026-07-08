# Whisper Local Hybrid Design

## Goal

Build a working local Whisper service for Dentolize to Qoyod finance sync, using mocked external systems by default and explicit Phase 0 gates before any live account access or financial writes.

## Approved Approach

Use the hybrid path:

- Local runnable project now.
- Mock Dentolize and Qoyod adapters for development and tests.
- Environment switches for live adapters later.
- No live browser/account inspection during this build unless wasif explicitly asks for Phase 0 discovery.

This gives wasif a working repo immediately while preserving the compliance and financial blockers from the master specification.

## Architecture

Whisper will be a Laravel/PHP application with SQLite for local development, Laravel queues for background jobs, and Blade views for the internal audit console. The production shape still maps to the master spec: webhook receiver, durable inbox, idempotent handlers, Qoyod client, Dentolize client, reconciliation jobs, audit log, sync map, and admin console.

Local development uses fake clients:

- `FakeDentolizeClient` returns deterministic sample patients, invoices, payments, expenses, and treasuries.
- `FakeQoyodClient` stores created target records locally and simulates lookup-by-reference, validation errors, rate limits, and total mismatches in tests.
- Live clients are configured but guarded by env flags and incomplete until Phase 0 provides real payloads, credentials, reference IDs, and ZATCA decisions.

## Components

- Webhook receiver: accepts Dentolize events, validates verify token, writes raw payloads to `inbox`, dispatches jobs, and never calls Qoyod inline.
- Sync handlers: patient, invoice, and payment handlers in the first slice; expense, expense payment, and treasury handlers next.
- Idempotency: deterministic `DENTO-*` references, `sync_maps` uniqueness, and Qoyod lookup as a second guard.
- Audit storage: request and response bodies for target writes, with secrets redacted.
- Reconciliation: command and service that pulls mock Dentolize changes, upserts the mirror, and heals missing records through the same handlers.
- Admin console: authenticated local UI with summary cards, item filters, detail/audit timeline, retry, and run reconciliation actions.
- Runbook: setup, local execution, seeded demo data, webhook testing, replay, reconciliation, and Phase 0 live-account checklist.

## Data Flow

Webhook flow:

1. Dentolize posts to `/webhooks/dentolize`.
2. Receiver validates `X-Dentolize-Verify-Token`.
3. Receiver persists the raw event in `inbox`.
4. A queued job calls the matching handler.
5. Handler ensures dependencies, transforms payload, writes to fake Qoyod, verifies read-back, and updates `sync_maps` and `audit_logs`.

Reconciliation flow:

1. Scheduler or admin action runs reconciliation.
2. Dentolize client returns changed records.
3. Records are mirrored in `dentolize_mirrors`.
4. Missing or changed records are processed through the same handlers.
5. Items that heal from failure become `fixed`.

## Live Integration Gates

The local project must not go live until these are resolved:

- ZATCA system of record.
- Qoyod sandbox or approved live test credentials.
- Dentolize test access and exact webhook payload schemas.
- Qoyod generic product ID.
- Dentolize branch to Qoyod inventory map.
- Dentolize treasury to Qoyod account map.
- Backfill source choice: UI export, official export/API, or approved GraphQL pull.
- Insurance split and tax edge-case policy.

## Testing

Use test-first implementation for behavioral code. The first build should cover:

- Phone normalization.
- Reference generation.
- Patient, invoice, and payment mapping.
- Webhook token validation.
- Inbox persistence and duplicate dedupe.
- Handler idempotency.
- Payment before invoice dependency handling.
- Reconciliation healing.
- Admin auth and role checks.

## Scope For This Local Build

In scope:

- Runnable Laravel app.
- Local SQLite setup.
- Migrations and models for the master schema.
- Fake clients and handlers.
- Local admin user seeding.
- Admin console and JSON endpoints.
- Reconciliation command.
- Automated tests.
- Runbook and guided project tour notes.

Out of scope for this build:

- Real live writes to Qoyod.
- Dentolize browser scraping or GraphQL capture.
- Historical backfill against real patient data.
- ZATCA submission behavior.
- Final production deployment.

## Self-Review

- No placeholders are required for local behavior; live integration gaps are explicit gates.
- The design follows the master specification while reducing financial risk.
- The first implementation slice is independently runnable and testable.
- The local fake adapters keep development deterministic and reversible.
