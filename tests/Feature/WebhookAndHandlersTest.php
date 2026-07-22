<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Inbox;
use App\Models\SyncMap;
use App\Sync\Handlers\InvoiceHandler;
use App\Sync\Handlers\PatientHandler;
use App\Sync\Handlers\PaymentHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_patient_handler_is_idempotent_and_audited(): void
    {
        $handler = app(PatientHandler::class);

        $first = $handler->handle($this->patientPayload());
        $second = $handler->handle($this->patientPayload());

        $this->assertSame($first->id, $second->id);
        $this->assertSame('transferred', $first->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'create_customer')->count());
    }

    public function test_invoice_handler_creates_customer_dependency_and_invoice(): void
    {
        $syncMap = app(InvoiceHandler::class)->handle($this->invoicePayload());

        $this->assertSame('transferred', $syncMap->status);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'patient', 'dentolize_id' => 'patient-1']);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'invoice', 'dentolize_id' => 'invoice-1', 'qoyod_reference' => 'DENTO-INV-21038']);
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
}
