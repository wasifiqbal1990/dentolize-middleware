<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WhisperSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_whisper_recovery_tables_exist(): void
    {
        foreach ([
            'inboxes',
            'sync_maps',
            'audit_logs',
            'dentolize_mirrors',
            'sync_checkpoints',
            'admin_users',
            'admin_access_logs',
            'fake_qoyod_records',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }
    }

    public function test_sync_map_is_unique_per_source_entity(): void
    {
        $now = now();

        \DB::table('sync_maps')->insert([
            'entity_type' => 'invoice',
            'dentolize_id' => '21038',
            'qoyod_reference' => 'DENTO-INV-21038',
            'status' => 'transferred',
            'first_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        \DB::table('sync_maps')->insert([
            'entity_type' => 'invoice',
            'dentolize_id' => '21038',
            'qoyod_reference' => 'DENTO-INV-21038',
            'status' => 'pending',
            'first_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
