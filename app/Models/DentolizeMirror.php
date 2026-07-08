<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DentolizeMirror extends Model
{
    protected $guarded = [];

    protected $casts = [
        'raw' => 'array',
        'synced_to_qoyod' => 'boolean',
        'source_created_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'pulled_at' => 'datetime',
    ];
}
