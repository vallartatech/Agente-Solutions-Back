<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceEquipment;
use App\Models\MaintenanceVisit;
use App\Models\Property;
use App\Models\WorkOrder;
use App\Models\WorkOrderComponent;
use App\Services\ElementosTrabajo;
use App\Services\FotosMantenimiento;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Ciclos de mantenimiento de Agente Solutions (solo Root y Admin del equipo interno).
class MaintenanceController extends Controller
{
    private const ZONA_LOCAL = 'America/Merida';

    private function esAdmin(Request $request): bool
    {
        return in_array((int) $request->user()->role_id, [0, 1], true);
    }

    private function tenantId(Request $request): ?int
    {
        return $request->user()->tenant_id ? (int) $request->user()->tenant_id : null;
    }

    private function propiedad(Request $request, $id): ?Property
    {
        $query = Property::withoutGlobalScopes()->whereKey($id);
        return WorkValidationController::mismoEquipo($query, $this->tenantId($request))->first();
    }

    private function equiposDelEquipo(Request $request)
    {
        return WorkValidationController::mismoEquipo(MaintenanceEquipment::query(), $this->tenantId($request));
    }

    // Propiedades del equipo interno, con cuántos elementos tiene su levantamiento
    public function propiedades(Request $request)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $query = Property::withoutGlobalScopes()
            ->select('id', 'property_name', 'address')
            ->selectSub(
                DB::table('property_components as pc')
                    ->join('property_areas as pa', 'pc.property_area_id', '=', 'pa.id')
                    ->whereColumn('pa.property_id', 'properties.id')
                    ->selectRaw('COUNT(*)'),
                'elementos_count'
            )
            ->orderBy('property_name');

