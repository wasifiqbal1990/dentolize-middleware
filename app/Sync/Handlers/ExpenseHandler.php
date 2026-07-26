<?php

namespace App\Sync\Handlers;

use App\Models\AuditLog;
use App\Models\SyncMap;
use App\Support\Money;
use App\Support\ReferenceBuilder;
use App\Sync\Clients\QoyodClient;
use Carbon\CarbonImmutable;

class ExpenseHandler
{
    public function __construct(private readonly QoyodClient $qoyod) {}

    public function handle(array $payload): SyncMap
    {
        $dentolizeId = (string) $payload['id'];
        $reference = ReferenceBuilder::for('expense', $dentolizeId);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $syncMap = SyncMap::query()->firstOrCreate(
            ['entity_type' => 'expense', 'dentolize_id' => $dentolizeId],
            [
                'qoyod_reference' => $reference,
                'amount' => Money::normalize($payload['total'] ?? $payload['amount'] ?? null),
                'status' => 'pending',
                'payload_hash' => $hash,
                'first_seen_at' => now(),
            ],
        );

        if ($syncMap->status === 'transferred' && $syncMap->payload_hash === $hash) {
            return $syncMap;
        }

        if ($existing = $this->qoyod->findByReference('expense', $reference)) {
            return $this->markTransferred($syncMap, $existing, $hash);
        }

        $body = $this->simpleBillPayload($payload, $reference);
        $response = $this->qoyod->createSimpleBill($body);
        $syncMap = $this->markTransferred($syncMap, $response, $hash);

        AuditLog::query()->create([
            'correlation_id' => $dentolizeId,
            'sync_map_id' => $syncMap->id,
            'action' => 'create_simple_bill',
            'target_system' => 'Qoyod',
            'endpoint' => '/simple_bills',
            'http_method' => 'POST',
            'request_body' => $body,
            'response_body' => $response,
            'response_code' => 201,
        ]);

        return $syncMap;
    }

    private function simpleBillPayload(array $payload, string $reference): array
    {
        $issueDate = CarbonImmutable::parse($payload['date'] ?? $payload['createdAt'] ?? now())->toDateString();
        $description = trim((string) ($payload['name'] ?? $payload['type'] ?? 'Dentolize expense')) ?: 'Dentolize expense';

        return [
            'simple_bill' => [
                'vendor_id' => (string) data_get($payload, 'supplier.qoyodVendorId', config('whisper.default_vendor_id')),
                'reference' => $reference,
                'description' => $description,
                'issue_date' => $issueDate,
                'status' => 'Approved',
                'line_items' => [[
                    'description' => $description,
                    'quantity' => (string) ($payload['quantity'] ?? '1'),
                    'unit_price' => Money::normalize($payload['unitPrice'] ?? $payload['amount'] ?? $payload['total'] ?? null),
                    'tax_percent' => (string) ($payload['taxPercent'] ?? config('whisper.vat_rate')),
                ]],
            ],
        ];
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
