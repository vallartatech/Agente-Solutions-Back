<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceVisit;
use App\Models\Quote;
use App\Models\WorkOrder;
use App\Models\WorkOrderComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Carrito de una orden con varios equipos: cada equipo tiene su propia cotización y el admin
// elige cuáles contrata el cliente. Puede cambiar la elección hasta finalizar; después es definitiva.
class CarritoCotizacionController extends Controller
{
    // Cotizaciones que ya no cuentan como la vigente de un equipo
    private const DESCARTADAS = ['Recotizada', 'Rechazado', 'Rechazada'];
    private const PAGADAS = ['Pagado', 'pagado'];

    private function esAdmin(Request $request): bool
    {
        return in_array((int) optional($request->user())->role_id, [0, 1], true);
    }

    // La orden con el filtro de empresa del usuario (TenantScoped)
    private function orden($id): ?WorkOrder
    {
        return WorkOrder::find((int) $id);
    }

    // La cotización que cuenta para un equipo: la más reciente que no se descartó
    // (la final del admin se crea después de la propuesta del técnico, así que gana)
    public static function vigente($cotizaciones)
    {
        return $cotizaciones
            ->reject(fn ($c) => in_array($c->status, self::DESCARTADAS, true))
            ->sortByDesc('id')
            ->first();
    }

    private function cotizacion(?Quote $c): ?array
    {
        if (!$c) {
            return null;
        }

        return [
            'id' => $c->id,
            'status' => $c->status,
            'payment_status' => $c->payment_status,
            'total' => $c->estimated_amount,
            'type' => $c->type,
            'file_path' => $c->file_path,
            'concept' => $c->concept,
            'observations' => $c->observations,
            'created_by_role' => $c->created_by_role ?? 'Admin',
            'parent_id' => $c->parent_id,
            'created_at' => optional($c->created_at)->toIso8601String(),
        ];
    }

    public function show(Request $request, $id)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }
        $orden = $this->orden($id);
        if (!$orden) {
            return response()->json(['error' => 'Orden no encontrada.'], 404);
        }

        $elementos = WorkOrderComponent::where('work_order_id', $orden->id)->orderBy('id')->get();
        $cotizaciones = Quote::withoutGlobalScopes()->where('work_order_id', $orden->id)->get()->groupBy('work_order_component_id');

        return response()->json([
            'finalizado_at' => optional($orden->carrito_finalizado_at ? \Carbon\Carbon::parse($orden->carrito_finalizado_at) : null)->toIso8601String(),
            'elementos' => $elementos->map(function ($e) use ($cotizaciones) {
                $suyas = $cotizaciones->get($e->id, collect());
                return [
                    'id' => $e->id,
                    'label' => $e->label,
                    'estado' => $e->estado ?? 'pendiente',
                    'cotizacion' => $this->cotizacion(self::vigente($suyas)),
                    'cotizaciones' => $suyas->sortBy('id')->map(fn ($c) => $this->cotizacion($c))->values(),
                ];
            })->values(),
        ]);
    }

    // Guarda la elección sin cerrarla (se puede cambiar cuantas veces se quiera)
    public function guardar(Request $request, $id)
    {
        return $this->aplicar($request, $id, false);
    }

    // Cierra el carrito: lo elegido queda aprobado y lo demás como "No aceptada". Ya no se puede editar.
    public function finalizar(Request $request, $id)
    {
        return $this->aplicar($request, $id, true);
    }

    private function aplicar(Request $request, $id, bool $finalizar)
    {
        if (!$this->esAdmin($request)) {
            return response()->json(['error' => 'Solo un administrador decide qué equipos se contratan.'], 403);
        }
        $request->validate([
            'aceptados' => 'present|array',
            'aceptados.*' => 'integer',
        ]);

        $orden = $this->orden($id);
        if (!$orden) {
            return response()->json(['error' => 'Orden no encontrada.'], 404);
        }
        if ($orden->carrito_finalizado_at) {
            return response()->json(['error' => 'El carrito ya se finalizó y no se puede modificar.'], 409);
        }

        $elementos = WorkOrderComponent::where('work_order_id', $orden->id)->get();
        $aceptados = collect($request->input('aceptados'))->map(fn ($v) => (int) $v)->unique();
        if ($aceptados->diff($elementos->pluck('id'))->isNotEmpty()) {
            return response()->json(['error' => 'Alguno de los equipos no pertenece a esta orden.'], 422);
        }

        $cotizaciones = Quote::withoutGlobalScopes()->where('work_order_id', $orden->id)->get()->groupBy('work_order_component_id');

        if ($finalizar) {
            if ($aceptados->isEmpty()) {
                return response()->json(['error' => 'Elige al menos un equipo. Si el cliente no quiere ninguno, cancela el trabajo.'], 422);
            }
            // Cada equipo debe tener su cotización: la de los no contratados queda como evidencia
            $sinCotizacion = $elementos->filter(fn ($e) => !self::vigente($cotizaciones->get($e->id, collect())))->pluck('label');
            if ($sinCotizacion->isNotEmpty()) {
                return response()->json([
                    'error' => 'Falta la cotización de: ' . $sinCotizacion->implode(', ') . '.',
                    'sin_cotizacion' => $sinCotizacion->values(),
                ], 422);
            }
        }

        DB::transaction(function () use ($orden, $elementos, $aceptados, $cotizaciones, $finalizar, $request) {
            foreach ($elementos as $elemento) {
                $contratado = $aceptados->contains($elemento->id);
                $elemento->update(['estado' => $contratado ? 'aceptado' : ($finalizar ? 'no_aceptado' : 'pendiente')]);

                if (!$finalizar) {
                    continue;
                }

                $suyas = $cotizaciones->get($elemento->id, collect());
                if ($contratado) {
                    // La vigente queda aprobada (si ya estaba aprobada o pagada, se respeta)
                    $vigente = self::vigente($suyas);
                    if ($vigente && !in_array($vigente->status, array_merge(['Aprobado'], self::PAGADAS), true)) {
                        $vigente->update(['status' => 'Aprobado']);
                    }
                } else {
                    // Se conservan como evidencia; no cuentan para el pago
                    $suyas->reject(fn ($c) => in_array($c->status, array_merge(self::DESCARTADAS, self::PAGADAS), true))
                        ->each(fn ($c) => $c->update(['status' => 'No aceptada']));

                    // Su fecha de mantenimiento sigue pendiente: esta orden ya no la atiende
                    if ($elemento->maintenance_equipment_id) {
                        MaintenanceVisit::where('maintenance_equipment_id', $elemento->maintenance_equipment_id)
                            ->where('work_order_id', $orden->id)
                            ->where('status', 'convertida')
                            ->update(['status' => 'pendiente', 'work_order_id' => null]);
                    }
                }
            }

            if ($finalizar) {
                $orden->forceFill([
                    'carrito_finalizado_at' => now(),
                    'carrito_finalizado_por' => optional($request->user())->id,
                ])->save();
            }
        });

        return $this->show($request, $orden->id);
    }
}
