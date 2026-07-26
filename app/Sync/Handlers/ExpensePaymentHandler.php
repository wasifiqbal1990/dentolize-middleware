<?php

namespace App\Sync\Handlers;

use App\Models\AuditLog;
use App\Models\SyncMap;
use App\Support\Money;
use App\Support\ReferenceBuilder;
use App\Sync\Clients\QoyodClient;
use Carbon\CarbonImmutable;

class ExpensePaymentHandler
{
    public function __construct(private readonly QoyodClient $qoyod) {}

    public function handle(array $payload): SyncMap
    {
        $dentolizeId = (string) $payload['id'];
        $reference = ReferenceBuilder::for('expense_payment', $dentolizeId);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $syncMap = SyncMap::query()->firstOrCreate(
            ['entity_type' => 'expense_payment', 'dentolize_id' => $dentolizeId],
            [
                'qoyod_reference' => $reference,
                'amount' => Money::normalize($payload['amount'] ?? null),
                'status' => 'pending',
                'payload_hash' => $hash,
                'first_seen_at' => now(),
            ],
        );

        if ($syncMap->status === 'transferred' && $syncMap->payload_hash === $hash) {
            return $syncMap;
        }

        $expenseDentolizeId = (string) ($payload['expense']['id'] ?? $payload['expenseId'] ?? '');
        $expenseMap = SyncMap::query()
            ->where('entity_type', 'expense')
            ->where('dentolize_id', $expenseDentolizeId)
            ->whereIn('status', ['transferred', 'fixed'])
            ->first();

        if ($expenseMap === null) {
            $syncMap->update([
                'status' => 'pending',
                'rejected_by' => 'Whisper',
                'last_error' => 'expense dependency missing for expense payment '.$dentolizeId,
                'attempts' => $syncMap->attempts + 1,
                'last_attempt_at' => now(),
            ]);

            return $syncMap->fresh();
        }

        if ($existing = $this->qoyod->findByReference('expense_payment', $reference)) {
            return $this->markTransferred($syncMap, $existing, $hash);
        }

        $body = [
            'simple_bill_payment' => [
                'reference' => $reference,
                'simple_bill_id' => $expenseMap->qoyod_id,
                'account_id' => (string) config('whisper.default_account_id'),
                'date' => CarbonImmutable::parse($payload['date'] ?? $payload['createdAt'] ?? now())->toDateString(),
                'amount' => Money::normalize($payload['amount'] ?? null),
            ],
        ];

        $response = $this->qoyod->createSimpleBillPayment($body);
        $syncMap = $this->markTransferred($syncMap, $response, $hash);

        AuditLog::query()->create([
            'correlation_id' => $dentolizeId,
            'sync_map_id' => $syncMap->id,
            'action' => 'create_simple_bill_payment',
            'target_system' => 'Qoyod',
            'endpoint' => '/simple_bill_payments',
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
