<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceVisit extends Model
{
    protected $fillable = [
        'maintenance_equipment_id',
        'fecha_programada',
        'status',
        'work_order_id',
    ];

    protected $casts = [
        'fecha_programada' => 'date:Y-m-d',
    ];

    public function equipment()
    {
        return $this->belongsTo(MaintenanceEquipment::class, 'maintenance_equipment_id');
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class)->withoutGlobalScopes();
    }
}
