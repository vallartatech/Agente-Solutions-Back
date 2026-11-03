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

// Mantenimiento de Agente Solutions (Root y Admin del equipo interno; el Técnico Agente puede agendar desde el
// levantamiento): cada equipo se elige del levantamiento de la propiedad o se agrega a mano, con su próxima fecha
// escrita o calculada por periodo (cada N meses desde el último mantenimiento), y esa fecha aparece en el calendario.
class MaintenanceController extends Controller
{
    private const ZONA_LOCAL = 'America/Merida';

    private function esAdmin(Request $request): bool
    {
        return in_array((int) $request->user()->role_id, [0, 1], true);
    }

    // Agendar y editar equipos también lo hace el Técnico Agente durante el levantamiento
    private function puedeProgramar(Request $request): bool
    {
        return in_array((int) $request->user()->role_id, [0, 1, 2], true);
    }

    private function reglasPeriodo(): array
    {
        return [
            'frecuencia_meses' => 'nullable|integer|in:' . implode(',', MaintenanceEquipment::FRECUENCIAS),
            'ultimo_mantenimiento' => 'nullable|date|before_or_equal:' . $this->hoy(),
        ];
    }

    // Próxima fecha: con periodo, último mantenimiento (o hoy) + N meses, aunque quede vencida;
    // una fecha escrita a mano no puede ser pasada, salvo que sea la que ya tenía ($actual).
    // Devuelve [fecha, error].
    private function proximaFecha(?string $escrita, ?int $frecuencia, ?string $ultimo, ?string $actual = null): array
    {
        $calculada = $frecuencia ? MaintenanceEquipment::siguienteFecha($ultimo ?: $this->hoy(), $frecuencia) : null;
        if (!$escrita) {
            return $calculada ? [$calculada, null] : [null, 'Indica la fecha del próximo mantenimiento o cada cuánto se hace.'];
        }

        $fecha = Carbon::parse($escrita)->toDateString();
        if ($fecha < $this->hoy() && $fecha !== $calculada && $fecha !== $actual) {
            return [null, 'La fecha debe ser de hoy en adelante.'];
        }
        return [$fecha, null];
    }

    // Root ve todo; los demás, solo lo de su equipo
    private function delEquipo($query, Request $request)
    {
        if ((int) $request->user()->role_id === 0) {
            return $query;
        }
        $tenantId = $request->user()->tenant_id ? (int) $request->user()->tenant_id : null;
        return WorkValidationController::mismoEquipo($query, $tenantId);
    }

    private function propiedad(Request $request, $id): ?Property
    {
        $query = Property::withoutGlobalScopes()->whereKey($id);
        return $this->delEquipo($query, $request)->first();
    }

    private function equiposDelEquipo(Request $request)
    {
        return $this->delEquipo(MaintenanceEquipment::query(), $request);
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

        return response()->json($this->delEquipo($query, $request)->get());
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
        if (!$this->puedeProgramar($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $validated = $request->validate([
            'property_id' => 'required|integer',
            'tipo' => 'required|string|max:150',
            'cantidad' => 'nullable|integer|min:1|max:1000',
            'detalle' => 'nullable|string|max:1000',
            'fecha' => 'required_without:frecuencia_meses|nullable|date',
            'property_component_id' => 'nullable|integer',
            'foto_equipo' => 'nullable|file|image|max:10240',
            'foto_datos' => 'nullable|file|image|max:10240',
        ] + $this->reglasPeriodo());

        $frecuencia = isset($validated['frecuencia_meses']) ? (int) $validated['frecuencia_meses'] : null;
        $ultimo = $validated['ultimo_mantenimiento'] ?? null;
        [$fecha, $error] = $this->proximaFecha($validated['fecha'] ?? null, $frecuencia, $ultimo);
        if ($error) {
            return response()->json(['error' => $error, 'errors' => ['fecha' => [$error]]], 422);
        }

        $propiedad = $this->propiedad($request, $validated['property_id']);
        if (!$propiedad) {
            return response()->json(['error' => 'Propiedad no encontrada.'], 404);
        }

        // Elegido del levantamiento: debe ser de esta propiedad y no tener ya un mantenimiento en la lista
        $componenteId = isset($validated['property_component_id']) ? (int) $validated['property_component_id'] : null;
        if ($componenteId) {
            if (ElementosTrabajo::delLevantamiento($propiedad->id, [$componenteId])->isEmpty()) {
                return response()->json(['error' => 'Ese elemento no pertenece a esta propiedad.'], 422);
            }
            if (MaintenanceEquipment::where('property_component_id', $componenteId)->where('activo', true)->exists()) {
                return response()->json(['error' => 'Ese elemento ya tiene un mantenimiento agendado. Edítalo desde la lista.'], 422);
            }
        }

        $fotos = app(FotosMantenimiento::class);
        $urls = [];
        foreach (['foto_equipo', 'foto_datos'] as $campo) {
            if ($request->hasFile($campo)) {
                $urls["{$campo}_url"] = $fotos->subir($request->file($campo));
            }
        }

        $equipo = DB::transaction(function () use ($validated, $propiedad, $urls, $request, $componenteId, $fecha, $frecuencia, $ultimo) {
            $equipo = MaintenanceEquipment::create($urls + [
                'tenant_id' => $propiedad->tenant_id,
                'property_id' => $propiedad->id,
                'property_component_id' => $componenteId,
                'tipo' => trim($validated['tipo']),
                'cantidad' => $validated['cantidad'] ?? 1,
                'detalle' => $validated['detalle'] ?? null,
                'fecha_base' => $this->hoy(),
                'frecuencia_meses' => $frecuencia,
                'ultimo_mantenimiento' => $ultimo,
                'created_by' => $request->user()->id,
            ]);
            $equipo->visits()->create(['fecha_programada' => $fecha, 'status' => 'pendiente']);
            return $equipo;
        });

        return response()->json(['success' => true, 'equipo' => $equipo->load('visits')], 201);
    }

    // Elementos del levantamiento de una propiedad, para elegirlos en una solicitud
    public function elementosDePropiedad(Request $request, $id)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }
        if (!$this->propiedad($request, $id)) {
            return response()->json(['error' => 'Propiedad no encontrada.'], 404);
        }

        return response()->json(ElementosTrabajo::delLevantamiento((int) $id)->values());
    }

