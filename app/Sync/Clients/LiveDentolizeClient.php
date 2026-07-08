<?php

namespace App\Sync\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LiveDentolizeClient
{
    public function fetchPatients(int $limit = 5): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new RuntimeException('Dentolize patient import limit must be between 1 and 50.');
        }

        $query = <<<'GRAPHQL'
query {
  searchPatients(searchTerm: "") {
    id
    firstName
    lastName
    phoneNumber
    patientId
    referenceId
    nationalId
  }
}
GRAPHQL;

        $response = $this->http()->post('', ['query' => $query]);

        if ($response->failed()) {
            throw new RuntimeException('Dentolize patient fetch failed with HTTP '.$response->status().'.');
        }

        if ($response->json('errors')) {
            throw new RuntimeException('Dentolize patient fetch returned GraphQL errors.');
        }

        return array_slice($this->parseFlatSearchPatients($response->json('data') ?? []), 0, $limit);
    }

    public function fetchPatientFlow(string $patientId): array
    {
        $details = $this->postGraphql(<<<GRAPHQL
query {
  patientDetails(patient: "{$patientId}") {
    id
    firstName
    lastName
    phoneNumber
    patientId
    doctor { id name }
  }
}
GRAPHQL);

        $invoices = $this->postGraphql(<<<GRAPHQL
query {
  invoices(orderBy: "createdAt:desc", skip: 0, take: 5, rangeDate: ["2000-01-01T00:00:00.000Z", "2035-12-31T23:59:59.999Z"], patient: "{$patientId}") {
    id
    invoiceId
    subtotal
    total
    taxPercent
    createdAt
  }
}
GRAPHQL);

        $payments = $this->postGraphql(<<<GRAPHQL
query {
  payments(orderBy: "createdAt:desc", skip: 0, take: 5, rangeDate: ["2000-01-01T00:00:00.000Z", "2035-12-31T23:59:59.999Z"], patient: "{$patientId}") {
    id
    amount
    createdAt
    invoice { invoiceId }
  }
}
GRAPHQL);

        return [
            'patient' => $this->mapPatientDetails($details),
            'invoices' => $this->mapInvoices($invoices),
            'payments' => $this->mapPayments($payments),
        ];
    }

    private function http(): PendingRequest
    {
        $cookie = (string) config('whisper.dentolize_session_cookie');

        if ($cookie === '') {
            throw new RuntimeException('DENTOLIZE_SESSION_COOKIE is missing.');
        }

        return Http::baseUrl(rtrim((string) config('whisper.dentolize_graphql_url'), '/').'/')
            ->acceptJson()
            ->asJson()
            ->withHeaders(['Cookie' => $cookie])
            ->timeout(30);
    }

    private function postGraphql(string $query): array
    {
        $response = $this->http()->post('', ['query' => $query]);

        if ($response->failed()) {
            throw new RuntimeException('Dentolize GraphQL request failed with HTTP '.$response->status().'.');
        }

        if ($response->json('errors')) {
            throw new RuntimeException('Dentolize GraphQL request returned errors.');
        }

        return $response->json('data') ?? [];
    }

    private function parseFlatSearchPatients(array $flat): array
    {
        $rows = [];
        $current = [];

        foreach ($flat as $value) {
            if (is_string($value) && $this->looksLikeUuid($value)) {
                if ($current !== []) {
                    $rows[] = $this->mapFlatPatientRow($current);
                }

                $current = [$value];

                continue;
            }

            if ($current !== []) {
                $current[] = $value;
            }
        }

        if ($current !== []) {
            $rows[] = $this->mapFlatPatientRow($current);
        }

        return array_values(array_filter($rows, fn (array $row): bool => $row['id'] !== ''));
    }

    private function mapFlatPatientRow(array $row): array
    {
        $id = (string) array_shift($row);
        $nameParts = [];
        $phone = '';
        $patientId = '';

        foreach ($row as $value) {
            if ($phone === '' && is_string($value) && preg_match('/^\+?\d[\d_\-\s]+$/', $value)) {
                $phone = $value;

                continue;
            }

            if ($patientId === '' && (is_int($value) || ctype_digit((string) $value)) && (int) $value >= 100) {
                $patientId = (string) $value;

                continue;
            }

            if ($phone === '' && $patientId === '' && is_string($value) && trim($value) !== '') {
                $nameParts[] = trim($value);
            }
        }

        return [
            'id' => $id,
            'firstName' => $nameParts[0] ?? 'Dentolize',
            'lastName' => implode(' ', array_slice($nameParts, 1)),
            'phoneNumber' => $phone,
            'patientId' => $patientId,
            'referenceId' => null,
            'nationalId' => null,
        ];
    }

    private function mapPatientDetails(array $flat): array
    {
        $nested = $this->firstNestedRecord($flat, 'id');

        if ($nested !== null) {
            return [
                'id' => (string) ($nested['id'] ?? ''),
                'firstName' => (string) ($nested['firstName'] ?? 'Dentolize'),
                'lastName' => (string) ($nested['lastName'] ?? ''),
                'phoneNumber' => (string) ($nested['phoneNumber'] ?? ''),
                'patientId' => (string) ($nested['patientId'] ?? ''),
                'doctorId' => (string) data_get($nested, 'doctor.id', ''),
                'doctorName' => (string) data_get($nested, 'doctor.name', ''),
            ];
        }

        return [
            'id' => (string) ($flat[0] ?? ''),
            'firstName' => (string) ($flat[1] ?? 'Dentolize'),
            'lastName' => (string) ($flat[2] ?? ''),
            'phoneNumber' => (string) ($flat[3] ?? ''),
            'patientId' => (string) ($flat[4] ?? ''),
            'doctorId' => (string) ($flat[5] ?? ''),
            'doctorName' => (string) ($flat[6] ?? ''),
        ];
    }

    private function mapInvoices(array $flat): array
    {
        $nested = $this->nestedRecords($flat, ['id', 'invoiceId']);

        if ($nested !== []) {
            return array_map(fn (array $invoice): array => [
                'id' => (string) ($invoice['id'] ?? ''),
                'invoiceId' => (string) ($invoice['invoiceId'] ?? ''),
                'subtotal' => (string) ($invoice['subtotal'] ?? '0'),
                'total' => (string) ($invoice['total'] ?? '0'),
                'taxPercent' => (string) ($invoice['taxPercent'] ?? '0'),
                'createdAt' => (string) ($invoice['createdAt'] ?? now()->toISOString()),
            ], $nested);
        }

        return $this->mapFixedRows($flat, 6, ['id', 'invoiceId', 'subtotal', 'total', 'taxPercent', 'createdAt']);
    }

    private function mapPayments(array $flat): array
    {
        $nested = $this->nestedRecords($flat, ['id', 'amount']);

        if ($nested !== []) {
            return array_map(fn (array $payment): array => [
                'id' => (string) ($payment['id'] ?? ''),
                'amount' => (string) ($payment['amount'] ?? '0'),
                'createdAt' => (string) ($payment['createdAt'] ?? now()->toISOString()),
                'invoiceId' => (string) data_get($payment, 'invoice.invoiceId', ''),
            ], $nested);
        }

        return $this->mapFixedRows($flat, 4, ['id', 'amount', 'createdAt', 'invoiceId']);
    }

    private function firstNestedRecord(array $flat, string $requiredKey): ?array
    {
        foreach ($flat as $value) {
            if (is_array($value) && array_key_exists($requiredKey, $value)) {
                return $this->dereferenceNestedRecord($value, $flat);
            }
        }

        return null;
    }

    private function nestedRecords(array $flat, array $requiredKeys): array
    {
        $records = array_values(array_filter($flat, function ($value) use ($requiredKeys): bool {
            if (! is_array($value)) {
                return false;
            }

            foreach ($requiredKeys as $key) {
                if (! array_key_exists($key, $value)) {
                    return false;
                }
            }

            return true;
        }));

        return array_map(fn (array $record): array => $this->dereferenceNestedRecord($record, $flat), $records);
    }

    private function dereferenceNestedRecord(array $record, array $flat): array
    {
        $resolved = [];

        foreach ($record as $key => $value) {
            if (is_int($value) && array_key_exists($value, $flat)) {
                $resolved[$key] = is_array($flat[$value])
                    ? $this->dereferenceNestedRecord($flat[$value], $flat)
                    : $flat[$value];

                continue;
            }

            if (is_array($value)) {
                $resolved[$key] = $this->dereferenceNestedRecord($value, $flat);

                continue;
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }

    private function mapFixedRows(array $flat, int $width, array $keys): array
    {
        $rows = [];

        foreach (array_chunk($flat, $width) as $chunk) {
            if (count($chunk) !== $width) {
                continue;
            }

            $rows[] = array_combine($keys, array_map(fn ($value): string => (string) $value, $chunk));
        }

        return $rows;
    }

    private function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }
}
