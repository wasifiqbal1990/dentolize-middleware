<?php

namespace App\Sync\Clients;

interface QoyodClient
{
    public function findByReference(string $recordType, string $reference): ?array;

    public function createCustomer(array $payload): array;

    public function createInvoice(array $payload): array;

    public function createInvoicePayment(array $payload): array;

    public function readInvoice(string $qoyodId): ?array;
}
