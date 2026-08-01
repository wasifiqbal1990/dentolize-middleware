<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookAttempt extends Model
{
    protected $guarded = [];

    protected $casts = [
        'verify_token_present' => 'boolean',
        'verify_token_valid' => 'boolean',
        'payload_keys' => 'array',
        'received_at' => 'datetime',
    ];
}
