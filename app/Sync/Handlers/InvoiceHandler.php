<?php

namespace App\Sync\Handlers;

use App\Models\AuditLog;
use App\Models\SyncMap;
use App\Support\Money;
use App\Support\ReferenceBuilder;
use App\Sync\Clients\QoyodClient;
use Carbon\CarbonImmutable;

class InvoiceHandler
{
    public function __construct(
        private readonly QoyodClient $qoyod,
        private readonly PatientHandler $patientHandler,
    ) {}

    public function handle(array $payload): SyncMap
    {
        $dentolizeId = (string) $payload['id'];
        $invoiceNumber = ltrim((string) ($payload['invoiceId'] ?? $dentolizeId), '#');
        $reference = ReferenceBuilder::for('invoice', $invoiceNumber);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $syncMap = SyncMap::query()->firstOrCreate(
            ['entity_type' => 'invoice', 'dentolize_id' => $dentolizeId],
            [
                'dentolize_number' => $invoiceNumber,
                'qoyod_reference' => $reference,
                'amount' => Money::normalize($payload['total'] ?? null),
                'status' => 'pending',
                'payload_hash' => $hash,
                'first_seen_at' => now(),
            ],
        );

        if ($syncMap->status === 'transferred' && $syncMap->payload_hash === $hash) {
            return $syncMap;
        }

        $patientMap = $this->patientHandler->handle($payload['patient']);

        if ($existing = $this->qoyod->findByReference('invoice', $reference)) {
            return $this->markTransferred($syncMap, $existing, $hash);
        }

        $issueDate = CarbonImmutable::parse($payload['createdAt'] ?? now())->toDateString();
        $body = [
            'invoice' => [
                'contact_id' => $patientMap->qoyod_id,
                'reference' => $reference,
                'description' => 'Dentolize invoice #'.$invoiceNumber,
                'issue_date' => $issueDate,
                'due_date' => $issueDate,
                'status' => 'Draft',
                'inventory_id' => (string) config('whisper.default_inventory_id'),
                'line_items' => [[
                    'product_id' => (string) config('whisper.qoyod_generic_product_id'),
                    'description' => 'Dental Services',
                    'quantity' => '1.0',
                    'unit_price' => Money::normalize($payload['subtotal'] ?? $payload['total'] ?? null),
                    'discount' => Money::normalize($payload['discount'] ?? null),
                    'discount_type' => 'amount',
                    'tax_percent' => (string) ($payload['taxPercent'] ?? config('whisper.vat_rate')),
                ]],
                'custom_fields' => [
                    'customfield1' => 'dentolize_invoice_id:'.$dentolizeId,
                ],
            ],
        ];

        $response = $this->qoyod->createInvoice($body);
        $syncMap = $this->markTransferred($syncMap, $response, $hash);

        AuditLog::query()->create([
            'correlation_id' => $dentolizeId,
            'sync_map_id' => $syncMap->id,
            'action' => 'create_invoice',
            'target_system' => 'Qoyod',
            'endpoint' => '/invoices',
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
