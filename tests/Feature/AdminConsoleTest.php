<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\DentolizeMirror;
use App\Models\SyncMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_requires_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->getJson('/admin/summary')->assertUnauthorized();
    }

    public function test_admin_can_login_and_view_summary(): void
    {
        $this->createAdmin('admin');
        SyncMap::query()->create([
            'entity_type' => 'invoice',
            'dentolize_id' => 'invoice-1',
            'qoyod_reference' => 'DENTO-INV-21038',
            'amount' => '286.35',
            'status' => 'transferred',
            'first_seen_at' => now(),
        ]);

        $this->post('/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertRedirect('/admin');

        $this->getJson('/admin/summary')
            ->assertOk()
            ->assertJsonPath('counts.transferred', 1)
            ->assertJsonPath('totals.dentolize', '286.35');
    }

    public function test_viewer_cannot_trigger_reconciliation(): void
    {
        $this->actingAsAdmin('viewer');

        $this->postJson('/admin/reconcile/run')->assertForbidden();
    }

    public function test_operator_can_run_reconciliation_and_filter_attention_items(): void
    {
        $this->actingAsAdmin('operator');

        $this->postJson('/admin/reconcile/run')
            ->assertOk()
            ->assertJsonPath('pushed', 3);

        $this->getJson('/admin/items?needs_attention=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_operator_can_retry_failed_item_from_mirror_payload(): void
    {
        $this->actingAsAdmin('operator');

        $syncMap = SyncMap::query()->create([
            'entity_type' => 'patient',
            'dentolize_id' => 'patient-1',
            'qoyod_reference' => 'DENTO-CUST-patient-1',
            'status' => 'failed',
            'rejected_by' => 'Qoyod',
            'last_error' => 'temporary validation failure',
            'first_seen_at' => now(),
        ]);

        DentolizeMirror::query()->create([
            'entity_type' => 'patient',
            'dentolize_id' => 'patient-1',
            'raw' => [
                'id' => 'patient-1',
                'firstName' => 'Sara',
                'lastName' => 'Patient',
                'phoneNumber' => '051 234 5678',
            ],
            'payload_hash' => 'old',
            'pulled_at' => now(),
        ]);

        $this->postJson("/admin/items/{$syncMap->id}/retry")
            ->assertOk()
            ->assertJsonPath('status', 'fixed');
    }

    private function actingAsAdmin(string $role): AdminUser
    {
        $admin = $this->createAdmin($role);
        $this->withSession(['admin_user_id' => $admin->id]);

        return $admin;
    }

    private function createAdmin(string $role): AdminUser
    {
        return AdminUser::query()->create([
            'email' => 'admin@example.com',
            'password_hash' => Hash::make('password'),
            'role' => $role,
        ]);
    }
}
