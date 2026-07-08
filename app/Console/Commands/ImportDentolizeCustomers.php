<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DentolizeMirror;
use App\Models\SyncMap;
use App\Support\PhoneNormalizer;
use App\Support\ReferenceBuilder;
use App\Sync\Clients\LiveDentolizeClient;
use App\Sync\Clients\LiveQoyodClient;
use Illuminate\Console\Command;
use Throwable;

class ImportDentolizeCustomers extends Command
{
    protected $signature = 'whisper:import-dentolize-customers {--limit=5}';

    protected $description = 'Fetch Dentolize patients through API-only GraphQL and create Qoyod contacts.';

    public function handle(LiveDentolizeClient $dentolize, LiveQoyodClient $qoyod): int
    {
        if ((string) config('whisper.dentolize_session_cookie') === '') {
            $this->components->error('DENTOLIZE_SESSION_COOKIE is missing. Add it to .env before running this command.');

            return self::FAILURE;
        }

        if ((string) config('whisper.qoyod_api_key') === '') {
            $this->components->error('QOYOD_API_KEY is missing. Add it to .env before running this command.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');

        try {
            $patients = $dentolize->fetchPatients($limit);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $imported = 0;

        foreach ($patients as $patient) {
            $reference = ReferenceBuilder::for('patient', (string) $patient['id']);
            $syncMap = SyncMap::query()->firstOrCreate(
                ['entity_type' => 'patient', 'dentolize_id' => (string) $patient['id']],
                [
                    'dentolize_number' => $patient['patientId'] ?: null,
                    'qoyod_reference' => $reference,
                    'status' => 'pending',
                    'first_seen_at' => now(),
                    'payload_hash' => hash('sha256', json_encode($patient, JSON_THROW_ON_ERROR)),
                ],
            );

            if (in_array($syncMap->status, ['transferred', 'fixed'], true) && $syncMap->qoyod_id) {
                continue;
            }

            DentolizeMirror::query()->updateOrCreate(
                ['entity_type' => 'patient', 'dentolize_id' => (string) $patient['id']],
                [
                    'dentolize_number' => $patient['patientId'] ?: null,
                    'raw' => $patient,
                    'payload_hash' => hash('sha256', json_encode($patient, JSON_THROW_ON_ERROR)),
                    'pulled_at' => now(),
                    'synced_to_qoyod' => false,
                ],
            );

            $body = $this->qoyodCustomerPayload($patient);

            try {
                $response = $qoyod->createCustomer($body);
            } catch (Throwable $exception) {
                $syncMap->update([
                    'status' => 'failed',
                    'rejected_by' => 'Qoyod',
                    'last_error' => $exception->getMessage(),
                    'attempts' => $syncMap->attempts + 1,
                    'last_attempt_at' => now(),
                ]);

                continue;
            }

            $syncMap->update([
                'qoyod_id' => $response['id'],
                'status' => $syncMap->status === 'failed' ? 'fixed' : 'transferred',
                'rejected_by' => null,
                'last_error' => null,
                'attempts' => $syncMap->attempts + 1,
                'last_attempt_at' => now(),
                'synced_at' => now(),
            ]);

            DentolizeMirror::query()
                ->where('entity_type', 'patient')
                ->where('dentolize_id', (string) $patient['id'])
                ->update(['synced_to_qoyod' => true]);

            AuditLog::query()->create([
                'correlation_id' => (string) $patient['id'],
                'sync_map_id' => $syncMap->id,
                'action' => 'import_dentolize_customer',
                'target_system' => 'Qoyod',
                'endpoint' => '/customers',
                'http_method' => 'POST',
                'request_body' => $body,
                'response_body' => $response['payload'],
                'response_code' => $response['status_code'],
            ]);

            $imported++;
            $this->line("Imported Dentolize patient {$patient['id']} to Qoyod contact {$response['id']}.");
        }

        $this->components->info("Imported {$imported} Dentolize customers into Qoyod.");

        return self::SUCCESS;
    }

    private function qoyodCustomerPayload(array $patient): array
    {
        $name = trim(($patient['firstName'] ?? '').' '.($patient['lastName'] ?? ''));

        return [
            'contact' => [
                'name' => $name !== '' ? $name : 'Dentolize Patient '.$patient['patientId'],
                'organization' => 'Dentolize patient '.$patient['patientId'],
                'email' => '',
                'phone_number' => PhoneNormalizer::toSaudiE164($patient['phoneNumber'] ?? null),
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
    }
}
