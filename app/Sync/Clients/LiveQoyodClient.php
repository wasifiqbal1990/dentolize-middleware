<?php

namespace App\Sync\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
            throw new RuntimeException($this->failureMessage('Qoyod customer creation', $response));
        }

        return $this->normalizeResponse($response->json(), $response->status());
    }

    public function deleteCustomer(string $qoyodId): array
    {
        $response = $this->http()->delete('customers/'.$qoyodId);

        if ($response->failed()) {
            throw new RuntimeException($this->failureMessage('Qoyod customer deletion', $response));
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
            throw new RuntimeException($this->failureMessage('Qoyod customer deactivation', $response));
        }

        return [
            'id' => $qoyodId,
            'status_code' => $response->status(),
            'payload' => $response->json() ?? [],
        ];
    }

    public function markCustomerDeleted(string $qoyodId): array
    {
        $response = $this->http()->put('customers/'.$qoyodId, [
            'contact' => [
                'name' => 'DELETED TEST CONTACT '.$qoyodId,
                'organization' => '',
                'email' => '',
                'phone_number' => '',
                'tax_number' => '',
                'status' => 'Deleted',
                'pos' => false,
                'shipping_address' => [
                    'shipping_address' => '',
                    'shipping_city' => '',
                    'shipping_state' => '',
                    'shipping_zip' => '',
                    'shipping_country' => '',
                ],
                'billing_address' => [
                    'billing_address' => '',
                    'billing_city' => '',
                    'billing_state' => '',
                    'billing_zip' => '',
                    'billing_country' => '',
                    'building_number' => '',
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->failureMessage('Qoyod customer deleted-status update', $response));
        }

        return [
            'id' => $qoyodId,
            'status_code' => $response->status(),
            'payload' => $response->json() ?? [],
        ];
    }

    public function createInvoice(array $payload): array
    {
        $response = $this->http()->post('invoices', $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->failureMessage('Qoyod invoice creation', $response));
        }

        return $this->normalizeDocumentResponse($response->json(), $response->status(), 'invoice');
    }

    public function createInvoicePayment(array $payload): array
    {
        $response = $this->http()->post('invoice_payments', $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->failureMessage('Qoyod invoice payment creation', $response));
        }

        return $this->normalizeDocumentResponse($response->json(), $response->status(), 'invoice_payment');
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

    private function normalizeDocumentResponse(array $payload, int $status, string $key): array
    {
        $document = $payload[$key] ?? $payload;
        $id = $document['id'] ?? $payload['id'] ?? null;

        if ($id === null) {
            throw new RuntimeException('Qoyod response did not include a '.$key.' id.');
        }

        return [
            'id' => (string) $id,
            'status_code' => $status,
            'payload' => $payload,
        ];
    }

    private function failureMessage(string $action, Response $response): string
    {
        $body = $response->json() ?? $response->body();

        return $action.' failed with HTTP '.$response->status().': '.json_encode($body, JSON_UNESCAPED_SLASHES);
    }
}
