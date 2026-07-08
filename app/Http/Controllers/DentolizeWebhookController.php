<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboxEvent;
use App\Models\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DentolizeWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('whisper.webhook_verify_token');
        $provided = (string) $request->header('X-Dentolize-Verify-Token', '');

        if (! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid verify token'], 401);
        }

        $payload = $request->json()->all();
        $eventId = $payload['event_id'] ?? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $eventType = $payload['event_type'] ?? 'Unknown';

        $inbox = Inbox::query()->firstOrCreate(
            ['dentolize_event_id' => $eventId],
            [
                'event_type' => $eventType,
                'raw_payload' => $payload,
                'headers' => [
                    'verify_token_valid' => true,
                    'user_agent' => $request->userAgent(),
                ],
                'received_at' => now(),
            ],
        );

        if ($inbox->wasRecentlyCreated) {
            ProcessInboxEvent::dispatch($inbox->id);
        }

        return response()->json([
            'status' => $inbox->wasRecentlyCreated ? 'received' : 'duplicate',
            'inbox_id' => $inbox->id,
        ]);
    }
}
