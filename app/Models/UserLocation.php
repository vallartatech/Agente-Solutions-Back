<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLocation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'lat',
        'lng',
        'accuracy',
        'source',
        'work_order_id',
        'recorded_at',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'accuracy' => 'float',
        'recorded_at' => 'datetime',
    ];
}
