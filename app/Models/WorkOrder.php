<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantScoped;

class WorkOrder extends Model
{
    use HasFactory, TenantScoped;

    protected $table = 'work_orders';

    protected $fillable = [
        'tenant_id',
        'property_id',
        'type',
        'zone',
        'equipment',
        'description',
        'evidence_path',
        'evidence_path_2',
        'status',
        'priority',
        'batch_id',
        'tecnico_id',
        'custom_checklist',
        'scheduled_at',
        'publish_network'
    ];

    protected $casts = [
        'custom_checklist' => 'array',
        'scheduled_at' => 'datetime'
    ];

    public function property()
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    // Admin que validó y dio por finalizado el trabajo (con o sin ticket de conformidad)
    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by')->withoutGlobalScopes();
    }

    public function tecnico()
    {
        return $this->belongsTo(User::class, 'tecnico_id')->withoutGlobalScopes();
    }

    public function technicians()
    {
        return $this->belongsToMany(User::class, 'work_order_technician', 'work_order_id', 'technician_id')->withoutGlobalScopes();
    }

    public function workReports()
    {
        return $this->hasMany(WorkReport::class, 'work_order_id');
    }

    // Elementos del trabajo; vacío en las órdenes de antes
    public function elementos()
    {
        return $this->hasMany(WorkOrderComponent::class, 'work_order_id')->orderBy('id');
    }

    public function networkQuotes()
    {
        return $this->hasMany(NetworkQuote::class, 'work_order_id');
    }
}
