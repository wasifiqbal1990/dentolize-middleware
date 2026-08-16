<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Inbox;
use App\Models\SyncMap;
use App\Models\WebhookAttempt;
use App\Sync\Clients\QoyodClient;
use App\Sync\Handlers\ExpenseHandler;
use App\Sync\Handlers\ExpensePaymentHandler;
use App\Sync\Handlers\InvoiceHandler;
use App\Sync\Handlers\PatientHandler;
use App\Sync\Handlers\PaymentHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WebhookAndHandlersTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_accepts_valid_token_and_processes_patient_event(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $response = $this->postJson('/webhooks/dentolize', [
            'event_id' => 'evt-patient-1',
            'event_type' => 'New Patient',
            'data' => $this->patientPayload(),
        ], ['X-Dentolize-Verify-Token' => 'secret-token']);

        $response->assertOk()->assertJson(['status' => 'received']);
        $this->assertDatabaseHas('inboxes', ['dentolize_event_id' => 'evt-patient-1', 'processing_status' => 'done']);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'patient', 'dentolize_id' => 'patient-1', 'status' => 'transferred']);
    }

    public function test_webhook_rejects_invalid_token_without_persisting(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $this->postJson('/webhooks/dentolize', [
            'event_id' => 'evt-patient-1',
            'event_type' => 'New Patient',
            'data' => $this->patientPayload(),
        ], ['X-Dentolize-Verify-Token' => 'wrong'])->assertUnauthorized();

        $this->assertSame(0, Inbox::query()->count());
        $this->assertDatabaseHas('webhook_attempts', [
            'event_id' => 'evt-patient-1',
            'event_type' => 'New Patient',
            'verify_token_present' => true,
            'verify_token_valid' => false,
            'result' => 'rejected_invalid_token',
        ]);
    }

    public function test_webhook_accepts_verify_token_from_body_without_storing_it(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $response = $this->postJson('/webhooks/dentolize', [
            'verifyToken' => 'secret-token',
            'event_id' => 'evt-patient-body-token',
            'event_type' => 'New Patient',
            'data' => $this->patientPayload(),
        ]);

        $response->assertOk()->assertJson(['status' => 'received']);

        $inbox = Inbox::query()->where('dentolize_event_id', 'evt-patient-body-token')->firstOrFail();

        $this->assertArrayNotHasKey('verifyToken', $inbox->raw_payload);
        $this->assertDatabaseHas('webhook_attempts', [
            'event_id' => 'evt-patient-body-token',
            'event_type' => 'New Patient',
            'verify_token_present' => true,
            'verify_token_valid' => true,
            'result' => 'accepted',
        ]);
    }

    public function test_webhook_accepts_form_encoded_dentolize_payload(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $response = $this->post('/webhooks/dentolize', [
            'verifyToken' => 'secret-token',
            'eventId' => 'evt-patient-form-token',
            'eventType' => 'مريض جديد',
            'data' => $this->patientPayload(),
        ]);

        $response->assertOk()->assertJson(['status' => 'received']);
        $this->assertDatabaseHas('inboxes', [
            'dentolize_event_id' => 'evt-patient-form-token',
            'event_type' => 'مريض جديد',
            'processing_status' => 'done',
        ]);

        $inbox = Inbox::query()->where('dentolize_event_id', 'evt-patient-form-token')->firstOrFail();

        $this->assertArrayNotHasKey('verifyToken', $inbox->raw_payload);
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'patient',
            'dentolize_id' => 'patient-1',
            'status' => 'transferred',
        ]);
    }

    public function test_webhook_accepts_verify_token_from_query_string(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $response = $this->postJson('/webhooks/dentolize?verify_token=secret-token', [
            'event_id' => 'evt-patient-query-token',
            'event_type' => 'New Patient',
            'data' => $this->patientPayload(),
        ]);

        $response->assertOk()->assertJson(['status' => 'received']);
        $this->assertDatabaseHas('inboxes', ['dentolize_event_id' => 'evt-patient-query-token', 'processing_status' => 'done']);
    }

    public function test_webhook_processes_supported_arabic_event_names(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $response = $this->postJson('/webhooks/dentolize', [
            'verifyToken' => 'secret-token',
            'event_id' => 'evt-patient-arabic',
            'event_type' => 'مريض جديد',
            'data' => $this->patientPayload(),
        ]);

        $response->assertOk()->assertJson(['status' => 'received']);
        $this->assertDatabaseHas('inboxes', ['dentolize_event_id' => 'evt-patient-arabic', 'processing_status' => 'done']);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'patient', 'dentolize_id' => 'patient-1', 'status' => 'transferred']);
    }

    public function test_webhook_processes_dentolize_uppercase_event_names(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $response = $this->postJson('/webhooks/dentolize?verify_token=secret-token', [
            'event_id' => 'evt-patient-uppercase',
            'event_type' => 'NEW_PATIENT',
            'data' => $this->patientPayload(),
        ]);

        $response->assertOk()->assertJson(['status' => 'received']);
        $this->assertDatabaseHas('inboxes', [
            'dentolize_event_id' => 'evt-patient-uppercase',
            'event_type' => 'NEW_PATIENT',
            'processing_status' => 'done',
        ]);
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'patient',
            'dentolize_id' => 'patient-1',
            'status' => 'transferred',
        ]);
    }

    public function test_webhook_processes_dentolize_uppercase_accounting_event_names(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $this->postJson('/webhooks/dentolize?verify_token=secret-token', [
            'event_id' => 'evt-invoice-uppercase',
            'event_type' => 'NEW_INVOICE',
            'data' => $this->invoicePayload(),
        ])->assertOk()->assertJson(['status' => 'received']);

        $this->postJson('/webhooks/dentolize?verify_token=secret-token', [
            'event_id' => 'evt-payment-uppercase',
            'event_type' => 'NEW_PAYMENT',
            'data' => $this->paymentPayload(),
        ])->assertOk()->assertJson(['status' => 'received']);

        $this->postJson('/webhooks/dentolize?verify_token=secret-token', [
            'event_id' => 'evt-expense-uppercase',
            'event_type' => 'NEW_EXPENSE',
            'data' => $this->expensePayload(),
        ])->assertOk()->assertJson(['status' => 'received']);

        $this->postJson('/webhooks/dentolize?verify_token=secret-token', [
            'event_id' => 'evt-expense-payment-uppercase',
            'event_type' => 'NEW_EXPENSE_PAYMENT',
            'data' => $this->expensePaymentPayload(),
        ])->assertOk()->assertJson(['status' => 'received']);

        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'invoice', 'dentolize_id' => 'invoice-1', 'status' => 'transferred']);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'payment', 'dentolize_id' => 'payment-1', 'status' => 'transferred']);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'expense', 'dentolize_id' => 'expense-1', 'status' => 'transferred']);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'expense_payment', 'dentolize_id' => 'expense-payment-1', 'status' => 'transferred']);
    }

    public function test_webhook_processes_inline_by_default_even_when_queue_connection_is_database(): void
    {
        config([
            'queue.default' => 'database',
            'whisper.webhook_processing' => 'sync',
            'whisper.webhook_verify_token' => 'secret-token',
        ]);

        $response = $this->postJson('/webhooks/dentolize', [
            'verifyToken' => 'secret-token',
            'event_id' => 'evt-inline-database-queue',
            'event_type' => 'مريض جديد',
            'data' => $this->patientPayload(),
        ]);

        $response->assertOk()->assertJson(['status' => 'received']);
        $this->assertDatabaseHas('inboxes', [
            'dentolize_event_id' => 'evt-inline-database-queue',
            'processing_status' => 'done',
        ]);
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'patient',
            'dentolize_id' => 'patient-1',
            'status' => 'transferred',
        ]);
    }

    public function test_webhook_dedupes_replayed_event_id(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $payload = [
            'event_id' => 'evt-patient-1',
            'event_type' => 'New Patient',
            'data' => $this->patientPayload(),
        ];

        $this->postJson('/webhooks/dentolize', $payload, ['X-Dentolize-Verify-Token' => 'secret-token'])->assertOk();
        $this->postJson('/webhooks/dentolize', $payload, ['X-Dentolize-Verify-Token' => 'secret-token'])->assertOk();

        $this->assertSame(1, Inbox::query()->count());
        $this->assertSame(1, SyncMap::query()->where('entity_type', 'patient')->count());
    }

    public function test_webhook_marks_inbox_failed_when_processing_throws(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $this->app->bind(QoyodClient::class, fn () => new class implements QoyodClient
        {
            public function findByReference(string $recordType, string $reference): ?array
            {
                return null;
            }

            public function createCustomer(array $payload): array
            {
                throw new RuntimeException('Qoyod unavailable');
            }

            public function createInvoice(array $payload): array
            {
                throw new RuntimeException('Qoyod unavailable');
            }

            public function createInvoicePayment(array $payload): array
            {
                throw new RuntimeException('Qoyod unavailable');
            }

            public function createSimpleBill(array $payload): array
            {
                throw new RuntimeException('Qoyod unavailable');
            }

            public function createSimpleBillPayment(array $payload): array
            {
                throw new RuntimeException('Qoyod unavailable');
            }

            public function readInvoice(string $qoyodId): ?array
            {
                return null;
            }
        });

        $response = $this->postJson('/webhooks/dentolize', [
            'event_id' => 'evt-patient-qoyod-down',
            'event_type' => 'New Patient',
            'data' => $this->patientPayload(),
        ], ['X-Dentolize-Verify-Token' => 'secret-token']);

        $response->assertOk()->assertJson(['status' => 'received']);

        $inbox = Inbox::query()->where('dentolize_event_id', 'evt-patient-qoyod-down')->firstOrFail();

        $this->assertSame('failed', $inbox->processing_status);
        $this->assertSame('Qoyod unavailable', $inbox->headers['last_error']);
    }

    public function test_patient_handler_is_idempotent_and_audited(): void
    {
        $handler = app(PatientHandler::class);

        $first = $handler->handle($this->patientPayload());
        $second = $handler->handle($this->patientPayload());

        $this->assertSame($first->id, $second->id);
        $this->assertSame('transferred', $first->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'create_customer')->count());
    }

    public function test_patient_handler_maps_real_dentolize_patient_without_invalid_tax_number(): void
    {
        $syncMap = app(PatientHandler::class)->handle([
            'id' => 'real-dentolize-patient-1',
            'name' => 'Wasif Patient DELETE',
            'file_no' => 'P-1001',
            'nationalId' => '1234567890',
            'phone' => '0111234567',
            'mobile' => '0500000000',
        ]);

        $this->assertSame('P-1001', $syncMap->dentolize_number);

        $requestBody = AuditLog::query()
            ->where('action', 'create_customer')
            ->firstOrFail()
            ->request_body;

        $this->assertSame('Wasif Patient DELETE', $requestBody['contact']['name']);
        $this->assertSame('+966500000000', $requestBody['contact']['phone_number']);
        $this->assertSame('', $requestBody['contact']['tax_number']);
    }

    public function test_invoice_handler_creates_customer_dependency_and_invoice(): void
    {
        $syncMap = app(InvoiceHandler::class)->handle($this->invoicePayload());

        $this->assertSame('transferred', $syncMap->status);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'patient', 'dentolize_id' => 'patient-1']);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'invoice', 'dentolize_id' => 'invoice-1', 'qoyod_reference' => 'DENTO-INV-21038']);
    }

    public function test_invoice_handler_uses_existing_patient_for_real_dentolize_invoice_payload(): void
    {
        app(PatientHandler::class)->handle([
            'id' => 'real-patient-1',
            'name' => 'Wasif Patient DELETE',
            'mobile' => '0500000000',
        ]);

        $syncMap = app(InvoiceHandler::class)->handle([
            'id' => 'real-invoice-1',
            'patient_id' => 'real-patient-1',
            'subtotal' => '100.00',
            'discount' => '0',
            'taxPercent' => '15',
            'total' => '115.00',
        ]);

        $this->assertSame('transferred', $syncMap->status);
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'invoice',
            'dentolize_id' => 'real-invoice-1',
            'qoyod_reference' => 'DENTO-INV-real-invoice-1',
            'status' => 'transferred',
        ]);
    }

    public function test_invoice_handler_can_link_real_dentolize_invoice_by_file_no(): void
    {
        app(PatientHandler::class)->handle([
            'id' => 'real-patient-by-file',
            'name' => 'Wasif Patient DELETE',
            'file_no' => 'P-2002',
            'mobile' => '0500000000',
        ]);

        $syncMap = app(InvoiceHandler::class)->handle([
            'id' => 'real-invoice-by-file',
            'file_no' => 'P-2002',
            'subtotal' => '100.00',
            'discount' => '0',
            'taxPercent' => '15',
            'total' => '115.00',
        ]);

        $this->assertSame('transferred', $syncMap->status);
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'invoice',
            'dentolize_id' => 'real-invoice-by-file',
            'status' => 'transferred',
        ]);
    }

    public function test_invoice_handler_does_not_send_invalid_qoyod_custom_field(): void
    {
        app(PatientHandler::class)->handle([
            'id' => 'real-patient-no-custom-field',
            'name' => 'Wasif Patient DELETE',
            'mobile' => '0500000000',
        ]);

        app(InvoiceHandler::class)->handle([
            'id' => 'real-invoice-no-custom-field',
            'patient_id' => 'real-patient-no-custom-field',
            'subtotal' => '100.00',
            'discount' => '0',
            'taxPercent' => '15',
            'total' => '115.00',
        ]);

        $requestBody = AuditLog::query()
            ->where('action', 'create_invoice')
            ->latest('id')
            ->firstOrFail()
            ->request_body;

        $this->assertArrayNotHasKey('custom_fields', $requestBody['invoice']);
        $this->assertSame('100.00', $requestBody['invoice']['line_items'][0]['unit_price']);
    }

    public function test_invoice_handler_marks_sync_map_failed_when_qoyod_rejects_invoice(): void
    {
        $this->app->bind(QoyodClient::class, fn () => new class implements QoyodClient
        {
            public function findByReference(string $recordType, string $reference): ?array
            {
                return null;
            }

            public function createCustomer(array $payload): array
            {
                return ['id' => 'customer-1', 'payload' => $payload, 'status_code' => 201];
            }

            public function createInvoice(array $payload): array
            {
                throw new RuntimeException('Qoyod invoice creation failed with HTTP 422');
            }

            public function createInvoicePayment(array $payload): array
            {
                return ['id' => 'payment-1', 'payload' => $payload, 'status_code' => 201];
            }

            public function createSimpleBill(array $payload): array
            {
                return ['id' => 'expense-1', 'payload' => $payload, 'status_code' => 201];
            }

            public function createSimpleBillPayment(array $payload): array
            {
                return ['id' => 'expense-payment-1', 'payload' => $payload, 'status_code' => 201];
            }

            public function readInvoice(string $qoyodId): ?array
            {
                return null;
            }
        });

        $this->expectException(RuntimeException::class);

        try {
            app(InvoiceHandler::class)->handle($this->invoicePayload());
        } finally {
            $this->assertDatabaseHas('sync_maps', [
                'entity_type' => 'invoice',
                'dentolize_id' => 'invoice-1',
                'status' => 'failed',
                'rejected_by' => 'Qoyod',
            ]);
        }
    }

    public function test_invoice_handler_waits_when_real_dentolize_patient_dependency_is_missing(): void
    {
        $syncMap = app(InvoiceHandler::class)->handle([
            'id' => 'real-invoice-missing-patient',
            'patient_id' => 'missing-patient',
            'subtotal' => '100.00',
            'total' => '115.00',
        ]);

        $this->assertSame('pending', $syncMap->status);
        $this->assertSame('Whisper', $syncMap->rejected_by);
        $this->assertStringContainsString('patient dependency missing', $syncMap->last_error);
    }

    public function test_payment_waits_when_invoice_dependency_is_missing(): void
    {
        $syncMap = app(PaymentHandler::class)->handle($this->paymentPayload());

        $this->assertSame('pending', $syncMap->status);
        $this->assertSame('Whisper', $syncMap->rejected_by);
        $this->assertStringContainsString('invoice dependency missing', $syncMap->last_error);
    }

    public function test_payment_transfers_after_invoice_exists(): void
    {
        app(InvoiceHandler::class)->handle($this->invoicePayload());

        $syncMap = app(PaymentHandler::class)->handle($this->paymentPayload());

        $this->assertSame('transferred', $syncMap->status);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'payment', 'dentolize_id' => 'payment-1', 'qoyod_reference' => 'DENTO-PAY-payment-1']);
    }

    public function test_payment_handler_uses_real_dentolize_invoice_id_payload(): void
    {
        app(PatientHandler::class)->handle([
            'id' => 'real-patient-1',
            'name' => 'Wasif Patient DELETE',
            'mobile' => '0500000000',
        ]);
        app(InvoiceHandler::class)->handle([
            'id' => 'real-invoice-1',
            'patient_id' => 'real-patient-1',
            'subtotal' => '100.00',
            'total' => '115.00',
        ]);

        $syncMap = app(PaymentHandler::class)->handle([
            'id' => 'real-payment-1',
            'invoice_id' => 'real-invoice-1',
            'patient_id' => 'real-patient-1',
            'amount' => '115.00',
        ]);

        $this->assertSame('transferred', $syncMap->status);
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'payment',
            'dentolize_id' => 'real-payment-1',
            'qoyod_reference' => 'DENTO-PAY-real-payment-1',
            'status' => 'transferred',
        ]);
    }

    public function test_expense_handler_creates_simple_bill(): void
    {
        $syncMap = app(ExpenseHandler::class)->handle($this->expensePayload());

        $this->assertSame('transferred', $syncMap->status);
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'expense',
            'dentolize_id' => 'expense-1',
            'qoyod_reference' => 'DENTO-EXP-expense-1',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create_simple_bill',
            'endpoint' => '/simple_bills',
        ]);
    }

    public function test_expense_payment_waits_when_expense_dependency_is_missing(): void
    {
        $syncMap = app(ExpensePaymentHandler::class)->handle($this->expensePaymentPayload());

        $this->assertSame('pending', $syncMap->status);
        $this->assertSame('Whisper', $syncMap->rejected_by);
        $this->assertStringContainsString('expense dependency missing', $syncMap->last_error);
    }

    public function test_expense_payment_transfers_after_expense_exists(): void
    {
        app(ExpenseHandler::class)->handle($this->expensePayload());

        $syncMap = app(ExpensePaymentHandler::class)->handle($this->expensePaymentPayload());

        $this->assertSame('transferred', $syncMap->status);
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'expense_payment',
            'dentolize_id' => 'expense-payment-1',
            'qoyod_reference' => 'DENTO-EXPPAY-expense-payment-1',
        ]);
    }

    public function test_webhook_processes_supported_arabic_expense_event_names(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $response = $this->postJson('/webhooks/dentolize', [
            'verifyToken' => 'secret-token',
            'event_id' => 'evt-expense-arabic',
            'event_type' => 'مصروفات جديدة',
            'data' => $this->expensePayload(),
        ]);

        $response->assertOk()->assertJson(['status' => 'received']);
        $this->assertDatabaseHas('inboxes', ['dentolize_event_id' => 'evt-expense-arabic', 'processing_status' => 'done']);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'expense', 'dentolize_id' => 'expense-1', 'status' => 'transferred']);
    }

    public function test_webhook_captures_custom_records_without_qoyod_write(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $response = $this->postJson('/webhooks/dentolize', [
            'verifyToken' => 'secret-token',
            'event_id' => 'evt-custom-operation-1',
            'event_type' => 'إجراء جديد',
            'data' => [
                'id' => 'operation-1',
                'name' => 'Custom dental operation',
                'createdAt' => '2026-07-08T10:00:00+03:00',
            ],
        ]);

        $response->assertOk()->assertJson(['status' => 'received']);
        $this->assertDatabaseHas('inboxes', ['dentolize_event_id' => 'evt-custom-operation-1', 'processing_status' => 'skipped']);
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'custom_record',
            'dentolize_id' => 'operation-1',
            'status' => 'skipped',
            'rejected_by' => 'Whisper',
        ]);
    }

    public function test_webhook_status_requires_valid_token(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $this->getJson('/webhooks/dentolize/status?verify_token=wrong')->assertUnauthorized();
    }

    public function test_webhook_status_reports_redacted_runtime_and_latest_processing_state(): void
    {
        config([
            'whisper.webhook_verify_token' => 'secret-token',
            'whisper.adapter_mode' => 'live',
            'whisper.webhook_processing' => 'sync',
            'whisper.qoyod_api_key' => 'configured-key',
            'whisper.qoyod_generic_product_id' => 'product-123',
            'whisper.default_inventory_id' => 'inventory-123',
            'whisper.default_account_id' => 'cash-101',
            'whisper.default_vendor_id' => 'vendor-123',
        ]);

        Inbox::query()->create([
            'dentolize_event_id' => 'evt-status-1',
            'event_type' => 'New Patient',
            'raw_payload' => ['data' => ['id' => 'patient-status-1']],
            'headers' => ['last_error' => 'Qoyod rejected the payload'],
            'processing_status' => 'failed',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        SyncMap::query()->create([
            'entity_type' => 'patient',
            'dentolize_id' => 'patient-status-1',
            'qoyod_reference' => 'DENTO-PAT-patient-status-1',
            'amount' => '115.00',
            'status' => 'failed',
            'last_error' => 'Qoyod rejected the payload',
            'first_seen_at' => now(),
            'last_attempt_at' => now(),
        ]);

        $response = $this->getJson('/webhooks/dentolize/status?verify_token=secret-token');

        $response
            ->assertOk()
            ->assertJsonPath('app.adapter_mode', 'live')
            ->assertJsonPath('app.webhook_processing', 'sync')
            ->assertJsonPath('qoyod.api_key_configured', true)
            ->assertJsonPath('qoyod.generic_product_id', 'product-123')
            ->assertJsonPath('inboxes.by_status.failed', 1)
            ->assertJsonPath('sync_maps.by_status.failed', 1)
            ->assertJsonPath('inboxes.latest.0.dentolize_event_id', 'evt-status-1')
            ->assertJsonPath('sync_maps.latest.0.dentolize_id', 'patient-status-1')
            ->assertJsonPath('sync_maps.latest.0.amount', '115.00');

        $this->assertStringNotContainsString('configured-key', $response->getContent());
    }

    public function test_webhook_status_reports_recent_attempts_without_tokens(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        WebhookAttempt::query()->create([
            'source' => 'dentolize',
            'http_method' => 'POST',
            'path' => 'webhooks/dentolize',
            'content_type' => 'application/json',
            'user_agent' => 'Dentolize',
            'event_id' => 'evt-attempt-status-1',
            'event_type' => 'New Patient',
            'verify_token_present' => true,
            'verify_token_valid' => false,
            'result' => 'rejected_invalid_token',
            'payload_keys' => ['verifyToken', 'eventId', 'eventType'],
            'received_at' => now(),
        ]);

        $response = $this->getJson('/webhooks/dentolize/status?verify_token=secret-token');

        $response
            ->assertOk()
            ->assertJsonPath('webhook_attempts.total', 1)
            ->assertJsonPath('webhook_attempts.by_result.rejected_invalid_token', 1)
            ->assertJsonPath('webhook_attempts.latest.0.event_id', 'evt-attempt-status-1')
            ->assertJsonPath('webhook_attempts.latest.0.verify_token_valid', false);

        $this->assertStringNotContainsString('secret-token', $response->getContent());
    }

    public function test_webhook_status_can_lookup_one_invoice_without_patient_details(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        Inbox::query()->create([
            'dentolize_event_id' => 'evt-lookup-invoice',
            'event_type' => 'NEW_INVOICE',
            'raw_payload' => [
                'type' => 'NEW_INVOICE',
                'id' => 'invoice-lookup-1',
                'name' => 'Do Not Expose Patient Name',
                'phone' => '0500000000',
                'total' => '115.00',
                'subtotal' => '100.00',
                'tax' => '15.00',
                'discount' => '0',
                'taxPercent' => '15',
                'file_no' => 'P-3003',
                'reference_no' => 'REF-3003',
            ],
            'headers' => [],
            'processing_status' => 'done',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        SyncMap::query()->create([
            'entity_type' => 'invoice',
            'dentolize_id' => 'invoice-lookup-1',
            'dentolize_number' => 'invoice-lookup-1',
            'qoyod_reference' => 'DENTO-INV-invoice-lookup-1',
            'amount' => '115.00',
            'status' => 'pending',
            'last_error' => 'patient dependency missing for invoice invoice-lookup-1',
            'first_seen_at' => now(),
            'last_attempt_at' => now(),
        ]);

        $response = $this->getJson('/webhooks/dentolize/status?verify_token=secret-token&dentolize_id=invoice-lookup-1');

        $response
            ->assertOk()
            ->assertJsonPath('lookup.dentolize_id', 'invoice-lookup-1')
            ->assertJsonPath('lookup.sync_maps.0.amount', '115.00')
            ->assertJsonPath('lookup.inboxes.0.payload.total', '115.00')
            ->assertJsonMissing(['name' => 'Do Not Expose Patient Name'])
            ->assertJsonMissing(['phone' => '0500000000']);
    }

    public function test_webhook_status_can_lookup_related_records_by_patient_id(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        Inbox::query()->create([
            'dentolize_event_id' => 'evt-lookup-patient',
            'event_type' => 'NEW_PATIENT',
            'raw_payload' => [
                'type' => 'NEW_PATIENT',
                'id' => 'patient-lookup-1',
                'name' => 'Do Not Expose Patient Name',
                'phone' => '0500000000',
                'file_no' => 'P-3003',
            ],
            'headers' => [],
            'processing_status' => 'done',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        Inbox::query()->create([
            'dentolize_event_id' => 'evt-lookup-patient-invoice',
            'event_type' => 'NEW_INVOICE',
            'raw_payload' => [
                'type' => 'NEW_INVOICE',
                'id' => 'invoice-for-patient-lookup-1',
                'patient_id' => 'patient-lookup-1',
                'total' => '250.00',
                'subtotal' => '250.00',
                'discount' => '0',
            ],
            'headers' => [],
            'processing_status' => 'done',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        Inbox::query()->create([
            'dentolize_event_id' => 'evt-lookup-patient-payment',
            'event_type' => 'NEW_PAYMENT',
            'raw_payload' => [
                'type' => 'NEW_PAYMENT',
                'id' => 'payment-for-patient-lookup-1',
                'patient_id' => 'patient-lookup-1',
                'invoice_id' => 'invoice-for-patient-lookup-1',
                'amount' => '100.00',
            ],
            'headers' => [],
            'processing_status' => 'done',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        SyncMap::query()->create([
            'entity_type' => 'patient',
            'dentolize_id' => 'patient-lookup-1',
            'dentolize_number' => 'P-3003',
            'qoyod_id' => '105',
            'qoyod_reference' => 'DENTO-CUST-patient-lookup-1',
            'status' => 'transferred',
            'first_seen_at' => now(),
        ]);

        SyncMap::query()->create([
            'entity_type' => 'payment',
            'dentolize_id' => 'payment-for-patient-lookup-1',
            'qoyod_reference' => 'DENTO-PAY-payment-for-patient-lookup-1',
            'amount' => '100.00',
            'status' => 'pending',
            'last_error' => 'invoice dependency missing for payment payment-for-patient-lookup-1',
            'first_seen_at' => now(),
        ]);

        $response = $this->getJson('/webhooks/dentolize/status?verify_token=secret-token&patient_id=patient-lookup-1');

        $response
            ->assertOk()
            ->assertJsonPath('lookup.patient_id', 'patient-lookup-1')
            ->assertJsonPath('lookup.inboxes.0.event_type', 'NEW_PAYMENT')
            ->assertJsonPath('lookup.inboxes.0.payload.amount', '100.00')
            ->assertJsonPath('lookup.inboxes.1.event_type', 'NEW_INVOICE')
            ->assertJsonPath('lookup.inboxes.1.payload.total', '250.00')
            ->assertJsonPath('lookup.sync_maps.0.entity_type', 'payment')
            ->assertJsonPath('lookup.sync_maps.0.amount', '100.00')
            ->assertJsonMissing(['name' => 'Do Not Expose Patient Name'])
            ->assertJsonMissing(['phone' => '0500000000']);
    }

    public function test_webhook_reprocess_requires_valid_token(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        $this->postJson('/webhooks/dentolize/reprocess?verify_token=wrong')->assertUnauthorized();
    }

    public function test_webhook_reprocess_runs_received_inboxes_inline(): void
    {
        config(['whisper.webhook_verify_token' => 'secret-token']);

        Inbox::query()->create([
            'dentolize_event_id' => 'evt-reprocess-1',
            'event_type' => 'مريض جديد',
            'raw_payload' => ['data' => $this->patientPayload()],
            'headers' => [],
            'processing_status' => 'received',
            'received_at' => now(),
        ]);

        $response = $this->postJson('/webhooks/dentolize/reprocess?verify_token=secret-token&statuses=received');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'reprocessed')
            ->assertJsonPath('processed_count', 1)
            ->assertJsonPath('processed_inbox_ids.0', 1);

        $this->assertDatabaseHas('inboxes', [
            'dentolize_event_id' => 'evt-reprocess-1',
            'processing_status' => 'done',
        ]);
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'patient',
            'dentolize_id' => 'patient-1',
            'status' => 'transferred',
        ]);
    }

    private function patientPayload(): array
    {
        return [
            'id' => 'patient-1',
            'firstName' => 'Sara',
            'lastName' => 'Patient',
            'phoneNumber' => '051 234 5678',
            'nationalId' => '1234567890',
        ];
    }

    private function invoicePayload(): array
    {
        return [
            'id' => 'invoice-1',
            'invoiceId' => '#21038',
            'patient' => $this->patientPayload(),
            'subtotal' => '249.00',
            'total' => '286.35',
            'taxPercent' => '15',
            'discount' => '0',
            'createdAt' => '2026-07-08T10:00:00+03:00',
            'branch' => ['id' => 'riyadh'],
        ];
    }

    private function paymentPayload(): array
    {
        return [
            'id' => 'payment-1',
            'invoiceId' => '#21038',
            'invoice' => ['id' => 'invoice-1', 'invoiceId' => '#21038'],
            'amount' => '286.35',
            'date' => '2026-07-08',
            'treasury' => ['id' => 'cash'],
        ];
    }

    private function expensePayload(): array
    {
        return [
            'id' => 'expense-1',
            'name' => 'Dental supplies',
            'type' => 'Supplies',
            'supplier' => ['id' => 'supplier-1', 'name' => 'Demo Supplier'],
            'quantity' => '2',
            'unitPrice' => '75.50',
            'total' => '151.00',
            'taxPercent' => '15',
            'date' => '2026-07-08',
            'treasury' => ['id' => 'cash'],
        ];
    }

    private function expensePaymentPayload(): array
    {
        return [
            'id' => 'expense-payment-1',
            'expense' => ['id' => 'expense-1'],
            'amount' => '151.00',
            'date' => '2026-07-08',
            'treasury' => ['id' => 'cash'],
        ];
    }
}
