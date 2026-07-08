<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Sync\Clients\LiveQoyodClient;
use Illuminate\Console\Command;
use Throwable;

class CreateQoyodTestContact extends Command
{
    protected $signature = 'whisper:qoyod-test-contact {--name=Wasif Test - DELETE}';

    protected $description = 'Create a clearly labeled live Qoyod test contact only. Does not create invoices or payments.';

    public function handle(LiveQoyodClient $qoyod): int
    {
        if ((string) config('whisper.qoyod_api_key') === '') {
            $this->components->error('QOYOD_API_KEY is missing. Add it to .env before running this command.');

            return self::FAILURE;
        }

        $name = (string) $this->option('name');
        $body = [
            'contact' => [
                'name' => $name,
                'organization' => '',
                'email' => '',
                'phone_number' => '',
                'tax_number' => '',
                'status' => 'Active',
                'shipping_address' => [
                    'shipping_address' => '',
                    'shipping_city' => '',
                    'shipping_country' => '',
                ],
                'billing_address' => [
                    'billing_address' => '',
                    'billing_city' => '',
                    'billing_country' => '',
                    'building_number' => '',
                ],
            ],
        ];

        try {
            $response = $qoyod->createCustomer($body);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        AuditLog::query()->create([
            'correlation_id' => 'qoyod-test-contact',
            'action' => 'create_test_customer',
            'target_system' => 'Qoyod',
            'endpoint' => '/customers',
            'http_method' => 'POST',
            'request_body' => $body,
            'response_body' => $response['payload'],
            'response_code' => $response['status_code'],
        ]);

        $this->components->info("Created Qoyod test contact '{$name}' with id {$response['id']}.");
        $this->line('Delete this test contact from Qoyod after verification.');

        return self::SUCCESS;
    }
}
