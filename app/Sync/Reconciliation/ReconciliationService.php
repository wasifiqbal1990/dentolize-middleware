<?php

namespace App\Sync\Reconciliation;

use App\Models\DentolizeMirror;
use App\Models\SyncMap;
use App\Sync\Clients\DentolizeClient;
use App\Sync\Handlers\InvoiceHandler;
use App\Sync\Handlers\PatientHandler;
use App\Sync\Handlers\PaymentHandler;
use Carbon\CarbonImmutable;

class ReconciliationService
{
    public function __construct(
        private readonly DentolizeClient $dentolize,
        private readonly PatientHandler $patientHandler,
        private readonly InvoiceHandler $invoiceHandler,
        private readonly PaymentHandler $paymentHandler,
    ) {}

    public function run(): array
    {
        $pushed = 0;
        $healed = 0;
        $stillFailing = 0;

        foreach ($this->orderedRecords() as [$entityType, $record]) {
            $hash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
            $wasFailing = SyncMap::query()
                ->where('entity_type', $entityType)
                ->where('dentolize_id', $record['id'])
                ->where('status', 'failed')
                ->exists();

            DentolizeMirror::query()->updateOrCreate(
                ['entity_type' => $entityType, 'dentolize_id' => $record['id']],
                [
                    'dentolize_number' => $record['invoiceId'] ?? null,
                    'raw' => $record,
                    'source_created_at' => isset($record['createdAt']) ? CarbonImmutable::parse($record['createdAt']) : null,
                    'source_updated_at' => isset($record['updatedAt']) ? CarbonImmutable::parse($record['updatedAt']) : null,
                    'payload_hash' => $hash,
                    'pulled_at' => now(),
                ],
            );

            $syncMap = match ($entityType) {
                'patient' => $this->patientHandler->handle($record),
                'invoice' => $this->invoiceHandler->handle($record),
                'payment' => $this->paymentHandler->handle($record),
            };

            if (in_array($syncMap->status, ['transferred', 'fixed'], true)) {
                $pushed++;
                if ($wasFailing || $syncMap->status === 'fixed') {
                    $healed++;
                }
                DentolizeMirror::query()
                    ->where('entity_type', $entityType)
                    ->where('dentolize_id', $record['id'])
                    ->update(['synced_to_qoyod' => true]);
            } else {
                $stillFailing++;
            }
        }

        return [
            'healed' => $healed,
            'pushed' => $pushed,
            'still_failing' => $stillFailing,
        ];
    }

    private function orderedRecords(): array
    {
        return [
            ...array_map(fn (array $record): array => ['patient', $record], $this->dentolize->changedPatients()),
            ...array_map(fn (array $record): array => ['invoice', $record], $this->dentolize->changedInvoices()),
            ...array_map(fn (array $record): array => ['payment', $record], $this->dentolize->changedPayments()),
        ];
    }
}
