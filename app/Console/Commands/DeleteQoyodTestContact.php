<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Sync\Clients\LiveQoyodClient;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class DeleteQoyodTestContact extends Command
{
    protected $signature = 'whisper:qoyod-delete-test-contact {qoyod_id}';

    protected $description = 'Delete a Qoyod test contact by id using the live API.';

    public function handle(LiveQoyodClient $qoyod): int
    {
        if ((string) config('whisper.qoyod_api_key') === '') {
            $this->components->error('QOYOD_API_KEY is missing. Add it to .env before running this command.');

            return self::FAILURE;
        }

        $qoyodId = (string) $this->argument('qoyod_id');

        try {
            $action = 'delete_test_customer';
            $method = 'DELETE';
            $response = $qoyod->deleteCustomer($qoyodId);
        } catch (Throwable $exception) {
            if (! str_contains($exception->getMessage(), 'HTTP 404')) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }

            try {
                $action = 'deactivate_test_customer';
                $method = 'PUT';
                $response = $qoyod->deactivateCustomer($qoyodId);
            } catch (Throwable $fallbackException) {
                throw new RuntimeException($fallbackException->getMessage(), previous: $fallbackException);
            }
        }

        AuditLog::query()->create([
            'correlation_id' => 'qoyod-test-contact-delete-'.$qoyodId,
            'action' => $action,
            'target_system' => 'Qoyod',
            'endpoint' => '/customers/'.$qoyodId,
            'http_method' => $method,
            'request_body' => ['qoyod_id' => $qoyodId],
            'response_body' => $response['payload'],
            'response_code' => $response['status_code'],
        ]);

        if ($action === 'delete_test_customer') {
            $this->components->info("Deleted Qoyod test contact with id {$qoyodId}.");
        } else {
            $this->components->info("Deactivated Qoyod test contact with id {$qoyodId}.");
        }

        return self::SUCCESS;
    }
}
