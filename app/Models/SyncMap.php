<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncMap extends Model
{
    protected $guarded = [];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'synced_at' => 'datetime',
        'amount' => 'decimal:2',
    ];
}
