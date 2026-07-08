<?php

namespace Tests\Feature;

use App\Sync\Clients\FakeQoyodClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_qoyod_client_creates_and_finds_records_by_reference(): void
    {
        $client = app(FakeQoyodClient::class);

        $created = $client->createCustomer([
            'contact' => [
                'name' => 'Sara Patient',
                'phone_number' => '+966512345678',
                'status' => 'Active',
            ],
            'reference' => 'DENTO-CUST-patient-1',
        ]);

        $this->assertSame('DENTO-CUST-patient-1', $created['reference']);
        $this->assertSame($created['id'], $client->findByReference('customer', 'DENTO-CUST-patient-1')['id']);
        $this->assertNull($client->findByReference('customer', 'DENTO-CUST-missing'));
    }
}
