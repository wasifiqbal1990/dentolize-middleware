<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Sync\Clients\LiveDentolizeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveDentolizeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_dentolize_client_fetches_patients_from_search_api(): void
    {
        config([
            'whisper.dentolize_graphql_url' => 'https://api.dentolize.test/',
            'whisper.dentolize_session_cookie' => 'session-cookie',
        ]);

        Http::fake([
            'https://api.dentolize.test/' => Http::response([
                'data' => [
                    '11111111-1111-4111-8111-111111111111', 'Wasif', 'Iqbal', '+966_500000001', 6599, 7,
                    '22222222-2222-4222-8222-222222222222', 'Sara', '+966_500000002', 6598, 7,
                ],
            ], 200),
        ]);

        $patients = app(LiveDentolizeClient::class)->fetchPatients(2);

        $this->assertCount(2, $patients);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $patients[0]['id']);
        $this->assertSame('Wasif', $patients[0]['firstName']);
        $this->assertSame('Iqbal', $patients[0]['lastName']);
        $this->assertSame('+966_500000001', $patients[0]['phoneNumber']);
        $this->assertSame('6599', $patients[0]['patientId']);
        $this->assertSame('Sara', $patients[1]['firstName']);
        $this->assertSame('', $patients[1]['lastName']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.dentolize.test/'
                && $request->hasHeader('Cookie', 'session-cookie')
                && str_contains($request['query'], 'searchPatients');
        });
    }

    public function test_import_command_fetches_dentolize_patients_and_creates_qoyod_contacts(): void
    {
        config([
            'whisper.dentolize_graphql_url' => 'https://api.dentolize.test/',
            'whisper.dentolize_session_cookie' => 'session-cookie',
            'whisper.qoyod_base_url' => 'https://api.qoyod.test/2.0/',
            'whisper.qoyod_api_key' => 'test-key',
        ]);

        Http::fake([
            'https://api.dentolize.test/' => Http::response([
                'data' => [
                    '11111111-1111-4111-8111-111111111111', 'Wasif', 'Iqbal', '+966_500000001', 6599, 7,
                    '22222222-2222-4222-8222-222222222222', 'Sara', 'Patient', '+966_500000002', 6598, 7,
                ],
            ], 200),
            'https://api.qoyod.test/2.0/customers' => Http::sequence()
                ->push(['contact' => ['id' => 501]], 201)
                ->push(['contact' => ['id' => 502]], 201),
        ]);

        $this->artisan('whisper:import-dentolize-customers --limit=2')
            ->expectsOutputToContain('Imported 2 Dentolize customers into Qoyod')
            ->assertSuccessful();

        $this->assertSame(2, AuditLog::query()->where('action', 'import_dentolize_customer')->count());
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'patient',
            'dentolize_id' => '11111111-1111-4111-8111-111111111111',
            'qoyod_id' => '501',
            'status' => 'transferred',
        ]);
        $this->assertDatabaseHas('sync_maps', [
            'entity_type' => 'patient',
            'dentolize_id' => '22222222-2222-4222-8222-222222222222',
            'qoyod_id' => '502',
            'status' => 'transferred',
        ]);
    }

    public function test_import_command_refuses_to_run_without_dentolize_cookie(): void
    {
        config(['whisper.dentolize_session_cookie' => '']);

        $this->artisan('whisper:import-dentolize-customers')
            ->expectsOutputToContain('DENTOLIZE_SESSION_COOKIE is missing')
            ->assertFailed();
    }
}
