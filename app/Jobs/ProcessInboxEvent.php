<?php

namespace App\Jobs;

use App\Models\Inbox;
use App\Sync\Handlers\InvoiceHandler;
use App\Sync\Handlers\PatientHandler;
use App\Sync\Handlers\PaymentHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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
        $eventType = $this->normalizeEventType($inbox->event_type);

        $inbox->update(['processing_status' => 'processing']);

        try {
            match ($eventType) {
                'New Patient' => $patientHandler->handle($data),
                'New Invoice' => $invoiceHandler->handle($data),
                'New Payment' => $paymentHandler->handle($data),
                default => null,
            };
        } catch (Throwable $exception) {
            report($exception);

            $inbox->update([
                'processing_status' => 'failed',
                'headers' => [
                    ...($inbox->headers ?? []),
                    'last_error' => $exception->getMessage(),
                ],
                'processed_at' => now(),
            ]);

            return;
        }

        $inbox->update([
            'processing_status' => in_array($eventType, ['New Patient', 'New Invoice', 'New Payment'], true) ? 'done' : 'skipped',
            'processed_at' => now(),
        ]);
    }

    private function normalizeEventType(string $eventType): string
    {
        return match ($eventType) {
            'مريض جديد', 'new_patient', 'patient.created' => 'New Patient',
            'فاتورة جديدة', 'new_invoice', 'invoice.created' => 'New Invoice',
            'دفعة جديدة', 'new_payment', 'payment.created' => 'New Payment',
            default => $eventType,
        };
    }
}
