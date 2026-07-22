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
        $payload = $request->json()->all();
        $provided = $this->verificationToken($request, $payload);

        if (! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid verify token'], 401);
        }

        $payload = $this->withoutVerifyToken($payload);
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

    private function verificationToken(Request $request, array $payload): string
    {
        foreach (['X-Dentolize-Verify-Token', 'X-Verify-Token', 'Verify-Token'] as $header) {
            $token = (string) $request->header($header, '');

            if ($token !== '') {
                return $token;
            }
        }

        foreach (['verify_token', 'verifyToken', 'token'] as $key) {
            $token = (string) ($request->query($key) ?? $payload[$key] ?? '');

            if ($token !== '') {
                return $token;
            }
        }

        return '';
    }

    private function withoutVerifyToken(array $payload): array
    {
        unset($payload['verify_token'], $payload['verifyToken'], $payload['token']);

        return $payload;
    }
}
