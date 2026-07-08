<?php

namespace App\Sync\Handlers;

use App\Models\AuditLog;
use App\Models\SyncMap;
use App\Support\Money;
use App\Support\ReferenceBuilder;
use App\Sync\Clients\QoyodClient;

class PaymentHandler
{
    public function __construct(private readonly QoyodClient $qoyod) {}

    public function handle(array $payload): SyncMap
    {
        $dentolizeId = (string) $payload['id'];
        $reference = ReferenceBuilder::for('payment', $dentolizeId);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $syncMap = SyncMap::query()->firstOrCreate(
            ['entity_type' => 'payment', 'dentolize_id' => $dentolizeId],
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

        $invoiceDentolizeId = (string) ($payload['invoice']['id'] ?? '');
        $invoiceMap = SyncMap::query()
            ->where('entity_type', 'invoice')
            ->where('dentolize_id', $invoiceDentolizeId)
            ->whereIn('status', ['transferred', 'fixed'])
            ->first();

        if ($invoiceMap === null) {
            $syncMap->update([
                'status' => 'pending',
                'rejected_by' => 'Whisper',
                'last_error' => 'invoice dependency missing for payment '.$dentolizeId,
                'attempts' => $syncMap->attempts + 1,
                'last_attempt_at' => now(),
            ]);

            return $syncMap->fresh();
        }

        if ($existing = $this->qoyod->findByReference('payment', $reference)) {
            return $this->markTransferred($syncMap, $existing, $hash);
        }

        $body = [
            'invoice_payment' => [
                'reference' => $reference,
                'invoice_id' => $invoiceMap->qoyod_id,
                'account_id' => (string) config('whisper.default_account_id'),
                'date' => $payload['date'] ?? now()->toDateString(),
                'amount' => Money::normalize($payload['amount'] ?? null),
            ],
        ];

        $response = $this->qoyod->createInvoicePayment($body);
        $syncMap = $this->markTransferred($syncMap, $response, $hash);

        AuditLog::query()->create([
            'correlation_id' => $dentolizeId,
            'sync_map_id' => $syncMap->id,
            'action' => 'create_invoice_payment',
            'target_system' => 'Qoyod',
            'endpoint' => '/invoice_payments',
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
