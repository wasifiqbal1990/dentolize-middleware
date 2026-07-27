<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboxEvent;
use App\Models\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DentolizeWebhookReprocessController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! hash_equals((string) config('whisper.webhook_verify_token'), $this->verificationToken($request))) {
            return response()->json(['message' => 'Invalid verify token'], 401);
        }

        $statuses = collect(explode(',', (string) $request->query('statuses', 'received,failed')))
            ->map(fn (string $status): string => trim($status))
            ->filter()
            ->values();
        $limit = max(1, min((int) $request->query('limit', 50), 200));

        $inboxes = Inbox::query()
            ->whereIn('processing_status', $statuses)
            ->oldest('id')
            ->limit($limit)
            ->get();

        foreach ($inboxes as $inbox) {
            ProcessInboxEvent::dispatchSync($inbox->id);
        }

        return response()->json([
            'status' => 'reprocessed',
            'requested_statuses' => $statuses,
            'processed_count' => $inboxes->count(),
            'processed_inbox_ids' => $inboxes->pluck('id'),
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
}
