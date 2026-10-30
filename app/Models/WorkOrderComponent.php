<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderComponent extends Model
{
    protected $fillable = [
        'work_order_id',
        'property_component_id',
        'maintenance_equipment_id',
        'label',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function maintenanceEquipment()
    {
        return $this->belongsTo(MaintenanceEquipment::class);
    }
}
