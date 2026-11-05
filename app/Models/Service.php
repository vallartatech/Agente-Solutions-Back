<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantScoped;

class Service extends Model
{
    use HasFactory, TenantScoped;

    protected static function booted(): void
    {
        // QR de acceso: se borra cuando el servicio queda finalizado, cancelado o rechazado
        static::updated(function (Service $servicio) {
            if ($servicio->wasChanged('status') && \App\Services\AccesoQr::cerrado($servicio->status)) {
                try {
                    \App\Services\AccesoQr::purgarSiCerrado($servicio);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("QR de acceso del servicio {$servicio->id}: " . $e->getMessage());
                }
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'property_id',
        'property_area_id',
        'requested_by',
        'assigned_to',
        'service_category_id',
        'service_type',
        'priority',
        'status',
        'title',
        'description',
        'evidence_path',
        'scheduled_start',
        'scheduled_end',
        'real_start',
        'real_end',
        'supervisor_name',
        'custom_checklist'
    ];

    protected $casts = [
        'custom_checklist' => 'array',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function area()
    {
        return $this->belongsTo(PropertyArea::class, 'property_area_id');
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'assigned_to')->withoutGlobalScopes();
    }
    public function components()
    {
        return $this->belongsToMany(PropertyComponent::class, 'service_component');
    }

    public function technicians()
    {
        return $this->belongsToMany(User::class, 'service_technician', 'service_id', 'technician_id')->withoutGlobalScopes();
    }

    public function workReports()
    {
        return $this->hasMany(WorkReport::class, 'service_id');
    }
}

