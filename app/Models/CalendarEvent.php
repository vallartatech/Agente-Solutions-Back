<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    use HasFactory;

    protected $table = 'calendar_events';

    protected $fillable = [
        'tenant_id',
        'created_by',
        'title',
        'description',
        'event_date',
        'start_time',
        'end_time',
        'type',
        'color',
        'notify_team',
        'label',
    ];

    protected $casts = [
        'notify_team' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
