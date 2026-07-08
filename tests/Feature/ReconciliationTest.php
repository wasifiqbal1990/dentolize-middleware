<?php

namespace Tests\Feature;

use App\Models\DentolizeMirror;
use App\Sync\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_mirrors_and_pushes_missing_records(): void
    {
        $result = app(ReconciliationService::class)->run();

        $this->assertGreaterThanOrEqual(3, $result['pushed']);
        $this->assertSame(0, $result['still_failing']);
        $this->assertGreaterThanOrEqual(3, DentolizeMirror::query()->count());
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'invoice', 'dentolize_id' => 'invoice-1', 'status' => 'transferred']);
        $this->assertDatabaseHas('sync_maps', ['entity_type' => 'payment', 'dentolize_id' => 'payment-1', 'status' => 'transferred']);
    }
}
