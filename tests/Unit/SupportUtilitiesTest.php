<?php

namespace Tests\Unit;

use App\Support\Money;
use App\Support\PhoneNormalizer;
use App\Support\ReferenceBuilder;
use PHPUnit\Framework\TestCase;

class SupportUtilitiesTest extends TestCase
{
    public function test_reference_generation_is_deterministic_and_stable(): void
    {
        $this->assertSame('DENTO-INV-21038', ReferenceBuilder::for('invoice', '#21038'));
        $this->assertSame('DENTO-CUST-patient-123', ReferenceBuilder::for('patient', 'patient-123'));
        $this->assertSame('DENTO-EXPPAY-abc', ReferenceBuilder::for('expense_payment', 'abc'));
    }

    public function test_saudi_phone_numbers_are_normalized_to_e164(): void
    {
        $this->assertSame('+966512345678', PhoneNormalizer::toSaudiE164('051 234 5678'));
        $this->assertSame('+966512345678', PhoneNormalizer::toSaudiE164('966512345678'));
        $this->assertSame('+966512345678', PhoneNormalizer::toSaudiE164('+966-51-234-5678'));
        $this->assertSame('', PhoneNormalizer::toSaudiE164(null));
    }

    public function test_money_is_normalized_as_decimal_string(): void
    {
        $this->assertSame('249.00', Money::normalize('249'));
        $this->assertSame('249.50', Money::normalize('249.5'));
        $this->assertSame('0.00', Money::normalize(null));
    }
}
