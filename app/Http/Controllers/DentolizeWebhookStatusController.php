<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Inbox;
use App\Models\SyncMap;
use App\Models\WebhookAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DentolizeWebhookStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals((string) config('whisper.webhook_verify_token'), $this->verificationToken($request))) {
            return response()->json(['message' => 'Invalid verify token'], 401);
        }

        $body = [
            'status' => 'ok',
            'app' => [
                'environment' => config('app.env'),
                'adapter_mode' => config('whisper.adapter_mode'),
                'webhook_processing' => config('whisper.webhook_processing'),
                'queue_connection' => config('queue.default'),
            ],
            'qoyod' => [
                'api_key_configured' => (string) config('whisper.qoyod_api_key') !== '',
                'generic_product_id' => config('whisper.qoyod_generic_product_id'),
                'invoice_status' => config('whisper.qoyod_invoice_status'),
                'default_inventory_id' => config('whisper.default_inventory_id'),
                'default_account_id' => config('whisper.default_account_id'),
                'default_vendor_id' => config('whisper.default_vendor_id'),
            ],
            'inboxes' => [
                'total' => Inbox::query()->count(),
                'by_status' => Inbox::query()
                    ->selectRaw('processing_status, count(*) as total')
                    ->groupBy('processing_status')
                    ->orderBy('processing_status')
                    ->pluck('total', 'processing_status'),
                'latest' => Inbox::query()
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (Inbox $inbox): array => [
                        'id' => $inbox->id,
                        'dentolize_event_id' => $inbox->dentolize_event_id,
                        'event_type' => $inbox->event_type,
                        'processing_status' => $inbox->processing_status,
                        'last_error' => $this->truncate($inbox->headers['last_error'] ?? null),
                        'received_at' => optional($inbox->received_at)->toIso8601String(),
                        'processed_at' => optional($inbox->processed_at)->toIso8601String(),
                    ]),
            ],
            'webhook_attempts' => [
                'total' => WebhookAttempt::query()->count(),
                'by_result' => WebhookAttempt::query()
                    ->selectRaw('result, count(*) as total')
                    ->groupBy('result')
                    ->orderBy('result')
                    ->pluck('total', 'result'),
                'latest' => WebhookAttempt::query()
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (WebhookAttempt $attempt): array => [
                        'id' => $attempt->id,
                        'event_id' => $attempt->event_id,
                        'event_type' => $attempt->event_type,
                        'content_type' => $attempt->content_type,
                        'verify_token_present' => $attempt->verify_token_present,
                        'verify_token_valid' => $attempt->verify_token_valid,
                        'result' => $attempt->result,
                        'payload_keys' => $attempt->payload_keys,
                        'received_at' => optional($attempt->received_at)->toIso8601String(),
                    ]),
            ],
            'sync_maps' => [
                'total' => SyncMap::query()->count(),
                'by_status' => SyncMap::query()
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->orderBy('status')
                    ->pluck('total', 'status'),
                'by_entity' => SyncMap::query()
                    ->selectRaw('entity_type, status, count(*) as total')
                    ->groupBy('entity_type', 'status')
                    ->orderBy('entity_type')
                    ->orderBy('status')
                    ->get()
                    ->map(fn (SyncMap $map): array => [
                        'entity_type' => $map->entity_type,
                        'status' => $map->status,
                        'total' => $map->total,
                    ]),
                'latest' => SyncMap::query()
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (SyncMap $map): array => [
                        'id' => $map->id,
                        'entity_type' => $map->entity_type,
                        'dentolize_id' => $map->dentolize_id,
                        'dentolize_number' => $map->dentolize_number,
                        'qoyod_id' => $map->qoyod_id,
                        'qoyod_reference' => $map->qoyod_reference,
                        'amount' => $map->amount,
                        'status' => $map->status,
                        'last_error' => $this->truncate($map->last_error),
                        'attempts' => $map->attempts,
                        'last_attempt_at' => optional($map->last_attempt_at)->toIso8601String(),
                        'synced_at' => optional($map->synced_at)->toIso8601String(),
                    ]),
            ],
        ];

        if ($request->filled('dentolize_id')) {
            $body['lookup'] = $this->lookup((string) $request->query('dentolize_id'));
        }

        if ($request->filled('patient_id')) {
            $body['lookup'] = $this->lookupPatient((string) $request->query('patient_id'));
        }

        if ($request->boolean('invoice_payload_audit')) {
            $body['invoice_payload_audit'] = $this->invoicePayloadAudit(
                max(1, min((int) $request->query('audit_limit', 25), 100)),
            );
        }

        return response()->json($body);
    }

    private function verificationToken(Request $request): string
    {
        foreach (['X-Dentolize-Verify-Token', 'X-Verify-Token', 'Verify-Token'] as $header) {
            $token = (string) $request->header($header, '');

            if ($token !== '') {
                return $token;
            }
        }

        foreach (['verify_token', 'verifyToken', 'token'] as $key) {
            $token = (string) $request->query($key, '');

            if ($token !== '') {
                return $token;
            }
        }

        return '';
    }

    private function truncate(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, 500);
    }

    private function lookup(string $dentolizeId): array
    {
        $syncMaps = SyncMap::query()
            ->where('dentolize_id', $dentolizeId)
            ->orWhere('dentolize_number', $dentolizeId)
            ->latest('id')
            ->limit(10)
            ->get();

        $inboxes = Inbox::query()
            ->where('dentolize_event_id', $dentolizeId)
            ->orWhere('raw_payload->id', $dentolizeId)
            ->orWhere('raw_payload->data->id', $dentolizeId)
            ->latest('id')
            ->limit(10)
            ->get();

        return [
            'dentolize_id' => $dentolizeId,
            'sync_maps' => $syncMaps->map(fn (SyncMap $map): array => [
                'id' => $map->id,
                'entity_type' => $map->entity_type,
                'dentolize_id' => $map->dentolize_id,
                'dentolize_number' => $map->dentolize_number,
                'qoyod_id' => $map->qoyod_id,
                'qoyod_reference' => $map->qoyod_reference,
                'amount' => $map->amount,
                'status' => $map->status,
                'last_error' => $this->truncate($map->last_error),
                'attempts' => $map->attempts,
                'last_attempt_at' => optional($map->last_attempt_at)->toIso8601String(),
                'synced_at' => optional($map->synced_at)->toIso8601String(),
                'latest_qoyod_request' => $this->latestQoyodRequest($map),
            ]),
            'inboxes' => $inboxes->map(fn (Inbox $inbox): array => [
                'id' => $inbox->id,
                'dentolize_event_id' => $inbox->dentolize_event_id,
                'event_type' => $inbox->event_type,
                'processing_status' => $inbox->processing_status,
                'last_error' => $this->truncate($inbox->headers['last_error'] ?? null),
                'payload' => $this->safePayloadSnapshot($inbox->raw_payload),
                'received_at' => optional($inbox->received_at)->toIso8601String(),
                'processed_at' => optional($inbox->processed_at)->toIso8601String(),
            ]),
        ];
    }

    private function lookupPatient(string $patientId): array
    {
        $inboxes = Inbox::query()
            ->where(function ($query) use ($patientId): void {
                $query
                    ->where('raw_payload->id', $patientId)
                    ->orWhere('raw_payload->data->id', $patientId)
                    ->orWhere('raw_payload->patient_id', $patientId)
                    ->orWhere('raw_payload->patientId', $patientId)
                    ->orWhere('raw_payload->data->patient_id', $patientId)
                    ->orWhere('raw_payload->data->patientId', $patientId);
            })
            ->latest('id')
            ->limit(25)
            ->get();

        $dentolizeIds = $inboxes
            ->map(fn (Inbox $inbox): ?string => $this->payloadDentolizeId($inbox->raw_payload))
            ->filter()
            ->push($patientId)
            ->unique()
            ->values();

        $syncMaps = SyncMap::query()
            ->whereIn('dentolize_id', $dentolizeIds)
            ->orWhere('dentolize_number', $patientId)
            ->latest('id')
            ->limit(25)
            ->get();

        return [
            'patient_id' => $patientId,
            'sync_maps' => $syncMaps->map(fn (SyncMap $map): array => [
                'id' => $map->id,
                'entity_type' => $map->entity_type,
                'dentolize_id' => $map->dentolize_id,
                'dentolize_number' => $map->dentolize_number,
                'qoyod_id' => $map->qoyod_id,
                'qoyod_reference' => $map->qoyod_reference,
                'amount' => $map->amount,
                'status' => $map->status,
                'last_error' => $this->truncate($map->last_error),
                'attempts' => $map->attempts,
                'last_attempt_at' => optional($map->last_attempt_at)->toIso8601String(),
                'synced_at' => optional($map->synced_at)->toIso8601String(),
                'latest_qoyod_request' => $this->latestQoyodRequest($map),
            ]),
            'inboxes' => $inboxes->map(fn (Inbox $inbox): array => [
                'id' => $inbox->id,
                'dentolize_event_id' => $inbox->dentolize_event_id,
                'event_type' => $inbox->event_type,
                'processing_status' => $inbox->processing_status,
                'last_error' => $this->truncate($inbox->headers['last_error'] ?? null),
                'payload' => $this->safePayloadSnapshot($inbox->raw_payload),
                'received_at' => optional($inbox->received_at)->toIso8601String(),
                'processed_at' => optional($inbox->processed_at)->toIso8601String(),
            ]),
        ];
    }

    private function latestQoyodRequest(SyncMap $map): ?array
    {
        $auditLog = AuditLog::query()
            ->where('sync_map_id', $map->id)
            ->latest('id')
            ->first();

        if ($auditLog === null) {
            return null;
        }

        return [
            'action' => $auditLog->action,
            'endpoint' => $auditLog->endpoint,
            'http_method' => $auditLog->http_method,
            'request' => $this->safeQoyodRequestSnapshot($auditLog->request_body ?? []),
            'response_code' => $auditLog->response_code,
        ];
    }

    private function safePayloadSnapshot(array $payload): array
    {
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;

        return array_filter([
            'type' => $payload['type'] ?? $payload['event_type'] ?? $payload['eventType'] ?? null,
            'id' => $data['id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? $data['invoiceId'] ?? null,
            'patient_id' => $data['patient_id'] ?? $data['patientId'] ?? null,
            'file_no' => $data['file_no'] ?? null,
            'reference_no' => $data['reference_no'] ?? null,
            'total' => $data['total'] ?? null,
            'subtotal' => $data['subtotal'] ?? null,
            'tax' => $data['tax'] ?? null,
            'discount' => $data['discount'] ?? null,
            'taxPercent' => $data['taxPercent'] ?? null,
            'amount' => $data['amount'] ?? null,
            'invoice_line_ids' => $data['invoice_line_ids'] ?? null,
        ], fn ($value): bool => $value !== null);
    }

    private function invoicePayloadAudit(int $limit): array
    {
        $inboxes = Inbox::query()
            ->whereIn('event_type', ['NEW_INVOICE', 'New Invoice', 'فاتورة جديدة'])
            ->latest('id')
            ->limit($limit)
            ->get();

        return [
            'limit' => $limit,
            'items' => $inboxes->map(function (Inbox $inbox): array {
                $data = isset($inbox->raw_payload['data']) && is_array($inbox->raw_payload['data'])
                    ? $inbox->raw_payload['data']
                    : $inbox->raw_payload;

                $paymentFields = $this->paymentCandidateFields($data);

                return [
                    'inbox_id' => $inbox->id,
                    'dentolize_event_id' => $inbox->dentolize_event_id,
                    'event_type' => $inbox->event_type,
                    'received_at' => optional($inbox->received_at)->toIso8601String(),
                    'invoice' => [
                        'id' => $data['id'] ?? null,
                        'patient_id' => $data['patient_id'] ?? $data['patientId'] ?? null,
                        'file_no' => $data['file_no'] ?? null,
                        'reference_no' => $data['reference_no'] ?? null,
                        'total' => $data['total'] ?? null,
                        'subtotal' => $data['subtotal'] ?? null,
                        'tax' => $data['tax'] ?? null,
                        'discount' => $data['discount'] ?? null,
                    ],
                    'top_level_keys' => array_values(array_keys($data)),
                    'payment_candidate_fields' => $paymentFields,
                    'has_payment_candidate_fields' => $paymentFields !== [],
                ];
            }),
        ];
    }

    private function paymentCandidateFields(array $payload, string $prefix = ''): array
    {
        $matches = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $matches = [
                    ...$matches,
                    ...$this->paymentCandidateFields($value, $path),
                ];

                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            if (! $this->isPaymentCandidateKey($path)) {
                continue;
            }

            $matches[$path] = $value;
        }

        return $matches;
    }

    private function isPaymentCandidateKey(string $path): bool
    {
        $key = strtolower(str_replace(['-', ' '], '_', $path));

        foreach ([
            'paid',
            'payment',
            'receipt',
            'remaining',
            'balance',
            'due',
            'outstanding',
            'collected',
            'received',
            'cash',
            'card',
            'method',
            'status',
        ] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function payloadDentolizeId(array $payload): ?string
    {
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
        $id = $data['id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    private function safeQoyodRequestSnapshot(array $request): array
    {
        $invoice = $request['invoice'] ?? null;

        if (is_array($invoice)) {
            $line = $invoice['line_items'][0] ?? [];

            return [
                'reference' => $invoice['reference'] ?? null,
                'status' => $invoice['status'] ?? null,
                'inventory_id' => $invoice['inventory_id'] ?? null,
                'line_item' => [
                    'product_id' => $line['product_id'] ?? null,
                    'description' => $line['description'] ?? null,
                    'quantity' => $line['quantity'] ?? null,
                    'unit_price' => $line['unit_price'] ?? null,
                    'discount' => $line['discount'] ?? null,
                    'tax_percent' => $line['tax_percent'] ?? null,
                ],
            ];
        }

        $payment = $request['invoice_payment'] ?? null;

        if (is_array($payment)) {
            return [
                'reference' => $payment['reference'] ?? null,
                'invoice_id' => $payment['invoice_id'] ?? null,
                'account_id' => $payment['account_id'] ?? null,
                'amount' => $payment['amount'] ?? null,
            ];
        }

        return [];
    }
}
