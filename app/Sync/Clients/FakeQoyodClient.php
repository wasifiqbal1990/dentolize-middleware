<?php

namespace App\Sync\Clients;

use App\Models\FakeQoyodRecord;
use App\Support\Money;

class FakeQoyodClient implements QoyodClient
{
    public function findByReference(string $recordType, string $reference): ?array
    {
        $record = FakeQoyodRecord::query()
            ->where('record_type', $recordType)
            ->where('reference', $reference)
            ->first();

        if ($record === null) {
            return null;
        }

        return $record->payload + ['id' => (string) $record->id, 'reference' => $reference];
    }

    public function createCustomer(array $payload): array
    {
        return $this->create('customer', $payload['reference'], $payload);
    }

    public function createInvoice(array $payload): array
    {
        $invoice = $payload['invoice'] ?? [];
        $line = $invoice['line_items'][0] ?? [];
        $amount = Money::normalize($line['unit_price'] ?? '0');

        return $this->create('invoice', $invoice['reference'], $payload, $amount);
    }

    public function createInvoicePayment(array $payload): array
    {
        $payment = $payload['invoice_payment'] ?? [];

        return $this->create('payment', $payment['reference'], $payload, Money::normalize($payment['amount'] ?? '0'));
    }

    public function readInvoice(string $qoyodId): ?array
    {
        $record = FakeQoyodRecord::query()->whereKey($qoyodId)->where('record_type', 'invoice')->first();

        if ($record === null) {
            return null;
        }

        return $record->payload + ['id' => (string) $record->id];
    }

    private function create(string $recordType, string $reference, array $payload, ?string $amount = null): array
    {
        $record = FakeQoyodRecord::query()->firstOrCreate(
            ['record_type' => $recordType, 'reference' => $reference],
            ['payload' => $payload, 'amount' => $amount],
        );

        return $record->payload + ['id' => (string) $record->id, 'reference' => $reference];
    }
}