        return response()->json(WorkValidationController::mismoEquipo($query, $this->tenantId($request))->get());
    }

    // Elementos de una orden, para la galería de evidencias y el reporte oficial
    public function elementosDeOrden($id)
    {
        return response()->json(WorkOrderComponent::where('work_order_id', (int) $id)->orderBy('id')->get(['id', 'label']));
    }

    // Elementos del levantamiento para armar un trabajo
    public function elementosDePropiedad(Request $request, $id)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }
        if (!$this->propiedad($request, $id)) {
            return response()->json(['error' => 'Propiedad no encontrada.'], 404);
        }
        return response()->json(ElementosTrabajo::delLevantamiento((int) $id));
    }

    // Datos de la ventana de ciclos: con work_order_id al finalizar un trabajo, con property_id desde la propiedad
    public function formulario(Request $request)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $orden = $request->filled('work_order_id')
            ? WorkOrder::withoutGlobalScopes()->find($request->integer('work_order_id'))
            : null;
        $propiedad = $this->propiedad($request, $orden?->property_id ?? $request->integer('property_id'));
        if (!$propiedad) {
            return response()->json(['error' => 'Propiedad no encontrada.'], 404);
        }

        $enOrden = $orden
            ? WorkOrderComponent::where('work_order_id', $orden->id)->pluck('property_component_id')->filter()->all()
            : [];
        $equipos = MaintenanceEquipment::where('property_id', $propiedad->id)->where('activo', true)->get();
        $porElemento = $equipos->whereNotNull('property_component_id')->keyBy('property_component_id');

        $elementos = ElementosTrabajo::delLevantamiento($propiedad->id)->map(fn ($c) => [
            'id' => $c->id,
            'label' => $c->label,
            'area' => $c->area,
            'en_orden' => in_array($c->id, $enOrden),
            'equipo_id' => $porElemento[$c->id]->id ?? null,
            'veces_al_ano' => $porElemento[$c->id]->veces_al_ano ?? null,
        ]);

        $fecha = $orden?->closed_at
            ? Carbon::parse($orden->closed_at)->setTimezone(self::ZONA_LOCAL)
            : Carbon::now(self::ZONA_LOCAL);

        return response()->json([
            'property_id' => $propiedad->id,
            'property_name' => $propiedad->property_name,
            'fecha_base' => $fecha->toDateString(),
            'elementos' => $elementos->values(),
            // Grupos sin levantamiento ya capturados: los de este trabajo y los que siguen sin ciclo
            'grupos' => $equipos->whereNull('property_component_id')
                ->filter(fn ($e) => !$e->veces_al_ano || ($orden && (int) $e->work_order_id === (int) $orden->id))
                ->values(),
        ]);
    }

    // Guarda los ciclos. Cada elemento: { id?, property_component_id? | tipo + cantidad + fotos, veces_al_ano }
    public function guardar(Request $request)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $validated = $request->validate([
            'property_id' => 'required|integer',
            'work_order_id' => 'nullable|integer',
            'fecha_base' => 'required|date',
            'items' => 'required|array|min:1|max:150',
            'items.*.id' => 'nullable|integer',
            'items.*.property_component_id' => 'nullable|integer',
            'items.*.tipo' => 'nullable|string|max:150',
            'items.*.cantidad' => 'nullable|integer|min:1|max:1000',
            'items.*.detalle' => 'nullable|string|max:1000',
            'items.*.veces_al_ano' => 'required|integer|in:1,2,3,4',
            'items.*.foto_equipo' => 'nullable|file|image|max:10240',
            'items.*.foto_datos' => 'nullable|file|image|max:10240',
        ]);

        $propiedad = $this->propiedad($request, $validated['property_id']);
        if (!$propiedad) {
            return response()->json(['error' => 'Propiedad no encontrada.'], 404);
        }

        // El trabajo de origen debe ser de esta misma propiedad
        $ordenId = !empty($validated['work_order_id'])
            ? WorkOrder::withoutGlobalScopes()->where('property_id', $propiedad->id)->whereKey($validated['work_order_id'])->value('id')
            : null;

        $idsElementos = collect($validated['items'])->pluck('property_component_id')->filter()->map(fn ($i) => (int) $i)->unique()->values();
        $elementos = ElementosTrabajo::delLevantamiento($propiedad->id, $idsElementos->all())->keyBy('id');
        if ($elementos->count() !== $idsElementos->count()) {
            return response()->json(['error' => 'Alguno de los elementos ya no pertenece a esta propiedad.'], 422);
        }

        // Fotos fuera de la transacción; un grupo nuevo debe traer las dos
        $fotos = app(FotosMantenimiento::class);
        $urls = [];
        foreach ($validated['items'] as $i => $item) {
            if (!empty($item['property_component_id'])) {
                continue;
            }
            if (empty($item['id']) && (empty($item['tipo']) || !$request->hasFile("items.{$i}.foto_equipo") || !$request->hasFile("items.{$i}.foto_datos"))) {
                return response()->json(['error' => 'Cada grupo necesita tipo, foto del equipo y foto de sus datos.'], 422);
            }
            foreach (['foto_equipo', 'foto_datos'] as $campo) {
                if ($request->hasFile("items.{$i}.{$campo}")) {
                    $urls[$i]["{$campo}_url"] = $fotos->subir($request->file("items.{$i}.{$campo}"));
                }
            }
        }

        $base = $validated['fecha_base'];
        $guardados = DB::transaction(function () use ($validated, $propiedad, $elementos, $urls, $base, $ordenId, $request) {
            $resultado = [];
            foreach ($validated['items'] as $i => $item) {
                $equipo = null;
                if (!empty($item['id'])) {
                    $equipo = MaintenanceEquipment::where('property_id', $propiedad->id)->find($item['id']);
                } elseif (!empty($item['property_component_id'])) {
                    $equipo = MaintenanceEquipment::where('property_id', $propiedad->id)
                        ->where('property_component_id', $item['property_component_id'])
                        ->where('activo', true)
                        ->first();
                }

                $elemento = !empty($item['property_component_id']) ? $elementos[(int) $item['property_component_id']] : null;
                $equipo ??= new MaintenanceEquipment([
                    'tenant_id' => $propiedad->tenant_id,
                    'property_id' => $propiedad->id,
                    'property_component_id' => $elemento?->id,
                    'work_order_id' => $ordenId,
                    'created_by' => $request->user()->id,
                ]);

                $equipo->fill(array_filter([
                    'tipo' => $elemento?->label ?? ($item['tipo'] ?? null),
                    'cantidad' => $item['cantidad'] ?? null,
                    'detalle' => $item['detalle'] ?? null,
                ], fn ($v) => $v !== null) + ($urls[$i] ?? []));
                $equipo->veces_al_ano = (int) $item['veces_al_ano'];
                $equipo->fecha_base = $base;
                $equipo->activo = true;
                $equipo->save();

                // Un ciclo nuevo reemplaza las fechas que aún no se atienden
                $equipo->visits()->where('status', 'pendiente')->delete();
                $equipo->crearVisitasDesde($base);
                $resultado[] = $equipo->load('visits');
            }
            return $resultado;
        });

        return response()->json(['success' => true, 'equipos' => $guardados], 201);
    }

    // Equipos en mantenimiento de una propiedad, con sus próximas fechas
    public function dePropiedad(Request $request, $id)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }
        if (!$this->propiedad($request, $id)) {
            return response()->json(['error' => 'Propiedad no encontrada.'], 404);
        }

        return response()->json(
            MaintenanceEquipment::with(['visits' => fn ($q) => $q->where('status', 'pendiente')])
                ->where('property_id', (int) $id)
                ->where('activo', true)
                ->orderBy('tipo')
                ->get()
        );
    }

    // Quita un equipo del programa: sus fechas pendientes desaparecen de la agenda
    public function baja(Request $request, $id)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }
        $equipo = $this->equiposDelEquipo($request)->find($id);
        if (!$equipo) {
            return response()->json(['error' => 'Equipo no encontrado.'], 404);
        }

        DB::transaction(function () use ($equipo) {
            $equipo->update(['activo' => false]);
            $equipo->visits()->where('status', 'pendiente')->delete();
        });

        return response()->json(['success' => true]);
    }

    // Fechas pendientes para el calendario interno
    public function visitas(Request $request)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $visitas = MaintenanceVisit::where('status', 'pendiente')
            ->whereHas('equipment', fn ($q) => WorkValidationController::mismoEquipo($q->where('activo', true), $this->tenantId($request)))
            ->with('equipment.property:id,property_name,address')
            ->orderBy('fecha_programada')
            ->get();

        return response()->json($visitas);
    }

    private function visitaPendiente(Request $request, $id): ?MaintenanceVisit
    {
        return MaintenanceVisit::where('status', 'pendiente')
            ->whereHas('equipment', fn ($q) => WorkValidationController::mismoEquipo($q, $this->tenantId($request)))
            ->with('equipment')
            ->find($id);
    }

    // Al atender la última fecha del ciclo se programa el año siguiente
    private function renovarCiclo(MaintenanceEquipment $equipo, string $ultimaFecha): void
    {
        if ($equipo->activo && $equipo->veces_al_ano && !$equipo->visits()->where('status', 'pendiente')->exists()) {
            $equipo->crearVisitasDesde($ultimaFecha);
        }
    }

    public function omitir(Request $request, $id)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }
        $visita = $this->visitaPendiente($request, $id);
        if (!$visita) {
            return response()->json(['error' => 'Esta fecha ya fue atendida.'], 422);
        }

        DB::transaction(function () use ($visita) {
            $visita->update(['status' => 'omitida']);
            $this->renovarCiclo($visita->equipment, $visita->fecha_programada->toDateString());
        });

        return response()->json(['success' => true]);
    }

    // Convierte en una sola orden todo lo que toca ese día en esa propiedad
    public function convertir(Request $request, $id)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }
        $visita = $this->visitaPendiente($request, $id);
        if (!$visita) {
            return response()->json(['error' => 'Esta fecha ya fue atendida.'], 422);
        }

        $orden = DB::transaction(function () use ($visita, $request) {
            $fecha = $visita->fecha_programada->toDateString();
            $visitas = MaintenanceVisit::where('status', 'pendiente')
                ->whereDate('fecha_programada', $fecha)
                ->whereHas('equipment', fn ($q) => $q->where('property_id', $visita->equipment->property_id)->where('activo', true))
                ->with('equipment')
                ->lockForUpdate()
                ->get();

            $etiquetas = $visitas->map(fn ($v) => $v->equipment->etiqueta());
            $orden = WorkOrder::create([
                'tenant_id' => $visita->equipment->tenant_id,
                'property_id' => $visita->equipment->property_id,
                'type' => 'Mantenimiento',
                'zone' => $visitas->count() > 1 ? 'Varias zonas' : 'General',
                'equipment' => mb_strimwidth($etiquetas->implode(', '), 0, 150, '…'),
                'description' => "Mantenimiento programado.\n\n[EQUIPO AFECTADO]: " . $etiquetas->implode(', '),
                'status' => 'Por Hacer',
                'priority' => 'Normal',
                'publish_network' => 0,
            ]);

            foreach ($visitas as $v) {
                $orden->elementos()->create([
                    'property_component_id' => $v->equipment->property_component_id,
                    'maintenance_equipment_id' => $v->equipment->id,
                    'label' => $v->equipment->etiqueta(),
                ]);
                $v->update(['status' => 'convertida', 'work_order_id' => $orden->id]);
                $this->renovarCiclo($v->equipment, $fecha);
            }

            return $orden;
        });

        return response()->json(['success' => true, 'work_order_id' => $orden->id], 201);
    }
}
