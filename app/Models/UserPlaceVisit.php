<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPlaceVisit extends Model
{
    protected $table = 'user_place_visits';

    protected $fillable = ['user_id', 'property_id', 'tenant_id', 'entered_at', 'last_seen_at', 'left_at'];

    protected $casts = [
        'entered_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
