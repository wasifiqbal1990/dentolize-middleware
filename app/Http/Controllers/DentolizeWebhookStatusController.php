<?php

namespace App\Http\Controllers;

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

        return response()->json([
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
                        'status' => $map->status,
                        'last_error' => $this->truncate($map->last_error),
                        'attempts' => $map->attempts,
                        'last_attempt_at' => optional($map->last_attempt_at)->toIso8601String(),
                        'synced_at' => optional($map->synced_at)->toIso8601String(),
                    ]),
            ],
        ]);
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
}
