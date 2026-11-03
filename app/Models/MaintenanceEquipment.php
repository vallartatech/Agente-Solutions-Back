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
        'propiedad_nombre',
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
        'frecuencia_meses',
        'ultimo_mantenimiento',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'veces_al_ano' => 'integer',
        'fecha_base' => 'date:Y-m-d',
        'activo' => 'boolean',
        'frecuencia_meses' => 'integer',
        'ultimo_mantenimiento' => 'date:Y-m-d',
    ];

    // Mantenimiento por periodo: cada cuántos meses
    public const FRECUENCIAS = [1, 2, 3, 4, 6, 12];

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

    // Último mantenimiento + N meses. Puede caer en el pasado: entonces queda vencida y se ve en rojo.
    public static function siguienteFecha(Carbon|string $ultimo, int $meses): string
    {
        return Carbon::parse($ultimo)->startOfDay()->addMonthsNoOverflow($meses)->toDateString();
    }

    // Deja una sola fecha pendiente. Si ese día ya se atendió, no se duplica.
    public function reprogramar(string $fecha): void
    {
        $this->visits()->where('status', 'pendiente')->delete();
        if (!$this->visits()->whereDate('fecha_programada', $fecha)->exists()) {
            $this->visits()->create(['fecha_programada' => $fecha, 'status' => 'pendiente']);
        }
    }

    // Se realizó el mantenimiento en $fecha: la fecha pendiente (o la de la orden) queda como realizada
    // y, si tiene periodo, el ciclo vuelve a contar desde ese día.
    public function registrarRealizado(string $fecha, ?int $workOrderId = null): void
    {
        $atendidas = MaintenanceVisit::where('maintenance_equipment_id', $this->id)
            ->where(function ($q) use ($workOrderId) {
                $q->where('status', 'pendiente');
                if ($workOrderId) {
                    $q->orWhere(fn ($o) => $o->where('status', 'convertida')->where('work_order_id', $workOrderId));
                }
            })
            ->get();

        foreach ($atendidas as $visita) {
            // Hecha antes de tiempo: queda en el día en que se hizo, para no ocupar una fecha futura del ciclo.
            // Si ese día ya está registrado, no se duplica.
            $adelantada = $visita->fecha_programada->toDateString() > $fecha;
            if ($adelantada && MaintenanceVisit::where('maintenance_equipment_id', $this->id)->whereKeyNot($visita->id)->whereDate('fecha_programada', $fecha)->exists()) {
                $visita->delete();
                continue;
            }
            $visita->update(['status' => 'realizada', 'realizada_el' => $fecha] + ($adelantada ? ['fecha_programada' => $fecha] : []));
        }

        if (!$this->ultimo_mantenimiento || $this->ultimo_mantenimiento->toDateString() < $fecha) {
            $this->update(['ultimo_mantenimiento' => $fecha]);
        }
        if ($this->frecuencia_meses) {
            $this->reprogramar(self::siguienteFecha($fecha, $this->frecuencia_meses));
        }
    }

    // Al cerrar (Listo) una orden de mantenimiento, cada equipo de esa orden sigue su ciclo
    public static function alCerrarOrden(int $workOrderId, string $fecha): void
    {
        $ids = MaintenanceVisit::where('work_order_id', $workOrderId)
            ->where('status', 'convertida')
            ->pluck('maintenance_equipment_id')
            ->unique();

        self::whereIn('id', $ids)->where('activo', true)->get()
            ->each(fn (self $equipo) => $equipo->registrarRealizado($fecha, $workOrderId));
    }
}
