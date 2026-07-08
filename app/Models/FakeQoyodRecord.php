<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FakeQoyodRecord extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'amount' => 'decimal:2',
    ];
}
