<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Sync\Clients\LiveDentolizeClient;
use App\Sync\Clients\LiveQoyodClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LivePatientFlowImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_dentolize_client_fetches_patient_flow(): void
    {
        config([
            'whisper.dentolize_graphql_url' => 'https://api.dentolize.test/',
            'whisper.dentolize_session_cookie' => 'session-cookie',
        ]);

        Http::fake([
            'https://api.dentolize.test/' => Http::sequence()
                ->push(['data' => [
                    'patient-uuid-0000-4000-8000-000000000001', 'Wasif', 'Iqbal', '+966_500000001', 1001, 'doctor-1', 'Dr Demo',
                ]], 200)
                ->push(['data' => [
                    'invoice-uuid-0000-4000-8000-000000000001', '#21038', '100.00', '100.00', '0', '2026-07-08T10:00:00.000Z',
                ]], 200)
                ->push(['data' => [
                    'payment-uuid-0000-4000-8000-000000000001', '100.00', '2026-07-08T10:05:00.000Z', '#21038',
                ]], 200),
        ]);

        $flow = app(LiveDentolizeClient::class)->fetchPatientFlow('patient-uuid-0000-4000-8000-000000000001');

        $this->assertSame('patient-uuid-0000-4000-8000-000000000001', $flow['patient']['id']);
        $this->assertSame('Dr Demo', $flow['patient']['doctorName']);
        $this->assertSame('#21038', $flow['invoices'][0]['invoiceId']);
        $this->assertSame('100.00', $flow['payments'][0]['amount']);
    }

    public function test_live_dentolize_client_prefers_nested_patient_flow_records(): void
    {
        config([
            'whisper.dentolize_graphql_url' => 'https://api.dentolize.test/',
            'whisper.dentolize_session_cookie' => 'session-cookie',
        ]);

        Http::fake([
            'https://api.dentolize.test/' => Http::sequence()
                ->push(['data' => [
                    'patient-uuid-0000-4000-8000-000000000001',
                    'Wasif',
                    'Iqbal',
                    '+966_500000001',
                    1001,
                    'doctor-1',
                    'Dr Demo',
                    ['id' => 0, 'firstName' => 1, 'lastName' => 2, 'phoneNumber' => 3, 'patientId' => 4, 'doctor' => ['id' => 5, 'name' => 6]],
                ]], 200)
                ->push(['data' => [
                    'invoice-uuid-0000-4000-8000-000000000001',
                    '#21038',
                    '100.00',
                    '115.00',
                    '15',
                    '2026-07-08T10:00:00.000Z',
                    ['id' => 0, 'invoiceId' => 1, 'subtotal' => 2, 'total' => 3, 'taxPercent' => 4, 'createdAt' => 5],
                ]], 200)
                ->push(['data' => [
                    'payment-uuid-0000-4000-8000-000000000001',
                    '115.00',
                    '2026-07-08T10:05:00.000Z',
                    '#21038',
                    ['invoiceId' => 3],
                    ['id' => 0, 'amount' => 1, 'createdAt' => 2, 'invoice' => 4],
                ]], 200),
        ]);

        $flow = app(LiveDentolizeClient::class)->fetchPatientFlow('patient-uuid-0000-4000-8000-000000000001');

        $this->assertSame('Dr Demo', $flow['patient']['doctorName']);
        $this->assertSame('#21038', $flow['invoices'][0]['invoiceId']);
        $this->assertSame('#21038', $flow['payments'][0]['invoiceId']);
    }

    public function test_live_qoyod_client_creates_draft_invoice_and_payment_payloads(): void
    {
        config([
            'whisper.qoyod_base_url' => 'https://api.qoyod.test/2.0/',
            'whisper.qoyod_api_key' => 'test-key',
        ]);

        Http::fake([
            'https://api.qoyod.test/2.0/invoices' => Http::response(['invoice' => ['id' => 77]], 201),
            'https://api.qoyod.test/2.0/invoice_payments' => Http::response(['invoice_payment' => ['id' => 88]], 201),
        ]);

        $invoice = app(LiveQoyodClient::class)->createInvoice([
            'invoice' => [
                'contact_id' => '25',
                'reference' => 'DENTO-INV-21038',
                'status' => 'Draft',
                'inventory_id' => '1',
                'line_items' => [[
                    'product_id' => '13',
                    'description' => 'Dental Services',
                    'quantity' => '1.0',
                    'unit_price' => '100.00',
                    'tax_percent' => '15',
                ]],
            ],
        ]);

        $payment = app(LiveQoyodClient::class)->createInvoicePayment([
            'invoice_payment' => [
                'reference' => 'DENTO-PAY-payment-1',
                'invoice_id' => '77',
                'account_id' => '7',
                'date' => '2026-07-08',
                'amount' => '115.00',
            ],
        ]);

        $this->assertSame('77', $invoice['id']);
        $this->assertSame('88', $payment['id']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.qoyod.test/2.0/invoices'
            && $request['invoice']['status'] === 'Draft'
            && $request['invoice']['line_items'][0]['product_id'] === '13');
    }

    public function test_patient_flow_command_creates_contact_invoice_and_payment_attempt(): void
    {
        config([
            'whisper.dentolize_graphql_url' => 'https://api.dentolize.test/',
            'whisper.dentolize_session_cookie' => 'session-cookie',
            'whisper.qoyod_base_url' => 'https://api.qoyod.test/2.0/',
            'whisper.qoyod_api_key' => 'test-key',
            'whisper.qoyod_generic_product_id' => '13',
            'whisper.default_inventory_id' => '1',
            'whisper.default_account_id' => '7',
        ]);

        Http::fake([
            'https://api.dentolize.test/' => Http::sequence()
                ->push(['data' => [
                    'patient-uuid-0000-4000-8000-000000000001', 'Wasif', 'Iqbal', '+966_500000001', 1001, 'doctor-1', 'Dr Demo',
                ]], 200)
                ->push(['data' => [
                    'invoice-uuid-0000-4000-8000-000000000001', '#21038', '100.00', '100.00', '0', '2026-07-08T10:00:00.000Z',
                ]], 200)
                ->push(['data' => [
                    'payment-uuid-0000-4000-8000-000000000001', '100.00', '2026-07-08T10:05:00.000Z', '#21038',
                ]], 200),
            'https://api.qoyod.test/2.0/customers' => Http::response(['contact' => ['id' => 25]], 201),
            'https://api.qoyod.test/2.0/invoices' => Http::response(['invoice' => ['id' => 77]], 201),
            'https://api.qoyod.test/2.0/invoice_payments' => Http::response(['invoice_payment' => ['id' => 88]], 201),
        ]);

        $this->artisan('whisper:import-dentolize-patient-flow patient-uuid-0000-4000-8000-000000000001')
            ->expectsOutputToContain('Created Qoyod customer')
            ->expectsOutputToContain('Created Qoyod Draft invoice')
            ->expectsOutputToContain('Created Qoyod payment')
            ->assertSuccessful();

        $this->assertSame(3, AuditLog::query()->count());

        $invoiceAudit = AuditLog::query()
            ->where('action', 'create_flow_invoice')
            ->firstOrFail();

        $this->assertSame('0', (string) $invoiceAudit->request_body['invoice']['line_items'][0]['tax_percent']);
    }

    public function test_patient_flow_command_fails_when_invoice_creation_fails(): void
    {
        config([
            'whisper.dentolize_graphql_url' => 'https://api.dentolize.test/',
            'whisper.dentolize_session_cookie' => 'session-cookie',
            'whisper.qoyod_base_url' => 'https://api.qoyod.test/2.0/',
            'whisper.qoyod_api_key' => 'test-key',
            'whisper.qoyod_generic_product_id' => '13',
            'whisper.default_inventory_id' => '1',
            'whisper.default_account_id' => '7',
        ]);

        Http::fake([
            'https://api.dentolize.test/' => Http::sequence()
                ->push(['data' => [
                    'patient-uuid-0000-4000-8000-000000000001', 'Wasif', 'Iqbal', '+966_500000001', 1001, 'doctor-1', 'Dr Demo',
                ]], 200)
                ->push(['data' => [
                    'invoice-uuid-0000-4000-8000-000000000001', '#21038', '100.00', '100.00', '0', '2026-07-08T10:00:00.000Z',
                ]], 200)
                ->push(['data' => [
                    'payment-uuid-0000-4000-8000-000000000001', '100.00', '2026-07-08T10:05:00.000Z', '#21038',
                ]], 200),
            'https://api.qoyod.test/2.0/customers' => Http::response(['contact' => ['id' => 25]], 201),
            'https://api.qoyod.test/2.0/invoices' => Http::response(['errors' => ['reference' => ['has already been taken']]], 422),
        ]);

        $this->artisan('whisper:import-dentolize-patient-flow patient-uuid-0000-4000-8000-000000000001')
            ->expectsOutputToContain('Created Qoyod customer')
            ->expectsOutputToContain('Invoice 21038 failed')
            ->expectsOutputToContain('Skipped payment')
            ->assertFailed();

        $this->assertSame(1, AuditLog::query()->count());
    }
}
