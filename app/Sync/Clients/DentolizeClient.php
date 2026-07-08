<?php

namespace App\Sync\Clients;

interface DentolizeClient
{
    public function changedPatients(): array;

    public function changedInvoices(): array;

    public function changedPayments(): array;
}
