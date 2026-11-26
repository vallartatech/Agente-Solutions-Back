<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Market: el cliente ve en su mapa al técnico que va en camino a su trabajo.
 *
 * - Técnico: después de "Iniciar ruta", su app manda la ubicación cada ~15 s mientras está abierta.
 * - Cliente: su Mercado consulta los técnicos que van en camino a sus trabajos.
 * - Solo se comparte de "Iniciar ruta" a "Llegué al lugar"; al llegar, cancelarse o pasar
 *   mucho tiempo sin datos, la fila se borra (no queda historial del recorrido).
 */
class SeguimientoTecnicoController extends Controller
{
    private const TABLA = 'ubicaciones_en_ruta';
    // Si el técnico no manda nada en este tiempo, deja de mostrarse (cerró la app o terminó el día)
    private const CADUCA_MIN = 120;

    private static function listo(): bool
    {
        static $listo = null;
        return $listo ??= Schema::hasTable(self::TABLA);
    }

    /** El trabajo sigue esperando al técnico: asignado y sin "Llegué al lugar". */
    private static function vaEnCamino(?WorkOrder $orden): bool
    {
        return $orden
            && $orden->status === 'Asignado'
            && empty($orden->arrived_at)
            && $orden->arrival_status !== 'EN_SITIO';
    }

    /** Coordenadas de la propiedad ("lat,lng"), o null si no tiene. */
    private static function destino(WorkOrder $orden): ?array
    {
        $coords = $orden->property?->coordinates;
        if (!$coords) return null;
        $partes = explode(',', $coords);
        if (count($partes) < 2) return null;
        $lat = (float) trim($partes[0]);
        $lng = (float) trim($partes[1]);
        return ($lat != 0 && $lng != 0) ? ['lat' => $lat, 'lng' => $lng] : null;
    }

    // POST /mercado-trabajos/{id}/ubicacion — el técnico en camino manda dónde va
    public function guardar(Request $request, $id)
    {
        if (!self::listo()) return response()->json(['success' => true, 'compartiendo' => false]);

        $datos = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'precision_m' => 'nullable|numeric|min:0|max:100000',
        ]);

        $user = $request->user();
        $orden = WorkOrder::withoutGlobalScopes()->find($id);
        if (!$orden) return response()->json(['success' => false, 'message' => 'Trabajo no encontrado'], 404);
        if (!AccesoQrRedController::esTecnicoDelTrabajo($user, $orden)) {
            return response()->json(['success' => false, 'message' => 'Este trabajo no es tuyo.'], 403);
        }

        // Ya llegó o el trabajo cambió: se deja de compartir y la app del técnico se detiene
        if (!self::vaEnCamino($orden)) {
            DB::table(self::TABLA)->where('work_order_id', $orden->id)->delete();
            return response()->json(['success' => true, 'compartiendo' => false]);
        }

        // "Iniciar ruta" queda registrado aunque la app no lo haya mandado antes
        if (Schema::hasColumn('work_orders', 'ruta_iniciada_at') && !$orden->ruta_iniciada_at) {
            $orden->forceFill(['ruta_iniciada_at' => now('America/Merida')->format('Y-m-d H:i:s')])->save();
        }

        $ahora = now();
        DB::table(self::TABLA)->updateOrInsert(
            ['work_order_id' => $orden->id],
            [
                'technician_id' => $user->id,
                'lat' => round((float) $datos['lat'], 7),
                'lng' => round((float) $datos['lng'], 7),
                'precision_m' => isset($datos['precision_m']) ? round((float) $datos['precision_m'], 1) : null,
                'updated_at' => $ahora,
                'created_at' => $ahora,
            ]
        );

        return response()->json(['success' => true, 'compartiendo' => true]);
    }

    // DELETE /mercado-trabajos/{id}/ubicacion — el técnico cerró la ruta o marcó "Llegué"
    public function detener(Request $request, $id)
    {
        if (!self::listo()) return response()->json(['success' => true]);
        $orden = WorkOrder::withoutGlobalScopes()->find($id);
        if ($orden && AccesoQrRedController::esTecnicoDelTrabajo($request->user(), $orden)) {
            DB::table(self::TABLA)->where('work_order_id', $orden->id)->delete();
        }
        return response()->json(['success' => true]);
    }

    // GET /seguimiento-tecnicos — técnicos que van en camino a los trabajos del cliente
    public function enCaminoDelCliente(Request $request)
    {
        if (!self::listo()) return response()->json(['success' => true, 'data' => []]);

        $user = $request->user();
        // Los que dejaron de mandar ubicación hace mucho ya no se muestran
        DB::table(self::TABLA)->where('updated_at', '<', now()->subMinutes(self::CADUCA_MIN))->delete();

        $filas = DB::table(self::TABLA)->get()->keyBy('work_order_id');
        if ($filas->isEmpty()) return response()->json(['success' => true, 'data' => []]);

        $ordenes = WorkOrder::withoutGlobalScopes()
            ->with(['property' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereIn('id', $filas->keys())
            ->get();

        $resultado = [];
        foreach ($ordenes as $orden) {
            if (!self::vaEnCamino($orden)) {
                DB::table(self::TABLA)->where('work_order_id', $orden->id)->delete();
                continue;
            }
            if (!AjusteCotizacionController::puedeResponderPorDueno($user, $orden)) continue;

            $fila = $filas[$orden->id];
            $tecnico = User::withoutGlobalScopes()->find($fila->technician_id);
            $nombre = $tecnico
                ? (trim(($tecnico->first_name ?? '') . ' ' . ($tecnico->last_name ?? '')) ?: ($tecnico->name ?? 'Técnico'))
                : 'Técnico';

            $resultado[] = [
                'work_order_id' => (int) $orden->id,
                'titulo' => $orden->type . ($orden->equipment ? ' - ' . $orden->equipment : ''),
                'propiedad' => $orden->property ? ($orden->property->nombre_propiedad ?: $orden->property->address) : '',
                'property_id' => $orden->property_id ? (int) $orden->property_id : null,
                'tecnico' => [
                    'id' => $tecnico ? (int) $tecnico->id : null,
                    'nombre' => $nombre,
                    'foto' => $tecnico->profile_picture ?? null,
                ],
                'ubicacion' => [
                    'lat' => (float) $fila->lat,
                    'lng' => (float) $fila->lng,
                    'precision_m' => $fila->precision_m !== null ? (float) $fila->precision_m : null,
                ],
                'destino' => self::destino($orden),
                'actualizado_hace_s' => (int) max(0, now()->diffInSeconds(\Carbon\Carbon::parse($fila->updated_at), true)),
            ];
        }

        return response()->json(['success' => true, 'data' => $resultado]);
    }
}
