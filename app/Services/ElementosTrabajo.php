<?php

namespace App\Services;

use App\Models\MaintenanceEquipment;
use App\Models\WorkOrder;
use App\Models\WorkOrderComponent;
use App\Models\WorkReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Elementos de una orden: los elegidos del levantamiento y los grupos capturados sin levantamiento.
class ElementosTrabajo
{
    // A partir de 2 elementos solo se piden ANTES y DESPUÉS por elemento
    public const MINIMO_POR_ELEMENTO = 2;
    private const ETAPAS_POR_ELEMENTO = ['antes', 'despues'];

    public static function vienenEnPeticion(Request $request): bool
    {
        return $request->filled('component_ids') || $request->filled('grupos') || $request->filled('equipo_ids');
    }

    // "Mini Split (LG) - Sala": elementos del levantamiento de una propiedad
    public static function delLevantamiento(int $propertyId, ?array $ids = null)
    {
        $query = DB::table('property_components as pc')
            ->join('property_areas as pa', 'pc.property_area_id', '=', 'pa.id')
            ->where('pa.property_id', $propertyId)
            ->select('pc.id', 'pc.category', 'pc.sub_category', 'pc.brand', 'pc.model_or_color', 'pa.name as area')
            ->orderBy('pa.name')
            ->orderBy('pc.sub_category');
        if ($ids !== null) {
            $query->whereIn('pc.id', $ids);
        }

        return $query->get()->map(function ($c) {
            $marca = trim((string) $c->brand);
            $c->label = $c->sub_category . ($marca !== '' ? " ({$marca})" : '') . " - {$c->area}";
            return $c;
        });
    }

    // Valida y sube las fotos antes de abrir la transacción que crea la orden.
    // Devuelve ['error' => mensaje] o los datos para adjuntar().
    public static function preparar(Request $request): array
    {
        if (!in_array((int) optional($request->user())->role_id, [0, 1], true)) {
            return ['error' => 'Solo un administrador puede crear trabajos con varios elementos.', 'codigo' => 403];
        }

        $request->validate([
            'component_ids' => 'nullable|array|max:100',
            'component_ids.*' => 'integer',
            'equipo_ids' => 'nullable|array|max:150',
            'equipo_ids.*' => 'integer',
            'grupos' => 'nullable|array|max:50',
            'grupos.*.tipo' => 'required|string|max:150',
            'grupos.*.cantidad' => 'required|integer|min:1|max:1000',
            'grupos.*.detalle' => 'nullable|string|max:1000',
            'grupos.*.foto_equipo' => 'required|file|image|max:10240',
            'grupos.*.foto_datos' => 'required|file|image|max:10240',
        ]);

        $ids = array_values(array_unique(array_map('intval', $request->input('component_ids', []))));
        $componentes = self::delLevantamiento((int) $request->property_id, $ids);
        if ($componentes->count() !== count($ids)) {
            return ['error' => 'Alguno de los elementos ya no pertenece a esta propiedad.', 'codigo' => 422];
        }

        // Equipos ya registrados en mantenimiento: se usan tal cual, sin duplicarlos
        $idsEquipos = array_values(array_unique(array_map('intval', $request->input('equipo_ids', []))));
        $equipos = MaintenanceEquipment::where('property_id', (int) $request->property_id)->whereIn('id', $idsEquipos)->get();
        if ($equipos->count() !== count($idsEquipos)) {
            return ['error' => 'Alguno de los equipos ya no pertenece a esta propiedad.', 'codigo' => 422];
        }

        $fotos = app(FotosMantenimiento::class);
        $grupos = [];
        foreach ((array) $request->input('grupos', []) as $i => $grupo) {
            $grupos[] = [
                'tipo' => trim($grupo['tipo']),
                'cantidad' => (int) $grupo['cantidad'],
                'detalle' => $grupo['detalle'] ?? null,
                'foto_equipo_url' => $fotos->subir($request->file("grupos.{$i}.foto_equipo")),
                'foto_datos_url' => $fotos->subir($request->file("grupos.{$i}.foto_datos")),
            ];
        }

        return ['componentes' => $componentes, 'equipos' => $equipos, 'grupos' => $grupos];
    }

    public static function adjuntar(WorkOrder $orden, array $preparados, $usuario): void
    {
        foreach ($preparados['componentes'] as $c) {
            $orden->elementos()->create(['property_component_id' => $c->id, 'label' => $c->label]);
        }
        foreach ($preparados['equipos'] as $equipo) {
            $orden->elementos()->create([
                'property_component_id' => $equipo->property_component_id,
                'maintenance_equipment_id' => $equipo->id,
                'label' => $equipo->etiqueta(),
            ]);
            if (!$equipo->work_order_id) {
                $equipo->update(['work_order_id' => $orden->id]);
            }
            // Su fecha pendiente queda atendida por esta orden: ya no aparece en la agenda
            $equipo->visits()->where('status', 'pendiente')->update(['status' => 'convertida', 'work_order_id' => $orden->id]);
        }
        foreach ($preparados['grupos'] as $grupo) {
            $equipo = MaintenanceEquipment::create($grupo + [
                'tenant_id' => $orden->tenant_id,
                'property_id' => $orden->property_id,
                'work_order_id' => $orden->id,
                'created_by' => optional($usuario)->id,
            ]);
            $orden->elementos()->create(['maintenance_equipment_id' => $equipo->id, 'label' => $equipo->etiqueta()]);
        }
    }

    // Elementos a los que les falta el ANTES o el DESPUÉS. Vacío en las órdenes viejas y en las de un elemento.
    public static function sinEvidencia(WorkOrder $orden): array
    {
        $elementos = WorkOrderComponent::where('work_order_id', $orden->id)->get();
        if ($elementos->count() < self::MINIMO_POR_ELEMENTO) {
            return [];
        }

        $reportes = WorkReport::where('work_order_id', $orden->id)
            ->whereIn('stage', self::ETAPAS_POR_ELEMENTO)
            ->get(['work_order_component_id', 'stage'])
            ->groupBy('work_order_component_id');

        return $elementos->filter(function ($elemento) use ($reportes) {
            $etapas = ($reportes[$elemento->id] ?? collect())->pluck('stage')->all();
            return array_diff(self::ETAPAS_POR_ELEMENTO, $etapas) !== [];
        })->pluck('label')->values()->all();
    }
}
