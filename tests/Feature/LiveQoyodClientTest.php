<?php

namespace Tests\Feature;

use App\Sync\Clients\LiveQoyodClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveQoyodClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_qoyod_client_posts_customer_with_api_key_header(): void
    {
        config([
            'whisper.qoyod_base_url' => 'https://api.qoyod.test/2.0/',
            'whisper.qoyod_api_key' => 'test-key',
        ]);

        Http::fake([
            'https://api.qoyod.test/2.0/customers' => Http::response([
                'contact' => ['id' => 123, 'name' => 'Wasif Test - DELETE'],
            ], 201),
        ]);

        $response = app(LiveQoyodClient::class)->createCustomer([
            'contact' => [
                'name' => 'Wasif Test - DELETE',
                'organization' => '',
                'email' => '',
                'phone_number' => '',
                'tax_number' => '',
                'status' => 'Active',
            ],
        ]);

        $this->assertSame('123', $response['id']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.qoyod.test/2.0/customers'
                && $request->hasHeader('API-KEY', 'test-key')
                && $request['contact']['name'] === 'Wasif Test - DELETE';
        });
    }

    public function test_live_qoyod_reference_lookup_is_non_blocking(): void
    {
        $this->assertNull(app(LiveQoyodClient::class)->findByReference('customer', 'DENTO-CUST-patient-1'));
    }

    public function test_live_qoyod_client_posts_simple_bill_with_api_key_header(): void
    {
        config([
            'whisper.qoyod_base_url' => 'https://api.qoyod.test/2.0/',
            'whisper.qoyod_api_key' => 'test-key',
        ]);

        Http::fake([
            'https://api.qoyod.test/2.0/simple_bills' => Http::response([
                'simple_bill' => ['id' => 789, 'reference' => 'DENTO-EXP-expense-1'],
            ], 201),
        ]);

        $response = app(LiveQoyodClient::class)->createSimpleBill([
            'simple_bill' => [
                'vendor_id' => '1',
                'reference' => 'DENTO-EXP-expense-1',
                'description' => 'Dental supplies',
                'issue_date' => '2026-07-08',
                'status' => 'Approved',
                'line_items' => [[
                    'description' => 'Dental supplies',
                    'quantity' => '2',
                    'unit_price' => '75.50',
                    'tax_percent' => '15',
                ]],
            ],
        ]);

        $this->assertSame('789', $response['id']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.qoyod.test/2.0/simple_bills'
            && $request->hasHeader('API-KEY', 'test-key')
            && $request['simple_bill']['reference'] === 'DENTO-EXP-expense-1');
    }

    public function test_live_qoyod_client_posts_simple_bill_payment_with_api_key_header(): void
    {
        config([
            'whisper.qoyod_base_url' => 'https://api.qoyod.test/2.0/',
            'whisper.qoyod_api_key' => 'test-key',
        ]);

        Http::fake([
            'https://api.qoyod.test/2.0/simple_bill_payments' => Http::response([
                'simple_bill_payment' => ['id' => 790, 'reference' => 'DENTO-EXPPAY-expense-payment-1'],
            ], 201),
        ]);

        $response = app(LiveQoyodClient::class)->createSimpleBillPayment([
            'simple_bill_payment' => [
                'reference' => 'DENTO-EXPPAY-expense-payment-1',
                'simple_bill_id' => '789',
                'account_id' => '7',
                'date' => '2026-07-08',
                'amount' => '151.00',
            ],
        ]);

        $this->assertSame('790', $response['id']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.qoyod.test/2.0/simple_bill_payments'
            && $request->hasHeader('API-KEY', 'test-key')
            && $request['simple_bill_payment']['reference'] === 'DENTO-EXPPAY-expense-payment-1');
    }

    public function test_test_contact_command_creates_customer_and_records_audit_log(): void
    {
        config([
            'whisper.qoyod_base_url' => 'https://api.qoyod.test/2.0/',
            'whisper.qoyod_api_key' => 'test-key',
        ]);

        Http::fake([
            'https://api.qoyod.test/2.0/customers' => Http::response([
                'contact' => ['id' => 456, 'name' => 'Wasif Test - DELETE'],
            ], 201),
        ]);

        $this->artisan('whisper:qoyod-test-contact')
            ->expectsOutputToContain('Created Qoyod test contact')
            ->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'correlation_id' => 'qoyod-test-contact',
            'action' => 'create_test_customer',
            'target_system' => 'Qoyod',
            'response_code' => 201,
        ]);
    }

    public function test_live_qoyod_client_deletes_customer_with_api_key_header(): void
    {
        config([
            'whisper.qoyod_base_url' => 'https://api.qoyod.test/2.0/',
            'whisper.qoyod_api_key' => 'test-key',
        ]);

        Http::fake([
            'https://api.qoyod.test/2.0/customers/24' => Http::response([], 200),
        ]);

        $response = app(LiveQoyodClient::class)->deleteCustomer('24');

        $this->assertSame('24', $response['id']);
        $this->assertSame(200, $response['status_code']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && $request->url() === 'https://api.qoyod.test/2.0/customers/24'
                && $request->hasHeader('API-KEY', 'test-key');
        });
    }

    public function test_delete_test_contact_command_records_audit_log(): void
    {
        config([
            'whisper.qoyod_base_url' => 'https://api.qoyod.test/2.0/',
            'whisper.qoyod_api_key' => 'test-key',
        ]);

        Http::fake([
            'https://api.qoyod.test/2.0/customers/24' => Http::response([], 200),
        ]);

        $this->artisan('whisper:qoyod-delete-test-contact 24')
            ->expectsOutputToContain('Deleted Qoyod test contact')
            ->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'correlation_id' => 'qoyod-test-contact-delete-24',
            'action' => 'delete_test_customer',
            'target_system' => 'Qoyod',
            'response_code' => 200,
        ]);
    }

    public function test_delete_test_contact_command_marks_deleted_when_delete_endpoint_is_unavailable(): void
    {
        config([
            'whisper.qoyod_base_url' => 'https://api.qoyod.test/2.0/',
            'whisper.qoyod_api_key' => 'test-key',
        ]);

        Http::fake([
            'https://api.qoyod.test/2.0/customers/24' => Http::sequence()
                ->push([], 404)
                ->push(['contact' => ['id' => 24, 'status' => 'Deleted', 'name' => 'DELETED TEST CONTACT 24']], 200),
        ]);

        $this->artisan('whisper:qoyod-delete-test-contact 24')
            ->expectsOutputToContain('Marked Qoyod test contact 24 as Deleted')
            ->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'correlation_id' => 'qoyod-test-contact-delete-24',
            'action' => 'mark_deleted_test_customer',
            'target_system' => 'Qoyod',
            'response_code' => 200,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request['contact']['status'] === 'Deleted'
            && $request['contact']['name'] === 'DELETED TEST CONTACT 24'
            && $request['contact']['pos'] === false);
    }

    public function test_test_contact_command_refuses_to_run_without_api_key(): void
    {
        config(['whisper.qoyod_api_key' => '']);

        $this->artisan('whisper:qoyod-test-contact')
            ->expectsOutputToContain('QOYOD_API_KEY is missing')
            ->assertFailed();
    }
}
