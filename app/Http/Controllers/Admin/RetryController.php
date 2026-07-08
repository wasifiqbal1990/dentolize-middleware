<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DentolizeMirror;
use App\Models\SyncMap;
use App\Sync\Handlers\InvoiceHandler;
use App\Sync\Handlers\PatientHandler;
use App\Sync\Handlers\PaymentHandler;
use Illuminate\Http\JsonResponse;

class RetryController extends Controller
{
    public function __invoke(
        SyncMap $syncMap,
        PatientHandler $patientHandler,
        InvoiceHandler $invoiceHandler,
        PaymentHandler $paymentHandler,
    ): JsonResponse {
        $mirror = DentolizeMirror::query()
            ->where('entity_type', $syncMap->entity_type)
            ->where('dentolize_id', $syncMap->dentolize_id)
            ->firstOrFail();

        $updated = match ($syncMap->entity_type) {
            'patient' => $patientHandler->handle($mirror->raw),
            'invoice' => $invoiceHandler->handle($mirror->raw),
            'payment' => $paymentHandler->handle($mirror->raw),
            default => abort(422, 'Unsupported retry entity type.'),
        };

        return response()->json($updated->fresh());
    }
}
