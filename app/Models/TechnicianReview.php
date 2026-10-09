<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TechnicianReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'technician_id',
        'client_id',
        'work_order_id',
        'service_id',
        'rating_stars',
        'rating_time',
        'comment',
        'scheduled_at',
        'arrived_at',
        'delay_minutes'
    ];

    protected $casts = [
        'rating_stars' => 'float',
        'rating_time' => 'float',
        'scheduled_at' => 'datetime',
        'arrived_at' => 'datetime',
        'delay_minutes' => 'integer'
    ];

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
