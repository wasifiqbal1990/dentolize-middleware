<?php

namespace App\Sync\Handlers;

use App\Models\SyncMap;

class CustomRecordHandler
{
    public function handle(array $payload, string $eventType): SyncMap
    {
        $dentolizeId = (string) ($payload['id'] ?? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)));
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return SyncMap::query()->updateOrCreate(
            ['entity_type' => 'custom_record', 'dentolize_id' => $dentolizeId],
            [
                'dentolize_number' => $eventType,
                'status' => 'skipped',
                'rejected_by' => 'Whisper',
                'last_error' => 'Captured non-accounting Dentolize custom record. No Qoyod write was attempted.',
                'payload_hash' => $hash,
                'first_seen_at' => now(),
                'last_attempt_at' => now(),
            ],
        );
    }
}
