# Whisper Local Hybrid Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a local working Whisper service that syncs mocked Dentolize finance records into mocked Qoyod records through the same architecture intended for live integration.

**Architecture:** Laravel/PHP app with SQLite locally, queue-backed webhook processing, idempotent sync handlers, mock external clients, reconciliation services, and an authenticated audit console. Live credentials and browser-based discovery are guarded behind Phase 0 gates.

**Tech Stack:** PHP 8.4, Laravel, SQLite, Laravel queues, Blade, PHPUnit/Pest-compatible Laravel tests.

## Global Constraints

- Money is represented as strings/decimals, never floats.
- Secrets come from env/config and are never logged.
- Every Qoyod-facing write uses a deterministic `DENTO-*` reference.
- Webhook receiver writes to inbox and returns quickly; Qoyod work happens in jobs.
- Local mode uses fake clients by default.
- Live mode is blocked until ZATCA, reference-data IDs, credentials, and payload schemas are resolved.

---

### Task 1: Scaffold Laravel Project

**Files:**
- Create: Laravel project files in repository root.
- Modify: `.env.example`
- Create: `docs/RUNBOOK.md`

**Interfaces:**
- Produces: runnable app via `php artisan serve`; test suite via `php artisan test`.

- [ ] Create a Laravel project in the empty repo using Composer.
- [ ] Configure SQLite as the default local database.
- [ ] Add env keys for Qoyod, Dentolize, reference maps, and adapter mode.
- [ ] Add a runbook skeleton with local setup and Phase 0 gates.
- [ ] Run `php artisan test` and verify the baseline passes.

### Task 2: Database Schema And Models

**Files:**
- Create: migrations for `inboxes`, `sync_maps`, `audit_logs`, `dentolize_mirrors`, `sync_checkpoints`, `admin_users`, `admin_access_logs`, and local fake Qoyod storage.
- Create: Eloquent models for each table.

**Interfaces:**
- Produces: `Inbox`, `SyncMap`, `AuditLog`, `DentolizeMirror`, `SyncCheckpoint`, `AdminUser`, `AdminAccessLog`.

- [ ] Write failing migration/model tests for required fields and uniqueness.
- [ ] Run tests and verify they fail because tables do not exist.
- [ ] Add migrations and focused models.
- [ ] Run tests and verify they pass.

### Task 3: Support Utilities

**Files:**
- Create: `app/Support/ReferenceBuilder.php`
- Create: `app/Support/PhoneNormalizer.php`
- Create: `app/Support/Money.php`
- Test: support utility tests.

**Interfaces:**
- Produces: `ReferenceBuilder::for(string $entityType, string $dentolizeId): string`
- Produces: `PhoneNormalizer::toSaudiE164(?string $phone): string`
- Produces: `Money::normalize(string|int $value): string`

- [ ] Write failing tests for deterministic references, Saudi phone normalization, and decimal normalization.
- [ ] Implement minimal utilities.
- [ ] Run utility tests until green.

### Task 4: Fake External Clients

**Files:**
- Create: `app/Sync/Clients/QoyodClient.php`
- Create: `app/Sync/Clients/FakeQoyodClient.php`
- Create: `app/Sync/Clients/DentolizeClient.php`
- Create: `app/Sync/Clients/FakeDentolizeClient.php`
- Modify: service provider bindings.

**Interfaces:**
- Produces: Qoyod methods `findByReference`, `createCustomer`, `createInvoice`, `createInvoicePayment`, `readInvoice`.
- Produces: Dentolize methods `changedPatients`, `changedInvoices`, `changedPayments`.

- [ ] Write failing tests for create and lookup by reference.
- [ ] Implement fake clients with local persistence.
- [ ] Bind fake clients when `WHISPER_ADAPTER_MODE=fake`.
- [ ] Run client tests until green.

### Task 5: Webhook Inbox And Queue

**Files:**
- Create: `app/Http/Controllers/DentolizeWebhookController.php`
- Create: `app/Jobs/ProcessInboxEvent.php`
- Modify: routes.
- Test: webhook tests.

**Interfaces:**
- Consumes: `Inbox` model.
- Produces: `POST /webhooks/dentolize`.

- [ ] Write failing tests for valid token, invalid token, malformed payload, duplicate event, and no inline Qoyod call.
- [ ] Implement controller with constant-time token validation.
- [ ] Implement queue job dispatch.
- [ ] Run webhook tests until green.

### Task 6: Patient, Invoice, And Payment Handlers

**Files:**
- Create: `app/Sync/Handlers/PatientHandler.php`
- Create: `app/Sync/Handlers/InvoiceHandler.php`
- Create: `app/Sync/Handlers/PaymentHandler.php`
- Create: transformer classes for patient, invoice, payment payloads.
- Test: handler tests.

**Interfaces:**
- Produces: `handle(array $payload): SyncMap` on each handler.

- [ ] Write failing tests for patient creation, invoice customer dependency, invoice total verification, payment invoice dependency, duplicate replay, and Qoyod validation failure.
- [ ] Implement transformers and handlers.
- [ ] Write audit records for every fake Qoyod write.
- [ ] Run handler tests until green.

### Task 7: Reconciliation

**Files:**
- Create: `app/Sync/Reconciliation/ReconciliationService.php`
- Create: `app/Console/Commands/RunReconciliation.php`
- Test: reconciliation tests.

**Interfaces:**
- Produces: `php artisan whisper:reconcile`
- Produces: result counts `{healed, pushed, still_failing}`.

- [ ] Write failing tests for missing mock Dentolize records being mirrored and pushed.
- [ ] Implement mirror upsert and handler reuse.
- [ ] Mark healed failures as `fixed`.
- [ ] Run reconciliation tests until green.

### Task 8: Admin Auth, API, And Console

**Files:**
- Create: admin auth controllers and middleware.
- Create: admin summary/items/retry/reconcile controllers.
- Create: Blade views for login, dashboard, item list, item detail.
- Test: admin feature tests.

**Interfaces:**
- Produces: `/admin/login`, `/admin`, `/admin/items/{id}`
- Produces: JSON endpoints from the master spec under `/admin/*`.

- [ ] Write failing tests for login, role checks, summary counts, filters, detail audit trail, retry, and run reconciliation.
- [ ] Implement auth, RBAC, access logging, controllers, and views.
- [ ] Run admin tests until green.

### Task 9: Expenses, Treasuries, Reports, And Runbook

**Files:**
- Create: expense, expense payment, and treasury handlers.
- Create: daily report command.
- Modify: `docs/RUNBOOK.md`
- Create: `docs/PROJECT_TOUR.md`

**Interfaces:**
- Produces: `php artisan whisper:daily-report`

- [ ] Write failing tests for expense and expense payment mapping.
- [ ] Implement handlers and fake Qoyod bill/payment methods.
- [ ] Add daily report generation in local mode.
- [ ] Complete runbook and project tour.
- [ ] Run the full test suite.

## Self-Review

- Each task creates an independently testable slice.
- The plan covers the local hybrid design and leaves live financial writes behind explicit Phase 0 gates.
- The task interfaces name the files and methods later tasks rely on.
- No implementation step assumes real Dentolize or Qoyod credentials.
