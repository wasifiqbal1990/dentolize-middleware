<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncCheckpoint extends Model
{
    protected $primaryKey = 'entity_type';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'last_updated_at_seen' => 'datetime',
        'last_full_sweep_at' => 'datetime',
    ];
}
