<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboxEvent;
use App\Models\Inbox;
use App\Models\WebhookAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DentolizeWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('whisper.webhook_verify_token');
        $payload = $this->payload($request);
        $provided = $this->verificationToken($request, $payload);
        $tokenValid = hash_equals($expected, $provided);
        $eventId = $payload['event_id'] ?? $payload['eventId'] ?? null;
        $eventType = $payload['event_type'] ?? $payload['eventType'] ?? $payload['type'] ?? $payload['api'] ?? null;

        $attempt = WebhookAttempt::query()->create([
            'source' => 'dentolize',
            'http_method' => $request->method(),
            'path' => $request->path(),
            'content_type' => $request->header('Content-Type'),
            'user_agent' => $request->userAgent(),
            'event_id' => $eventId,
            'event_type' => $eventType,
            'verify_token_present' => $provided !== '',
            'verify_token_valid' => $tokenValid,
            'result' => $tokenValid ? 'accepted' : 'rejected_invalid_token',
            'payload_keys' => array_slice(array_keys($payload), 0, 20),
            'received_at' => now(),
        ]);

        if (! $tokenValid) {
            return response()->json(['message' => 'Invalid verify token'], 401);
        }

        $payload = $this->withoutVerifyToken($payload);
        $eventId = $eventId ?? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $eventType = $eventType ?? 'Unknown';

        $inbox = Inbox::query()->firstOrCreate(
            ['dentolize_event_id' => $eventId],
            [
                'event_type' => $eventType,
                'raw_payload' => $payload,
                'headers' => [
                    'verify_token_valid' => true,
                    'content_type' => $request->header('Content-Type'),
                    'payload_keys' => array_slice(array_keys($payload), 0, 20),
                    'user_agent' => $request->userAgent(),
                ],
                'received_at' => now(),
            ],
        );

        $attempt->update(['result' => $inbox->wasRecentlyCreated ? 'accepted' : 'duplicate']);

        if ($inbox->wasRecentlyCreated) {
            if (config('whisper.webhook_processing') === 'queue') {
                ProcessInboxEvent::dispatch($inbox->id);
            } else {
                ProcessInboxEvent::dispatchSync($inbox->id);
            }
        }

        return response()->json([
            'status' => $inbox->wasRecentlyCreated ? 'received' : 'duplicate',
            'inbox_id' => $inbox->id,
        ]);
    }

    private function payload(Request $request): array
    {
        $jsonPayload = $request->json()->all();

        if ($jsonPayload !== []) {
            return $jsonPayload;
        }

        $formPayload = $request->all();

        if ($formPayload !== []) {
            return $formPayload;
        }

        $rawPayload = trim($request->getContent());

        if ($rawPayload === '' || ! in_array($rawPayload[0], ['{', '['], true)) {
            return [];
        }

        $decoded = json_decode($rawPayload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function verificationToken(Request $request, array $payload): string
    {
        foreach (['X-Dentolize-Verify-Token', 'X-Verify-Token', 'Verify-Token'] as $header) {
            $token = (string) $request->header($header, '');

            if ($token !== '') {
                return $token;
            }
        }

        foreach (['verify_token', 'verifyToken', 'verify-token', 'token'] as $key) {
            $token = (string) ($request->query($key) ?? $payload[$key] ?? '');

            if ($token !== '') {
                return $token;
            }
        }

        return '';
    }

    private function withoutVerifyToken(array $payload): array
    {
        unset($payload['verify_token'], $payload['verifyToken'], $payload['verify-token'], $payload['token']);

        return $payload;
    }
}
