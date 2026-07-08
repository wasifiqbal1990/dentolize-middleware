<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\Money;
use App\Support\PhoneNormalizer;
use App\Support\ReferenceBuilder;
use App\Sync\Clients\LiveDentolizeClient;
use App\Sync\Clients\LiveQoyodClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class ImportDentolizePatientFlow extends Command
{
    protected $signature = 'whisper:import-dentolize-patient-flow
        {patient_id}
        {--invoice-status=Draft}
        {--reference-suffix= : Optional suffix for repeat/corrective Qoyod test imports.}';

    protected $description = 'Create one Dentolize patient flow in Qoyod: contact, invoices, and payment attempts.';

    public function handle(LiveDentolizeClient $dentolize, LiveQoyodClient $qoyod): int
    {
        $patientId = (string) $this->argument('patient_id');
        $invoiceStatus = (string) $this->option('invoice-status');
        $referenceSuffix = $this->referenceSuffix();

        try {
            $flow = $dentolize->fetchPatientFlow($patientId);
            $customer = $qoyod->createCustomer($this->customerPayload($flow['patient']));
            $this->audit($patientId, 'create_flow_customer', '/customers', 'POST', $this->customerPayload($flow['patient']), $customer);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Created Qoyod customer {$customer['id']}.");

        $invoiceIdByNumber = [];
        $failures = 0;

        foreach ($flow['invoices'] as $invoice) {
            $payload = $this->invoicePayload($invoice, $customer['id'], $invoiceStatus, $referenceSuffix);

            try {
                $created = $qoyod->createInvoice($payload);
                $invoiceIdByNumber[$this->invoiceKey($invoice)] = $created['id'];
                $this->audit($invoice['id'], 'create_flow_invoice', '/invoices', 'POST', $payload, $created);
                $this->components->info("Created Qoyod {$invoiceStatus} invoice {$created['id']} for Dentolize invoice {$this->invoiceKey($invoice)}.");
            } catch (Throwable $exception) {
                $failures++;
                $this->components->error("Invoice {$this->invoiceKey($invoice)} failed: ".$exception->getMessage());
            }
        }

        foreach ($flow['payments'] as $payment) {
            $invoiceNumber = ltrim($payment['invoiceId'], '#') ?: (count($invoiceIdByNumber) === 1 ? array_key_first($invoiceIdByNumber) : '');

            if (! isset($invoiceIdByNumber[$invoiceNumber])) {
                $failures++;
                $this->components->warn("Skipped payment {$payment['id']} because invoice {$invoiceNumber} was not created.");

                continue;
            }

            $payload = $this->paymentPayload($payment, $invoiceIdByNumber[$invoiceNumber], $referenceSuffix);

            try {
                $created = $qoyod->createInvoicePayment($payload);
                $this->audit($payment['id'], 'create_flow_payment', '/invoice_payments', 'POST', $payload, $created);
                $this->components->info("Created Qoyod payment {$created['id']} for Dentolize payment {$payment['id']}.");
            } catch (Throwable $exception) {
                $failures++;
                $this->components->error("Payment {$payment['id']} failed: ".$exception->getMessage());
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function customerPayload(array $patient): array
    {
        return [
            'contact' => [
                'name' => trim(($patient['firstName'] ?? '').' '.($patient['lastName'] ?? '')) ?: 'Dentolize Patient',
                'organization' => trim('Dentolize doctor '.($patient['doctorName'] ?? '')),
                'email' => '',
                'phone_number' => PhoneNormalizer::toSaudiE164($patient['phoneNumber'] ?? null),
                'tax_number' => '',
                'status' => 'Active',
                'shipping_address' => ['shipping_address' => '', 'shipping_city' => '', 'shipping_country' => ''],
                'billing_address' => ['billing_address' => '', 'billing_city' => '', 'billing_country' => '', 'building_number' => ''],
            ],
        ];
    }

    private function invoicePayload(array $invoice, string $contactId, string $status, string $referenceSuffix): array
    {
        $invoiceNumber = $this->invoiceKey($invoice);
        $issueDate = CarbonImmutable::parse($invoice['createdAt'])->toDateString();

        return [
            'invoice' => [
                'contact_id' => $contactId,
                'reference' => ReferenceBuilder::for('invoice', $invoiceNumber, $referenceSuffix),
                'description' => 'Dentolize invoice '.$invoiceNumber,
                'issue_date' => $issueDate,
                'due_date' => $issueDate,
                'status' => $status,
                'inventory_id' => (string) config('whisper.default_inventory_id'),
                'line_items' => [[
                    'product_id' => (string) config('whisper.qoyod_generic_product_id'),
                    'description' => 'Dental Services',
                    'quantity' => '1.0',
                    'unit_price' => Money::normalize($invoice['subtotal'] ?: $invoice['total']),
                    'discount' => '0.00',
                    'discount_type' => 'amount',
                    'tax_percent' => $this->taxPercent($invoice),
                ]],
            ],
        ];
    }

    private function taxPercent(array $invoice): string
    {
        $taxPercent = $invoice['taxPercent'] ?? null;

        if ($taxPercent === null || $taxPercent === '') {
            return (string) config('whisper.vat_rate');
        }

        return (string) $taxPercent;
    }

    private function invoiceKey(array $invoice): string
    {
        return ltrim($invoice['invoiceId'] ?: $invoice['id'], '#');
    }

    private function paymentPayload(array $payment, string $qoyodInvoiceId, string $referenceSuffix): array
    {
        return [
            'invoice_payment' => [
                'reference' => ReferenceBuilder::for('payment', $payment['id'], $referenceSuffix),
                'invoice_id' => $qoyodInvoiceId,
                'account_id' => (string) config('whisper.default_account_id'),
                'date' => CarbonImmutable::parse($payment['createdAt'])->toDateString(),
                'amount' => Money::normalize($payment['amount']),
            ],
        ];
    }

    private function referenceSuffix(): string
    {
        return trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $this->option('reference-suffix')), '-');
    }

    private function audit(string $correlationId, string $action, string $endpoint, string $method, array $request, array $response): void
    {
        AuditLog::query()->create([
            'correlation_id' => $correlationId,
            'action' => $action,
            'target_system' => 'Qoyod',
            'endpoint' => $endpoint,
            'http_method' => $method,
            'request_body' => $request,
            'response_body' => $response['payload'],
            'response_code' => $response['status_code'],
        ]);
    }
}
