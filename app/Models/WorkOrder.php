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

    protected static function booted(): void
    {
        // Mantenimiento por periodo: al pasar a Listo, sus equipos agendan la siguiente fecha.
        // Un error aquí no debe impedir cerrar la orden.
        static::updated(function (WorkOrder $orden) {
            if (!$orden->wasChanged('status') || $orden->status !== 'Listo') {
                return;
            }
            try {
                MaintenanceEquipment::alCerrarOrden($orden->id, \Carbon\Carbon::now('America/Merida')->toDateString());
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Ciclo de mantenimiento de la orden {$orden->id}: " . $e->getMessage());
            }
        });

        // QR de acceso: se borra cuando la orden (o todo su lote) queda finalizada, cancelada o rechazada
        static::updated(function (WorkOrder $orden) {
            if ($orden->wasChanged('status') && \App\Services\AccesoQr::cerrado($orden->status)) {
                try {
                    \App\Services\AccesoQr::purgarSiCerrado($orden);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("QR de acceso de la orden {$orden->id}: " . $e->getMessage());
                }
            }
        });
        // Al borrar una orden de un lote, si ya no queda ninguna abierta se borra el QR del lote
        static::deleted(function (WorkOrder $orden) {
            if ($orden->batch_id) {
                try {
                    \App\Services\AccesoQr::purgarSiCerrado($orden, 'Orden eliminada');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("QR de acceso del lote {$orden->batch_id}: " . $e->getMessage());
                }
            }
        });
    }

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
