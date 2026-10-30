<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceEquipment;
use App\Models\MaintenanceVisit;
use App\Models\Property;
use App\Models\WorkOrder;
use App\Models\WorkOrderComponent;
use App\Services\FotosMantenimiento;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Mantenimiento de Agente Solutions (solo Root y Admin del equipo interno): cada equipo se agrega a mano
// con su próxima fecha, sin depender del levantamiento, y esa fecha aparece en el calendario.
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

    private function hoy(): string
    {
        return Carbon::now(self::ZONA_LOCAL)->toDateString();
    }

    // Elementos de una orden, para la galería de evidencias y el reporte oficial
    public function elementosDeOrden($id)
    {
        return response()->json(WorkOrderComponent::where('work_order_id', (int) $id)->orderBy('id')->get(['id', 'label']));
    }

    // Propiedades del equipo interno, para el formulario
    public function propiedades(Request $request)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $query = Property::withoutGlobalScopes()->select('id', 'property_name', 'address')->orderBy('property_name');

        return response()->json(WorkValidationController::mismoEquipo($query, $this->tenantId($request))->get());
    }

    // Todos los equipos agregados, con su próxima fecha
    public function equipos(Request $request)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        return response()->json(
            $this->equiposDelEquipo($request)
                ->with([
                    'property:id,property_name,address',
                    'visits' => fn ($q) => $q->where('status', 'pendiente'),
                ])
                ->where('activo', true)
                ->orderByDesc('id')
                ->get()
        );
    }

    // Agrega un equipo con la fecha de su próximo mantenimiento
    public function agregar(Request $request)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $validated = $request->validate([
            'property_id' => 'required|integer',
            'tipo' => 'required|string|max:150',
            'cantidad' => 'nullable|integer|min:1|max:1000',
            'detalle' => 'nullable|string|max:1000',
            'fecha' => 'required|date|after_or_equal:' . $this->hoy(),
            'foto_equipo' => 'nullable|file|image|max:10240',
            'foto_datos' => 'nullable|file|image|max:10240',
        ]);

        $propiedad = $this->propiedad($request, $validated['property_id']);
        if (!$propiedad) {
            return response()->json(['error' => 'Propiedad no encontrada.'], 404);
        }

        $fotos = app(FotosMantenimiento::class);
        $urls = [];
        foreach (['foto_equipo', 'foto_datos'] as $campo) {
            if ($request->hasFile($campo)) {
                $urls["{$campo}_url"] = $fotos->subir($request->file($campo));
            }
        }

        $equipo = DB::transaction(function () use ($validated, $propiedad, $urls, $request) {
            $equipo = MaintenanceEquipment::create($urls + [
                'tenant_id' => $propiedad->tenant_id,
                'property_id' => $propiedad->id,
                'tipo' => trim($validated['tipo']),
                'cantidad' => $validated['cantidad'] ?? 1,
                'detalle' => $validated['detalle'] ?? null,
                'fecha_base' => $this->hoy(),
                'created_by' => $request->user()->id,
            ]);
            $equipo->visits()->create(['fecha_programada' => $validated['fecha'], 'status' => 'pendiente']);
            return $equipo;
        });

        return response()->json(['success' => true, 'equipo' => $equipo->load('visits')], 201);
    }

    // Mantenimientos programados de una propiedad, para su expediente
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
                ->get()
        );
    }

    // Ajusta los datos de un equipo y su próxima fecha (o pone una nueva si la anterior ya se atendió)
    public function actualizar(Request $request, $id)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }
        $equipo = $this->equiposDelEquipo($request)->where('activo', true)->find($id);
        if (!$equipo) {
            return response()->json(['error' => 'Equipo no encontrado.'], 404);
        }

        $validated = $request->validate([
            'tipo' => 'required|string|max:150',
            'cantidad' => 'nullable|integer|min:1|max:1000',
            'detalle' => 'nullable|string|max:1000',
            'fecha' => 'required|date',
            'foto_equipo' => 'nullable|file|image|max:10240',
            'foto_datos' => 'nullable|file|image|max:10240',
        ]);

        $fecha = Carbon::parse($validated['fecha'])->toDateString();
        $pendiente = $equipo->visits()->where('status', 'pendiente')->first();
        $cambiaFecha = $pendiente?->fecha_programada->toDateString() !== $fecha;

        // Una fecha vencida puede quedarse al corregir otros datos, pero no se puede agendar una nueva en el pasado
        if ($cambiaFecha && $fecha < $this->hoy()) {
            return response()->json(['error' => 'La fecha debe ser de hoy en adelante.'], 422);
        }
        if ($cambiaFecha && $equipo->visits()->where('status', '!=', 'pendiente')->whereDate('fecha_programada', $fecha)->exists()) {
            return response()->json(['error' => 'Esa fecha ya se atendió para este equipo. Elige otra.'], 422);
        }

        $fotos = app(FotosMantenimiento::class);
        $urls = [];
        foreach (['foto_equipo', 'foto_datos'] as $campo) {
            if ($request->hasFile($campo)) {
                $urls["{$campo}_url"] = $fotos->subir($request->file($campo));
            }
        }

        DB::transaction(function () use ($equipo, $validated, $urls, $cambiaFecha, $fecha) {
            $equipo->update($urls + [
                'tipo' => trim($validated['tipo']),
                'cantidad' => $validated['cantidad'] ?? 1,
                'detalle' => $validated['detalle'] ?? null,
            ]);
            if ($cambiaFecha) {
                $equipo->visits()->where('status', 'pendiente')->delete();
                $equipo->visits()->create(['fecha_programada' => $fecha, 'status' => 'pendiente']);
            }
        });

        return response()->json(['success' => true, 'equipo' => $equipo->load(['visits' => fn ($q) => $q->where('status', 'pendiente')])]);
    }

    // Quita un equipo de la lista: sus fechas pendientes desaparecen de la agenda
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

    public function omitir(Request $request, $id)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }
        $visita = $this->visitaPendiente($request, $id);
        if (!$visita) {
            return response()->json(['error' => 'Esta fecha ya fue atendida.'], 422);
        }

        $visita->update(['status' => 'omitida']);

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

        // Una orden siempre pertenece a una propiedad registrada
        if (!$visita->equipment->property_id) {
            return response()->json(['error' => 'Este equipo no tiene una propiedad registrada, así que no se puede convertir en orden.'], 422);
        }

        $orden = DB::transaction(function () use ($visita) {
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
                    'maintenance_equipment_id' => $v->equipment->id,
                    'label' => $v->equipment->etiqueta(),
                ]);
                $v->update(['status' => 'convertida', 'work_order_id' => $orden->id]);
            }

            return $orden;
        });

        return response()->json(['success' => true, 'work_order_id' => $orden->id], 201);
    }
}
