<?php

namespace App\Support;

class ReferenceBuilder
{
    private const PREFIXES = [
        'patient' => 'DENTO-CUST',
        'customer' => 'DENTO-CUST',
        'invoice' => 'DENTO-INV',
        'payment' => 'DENTO-PAY',
        'expense' => 'DENTO-EXP',
        'expense_payment' => 'DENTO-EXPPAY',
        'treasury' => 'DENTO-TREAS',
    ];

    public static function for(string $entityType, string $dentolizeId, string $suffix = ''): string
    {
        $prefix = self::PREFIXES[$entityType] ?? 'DENTO-'.strtoupper($entityType);
        $id = ltrim(trim($dentolizeId), '#');
        $suffix = trim($suffix);

        return $suffix === ''
            ? "{$prefix}-{$id}"
            : "{$prefix}-{$id}-{$suffix}";
    }
}
