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
        'parent_id',
        'completed_at',
        'completed_by',
        'completion_note',
    ];

    protected $casts = [
        'notify_team' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    // Aviso anterior del que este es el seguimiento
    public function parent()
    {
        return $this->belongsTo(CalendarEvent::class, 'parent_id');
    }

    public function followUps()
    {
        return $this->hasMany(CalendarEvent::class, 'parent_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
