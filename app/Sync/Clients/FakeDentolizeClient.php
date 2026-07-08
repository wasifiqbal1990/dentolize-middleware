<?php

namespace App\Sync\Clients;

class FakeDentolizeClient implements DentolizeClient
{
    public function changedPatients(): array
    {
        return [[
            'id' => 'patient-1',
            'firstName' => 'Sara',
            'lastName' => 'Patient',
            'phoneNumber' => '051 234 5678',
            'nationalId' => '1234567890',
            'createdAt' => '2026-07-08T10:00:00+03:00',
            'updatedAt' => '2026-07-08T10:00:00+03:00',
        ]];
    }

    public function changedInvoices(): array
    {
        return [[
            'id' => 'invoice-1',
            'invoiceId' => '#21038',
            'patient' => $this->changedPatients()[0],
            'subtotal' => '249.00',
            'total' => '286.35',
            'taxPercent' => '15',
            'discount' => '0',
            'createdAt' => '2026-07-08T10:00:00+03:00',
            'updatedAt' => '2026-07-08T10:00:00+03:00',
            'branch' => ['id' => 'riyadh'],
        ]];
    }

    public function changedPayments(): array
    {
        return [[
            'id' => 'payment-1',
            'invoiceId' => '#21038',
            'invoice' => ['id' => 'invoice-1', 'invoiceId' => '#21038'],
            'amount' => '286.35',
            'date' => '2026-07-08',
            'treasury' => ['id' => 'cash'],
            'createdAt' => '2026-07-08T10:05:00+03:00',
            'updatedAt' => '2026-07-08T10:05:00+03:00',
        ]];
    }
}
