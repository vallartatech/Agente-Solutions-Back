<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class MaintenanceEquipment extends Model
{
    protected $table = 'maintenance_equipment';

    protected $fillable = [
        'tenant_id',
        'property_id',
        'property_component_id',
        'work_order_id',
        'tipo',
        'cantidad',
        'foto_equipo_url',
        'foto_datos_url',
        'detalle',
        'veces_al_ano',
        'fecha_base',
        'activo',
        'created_by',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'veces_al_ano' => 'integer',
        'fecha_base' => 'date:Y-m-d',
        'activo' => 'boolean',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class)->withoutGlobalScopes();
    }

    public function visits()
    {
        return $this->hasMany(MaintenanceVisit::class)->orderBy('fecha_programada');
    }

    // "3 × Minis" en los grupos; un solo equipo, solo su tipo
    public function etiqueta(): string
    {
        return $this->cantidad > 1 ? "{$this->cantidad} × {$this->tipo}" : $this->tipo;
    }

    // Fechas de un ciclo de un año a partir de la base: 1 vez = a los 12 meses, 2 = 6 y 12, 3 = 4, 8 y 12, 4 = 3, 6, 9 y 12
    public static function fechasDelCiclo(Carbon|string $base, int $veces): array
    {
        $inicio = Carbon::parse($base)->startOfDay();
        $meses = (int) (12 / $veces);

        return array_map(
            fn ($i) => $inicio->copy()->addMonthsNoOverflow($meses * $i)->toDateString(),
            range(1, $veces)
        );
    }

    public function crearVisitasDesde(Carbon|string $base): void
    {
        foreach (self::fechasDelCiclo($base, (int) $this->veces_al_ano) as $fecha) {
            MaintenanceVisit::firstOrCreate(
                ['maintenance_equipment_id' => $this->id, 'fecha_programada' => $fecha],
                ['status' => 'pendiente']
            );
        }
    }
}
