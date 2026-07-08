<?php

namespace App\Sync\Handlers;

use App\Models\AuditLog;
use App\Models\SyncMap;
use App\Support\PhoneNormalizer;
use App\Support\ReferenceBuilder;
use App\Sync\Clients\QoyodClient;

class PatientHandler
{
    public function __construct(private readonly QoyodClient $qoyod) {}

    public function handle(array $payload): SyncMap
    {
        $dentolizeId = (string) $payload['id'];
        $reference = ReferenceBuilder::for('patient', $dentolizeId);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $syncMap = SyncMap::query()->firstOrCreate(
            ['entity_type' => 'patient', 'dentolize_id' => $dentolizeId],
            [
                'qoyod_reference' => $reference,
                'status' => 'pending',
                'payload_hash' => $hash,
                'first_seen_at' => now(),
            ],
        );

        if ($syncMap->status === 'transferred' && $syncMap->payload_hash === $hash) {
            return $syncMap;
        }

        if ($existing = $this->qoyod->findByReference('customer', $reference)) {
            return $this->markTransferred($syncMap, $existing, $hash);
        }

        $body = [
            'reference' => $reference,
            'contact' => [
                'name' => trim(($payload['firstName'] ?? '').' '.($payload['lastName'] ?? '')) ?: 'Unknown Patient',
                'organization' => '',
                'email' => $payload['email'] ?? '',
                'phone_number' => PhoneNormalizer::toSaudiE164($payload['phoneNumber'] ?? null),
                'tax_number' => $payload['nationalId'] ?? '',
                'status' => 'Active',
            ],
        ];

        $response = $this->qoyod->createCustomer($body);

        $syncMap = $this->markTransferred($syncMap, $response, $hash);

        AuditLog::query()->create([
            'correlation_id' => $dentolizeId,
            'sync_map_id' => $syncMap->id,
            'action' => 'create_customer',
            'target_system' => 'Qoyod',
            'endpoint' => '/customers',
            'http_method' => 'POST',
            'request_body' => $body,
            'response_body' => $response,
            'response_code' => 201,
        ]);

        return $syncMap;
    }

    private function markTransferred(SyncMap $syncMap, array $response, string $hash): SyncMap
    {
        $syncMap->update([
            'qoyod_id' => (string) $response['id'],
            'status' => $syncMap->status === 'failed' ? 'fixed' : 'transferred',
            'rejected_by' => null,
            'last_error' => null,
            'attempts' => $syncMap->attempts + 1,
            'payload_hash' => $hash,
            'last_attempt_at' => now(),
            'synced_at' => now(),
        ]);

        return $syncMap->fresh();
    }
}
