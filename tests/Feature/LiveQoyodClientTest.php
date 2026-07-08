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

    public function test_test_contact_command_refuses_to_run_without_api_key(): void
    {
        config(['whisper.qoyod_api_key' => '']);

        $this->artisan('whisper:qoyod-test-contact')
            ->expectsOutputToContain('QOYOD_API_KEY is missing')
            ->assertFailed();
    }
}
