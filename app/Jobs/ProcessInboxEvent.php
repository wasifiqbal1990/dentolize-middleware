<?php

namespace App\Jobs;

use App\Models\Inbox;
use App\Sync\Handlers\InvoiceHandler;
use App\Sync\Handlers\PatientHandler;
use App\Sync\Handlers\PaymentHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboxEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $inboxId) {}

    public function handle(
        PatientHandler $patientHandler,
        InvoiceHandler $invoiceHandler,
        PaymentHandler $paymentHandler,
    ): void {
        $inbox = Inbox::query()->findOrFail($this->inboxId);
        $payload = $inbox->raw_payload;
        $data = $payload['data'] ?? $payload;

        $inbox->update(['processing_status' => 'processing']);

        match ($inbox->event_type) {
            'New Patient' => $patientHandler->handle($data),
            'New Invoice' => $invoiceHandler->handle($data),
            'New Payment' => $paymentHandler->handle($data),
            default => null,
        };

        $inbox->update([
            'processing_status' => in_array($inbox->event_type, ['New Patient', 'New Invoice', 'New Payment'], true) ? 'done' : 'skipped',
            'processed_at' => now(),
        ]);
    }
}
