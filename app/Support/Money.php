<?php

namespace App\Support;

class Money
{
    public static function normalize(string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