    // Mantenimientos programados de una propiedad, para su expediente
    public function dePropiedad(Request $request, $id)
    {
        if (!$this->puedeProgramar($request)) {
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

    // Ajusta los datos de un equipo, su periodo y su próxima fecha (o pone una nueva si la anterior ya se atendió)
    public function actualizar(Request $request, $id)
    {
        if (!$this->puedeProgramar($request)) {
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
            'fecha' => 'required_without:frecuencia_meses|nullable|date',
            'foto_equipo' => 'nullable|file|image|max:10240',
            'foto_datos' => 'nullable|file|image|max:10240',
        ] + $this->reglasPeriodo());

        // Los campos del periodo que no vengan se quedan como estaban
        $frecuencia = $request->exists('frecuencia_meses')
            ? (isset($validated['frecuencia_meses']) ? (int) $validated['frecuencia_meses'] : null)
            : $equipo->frecuencia_meses;
        $ultimo = $request->exists('ultimo_mantenimiento')
            ? ($validated['ultimo_mantenimiento'] ?? null)
            : $equipo->ultimo_mantenimiento?->toDateString();

        $pendiente = $equipo->visits()->where('status', 'pendiente')->first();
        $actual = $pendiente?->fecha_programada->toDateString();
        [$fecha, $error] = $this->proximaFecha($validated['fecha'] ?? null, $frecuencia, $ultimo, $actual);
        if ($error) {
            return response()->json(['error' => $error, 'errors' => ['fecha' => [$error]]], 422);
        }
        $cambiaFecha = $actual !== $fecha;
        // Con una orden de mantenimiento en curso no se agenda otra fecha calculada: la siguiente sale al cerrarla
        if ($cambiaFecha && $actual === null && empty($validated['fecha']) && $equipo->visits()->where('status', 'convertida')->exists()) {
            $cambiaFecha = false;
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

        DB::transaction(function () use ($equipo, $validated, $urls, $cambiaFecha, $fecha, $frecuencia, $ultimo) {
            $equipo->update($urls + [
                'tipo' => trim($validated['tipo']),
                'cantidad' => $validated['cantidad'] ?? 1,
                'detalle' => $validated['detalle'] ?? null,
                'frecuencia_meses' => $frecuencia,
                'ultimo_mantenimiento' => $ultimo,
            ]);
            if ($cambiaFecha) {
                $equipo->visits()->where('status', 'pendiente')->delete();
                $equipo->visits()->create(['fecha_programada' => $fecha, 'status' => 'pendiente']);
            }
        });

        return response()->json(['success' => true, 'equipo' => $equipo->load(['visits' => fn ($q) => $q->where('status', 'pendiente')])]);
    }

    // Varios elementos del levantamiento (ej. 3 climas de distintas habitaciones) con el mismo periodo y la
    // misma próxima fecha; al convertir esa fecha salen en una sola orden. Los que ya estaban se reprograman.
    public function programarVarios(Request $request)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $validated = $request->validate([
            'property_id' => 'required|integer',
            'elementos' => 'required|array|min:1|max:200',
            'elementos.*' => 'integer|distinct',
            'fecha' => 'nullable|date',
            'frecuencia_meses' => 'required|integer|in:' . implode(',', MaintenanceEquipment::FRECUENCIAS),
            'ultimo_mantenimiento' => 'nullable|date|before_or_equal:' . $this->hoy(),
        ]);

        $propiedad = $this->propiedad($request, $validated['property_id']);
        if (!$propiedad) {
            return response()->json(['error' => 'Propiedad no encontrada.'], 404);
        }
        $ids = array_map('intval', $validated['elementos']);
        $componentes = ElementosTrabajo::delLevantamiento($propiedad->id, $ids);
        if ($componentes->count() !== count($ids)) {
            return response()->json(['error' => 'Algún elemento no pertenece a esta propiedad.'], 422);
        }

        $frecuencia = (int) $validated['frecuencia_meses'];
        $ultimo = $validated['ultimo_mantenimiento'] ?? null;
        [$fecha, $error] = $this->proximaFecha($validated['fecha'] ?? null, $frecuencia, $ultimo);
        if ($error) {
            return response()->json(['error' => $error, 'errors' => ['fecha' => [$error]]], 422);
        }

        $ids = DB::transaction(function () use ($componentes, $propiedad, $request, $frecuencia, $ultimo, $fecha) {
            return $componentes->map(function ($c) use ($propiedad, $request, $frecuencia, $ultimo, $fecha) {
                $equipo = MaintenanceEquipment::where('property_component_id', $c->id)->where('activo', true)->first()
                    ?? MaintenanceEquipment::create([
                        'tenant_id' => $propiedad->tenant_id,
                        'property_id' => $propiedad->id,
                        'property_component_id' => $c->id,
                        'tipo' => mb_substr($c->label, 0, 150),
                        'cantidad' => 1,
                        'fecha_base' => $this->hoy(),
                        'created_by' => $request->user()->id,
                    ]);
                $equipo->update(['frecuencia_meses' => $frecuencia, 'ultimo_mantenimiento' => $ultimo]);
                $equipo->reprogramar($fecha);
                return $equipo->id;
            });
        });

        return response()->json(['success' => true, 'fecha' => $fecha, 'equipos' => $this->conProximaFecha($ids)]);
    }

    // "Ya se realizó": la fecha pendiente queda como realizada (aunque esté vencida) y el ciclo vuelve a contar
    // desde ese día. Sin periodo, se puede indicar la próxima fecha a mano.
    public function realizado(Request $request)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $validated = $request->validate([
            'equipos' => 'required|array|min:1|max:200',
            'equipos.*' => 'integer|distinct',
            'fecha' => 'nullable|date|before_or_equal:' . $this->hoy(),
            'proxima' => 'nullable|date|after_or_equal:' . $this->hoy(),
        ]);

        $equipos = $this->equiposDelEquipo($request)->where('activo', true)->whereIn('id', $validated['equipos'])->get();
        if ($equipos->count() !== count($validated['equipos'])) {
            return response()->json(['error' => 'Algún equipo no se encontró.'], 404);
        }

        $fecha = isset($validated['fecha']) ? Carbon::parse($validated['fecha'])->toDateString() : $this->hoy();
        $proxima = isset($validated['proxima']) ? Carbon::parse($validated['proxima'])->toDateString() : null;

        DB::transaction(function () use ($equipos, $fecha, $proxima) {
            foreach ($equipos as $equipo) {
                $equipo->registrarRealizado($fecha);
                if (!$equipo->frecuencia_meses && $proxima) {
                    $equipo->reprogramar($proxima);
                }
            }
        });

        return response()->json(['success' => true, 'equipos' => $this->conProximaFecha($equipos->pluck('id'))]);
    }

    private function conProximaFecha($ids)
    {
        return MaintenanceEquipment::with(['property:id,property_name,address', 'visits' => fn ($q) => $q->where('status', 'pendiente')])
            ->whereIn('id', $ids)
            ->get();
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
            ->whereHas('equipment', fn ($q) => $this->delEquipo($q->where('activo', true), $request))
            ->with('equipment.property:id,property_name,address')
            ->orderBy('fecha_programada')
            ->get();

        return response()->json($visitas);
    }

    private function visitaPendiente(Request $request, $id): ?MaintenanceVisit
    {
        return MaintenanceVisit::where('status', 'pendiente')
            ->whereHas('equipment', fn ($q) => $this->delEquipo($q, $request))
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
