<?php

namespace App\Sync\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LiveQoyodClient implements QoyodClient
{
    public function findByReference(string $recordType, string $reference): ?array
    {
        throw new RuntimeException('Live reference lookup is not implemented for '.$recordType.'.');
    }

    public function createCustomer(array $payload): array
    {
        $response = $this->http()
            ->post('customers', $this->withoutLocalReference($payload));

        if ($response->failed()) {
            throw new RuntimeException('Qoyod customer creation failed with HTTP '.$response->status().'.');
        }

        return $this->normalizeResponse($response->json(), $response->status());
    }

    public function deleteCustomer(string $qoyodId): array
    {
        $response = $this->http()->delete('customers/'.$qoyodId);

        if ($response->failed()) {
            throw new RuntimeException('Qoyod customer deletion failed with HTTP '.$response->status().'.');
        }

        return [
            'id' => $qoyodId,
            'status_code' => $response->status(),
            'payload' => $response->json() ?? [],
        ];
    }

    public function deactivateCustomer(string $qoyodId): array
    {
        $response = $this->http()->put('customers/'.$qoyodId, [
            'contact' => [
                'status' => 'Inactive',
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Qoyod customer deactivation failed with HTTP '.$response->status().'.');
        }

        return [
            'id' => $qoyodId,
            'status_code' => $response->status(),
            'payload' => $response->json() ?? [],
        ];
    }

    public function createInvoice(array $payload): array
    {
        throw new RuntimeException('Live invoice creation is disabled until ZATCA behavior is resolved.');
    }

    public function createInvoicePayment(array $payload): array
    {
        throw new RuntimeException('Live payment creation is disabled until invoice sync is approved.');
    }

    public function readInvoice(string $qoyodId): ?array
    {
        throw new RuntimeException('Live invoice read is not implemented yet.');
    }

    private function http(): PendingRequest
    {
        $apiKey = (string) config('whisper.qoyod_api_key');

        if ($apiKey === '') {
            throw new RuntimeException('QOYOD_API_KEY is missing.');
        }

        return Http::baseUrl(rtrim((string) config('whisper.qoyod_base_url'), '/').'/')
            ->acceptJson()
            ->asJson()
            ->withHeaders(['API-KEY' => $apiKey])
            ->timeout(20);
    }

    private function withoutLocalReference(array $payload): array
    {
        unset($payload['reference']);

        return $payload;
    }

    private function normalizeResponse(array $payload, int $status): array
    {
        $contact = $payload['contact'] ?? $payload['customer'] ?? $payload;
        $id = $contact['id'] ?? $payload['id'] ?? null;

        if ($id === null) {
            throw new RuntimeException('Qoyod response did not include a contact id.');
        }

        return [
            'id' => (string) $id,
            'status_code' => $status,
            'payload' => $payload,
        ];
    }
}
