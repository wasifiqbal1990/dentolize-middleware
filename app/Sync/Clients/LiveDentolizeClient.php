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

    private function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }
}
