<?php

namespace App\Http\Controllers;

use App\Models\ClientTechnicianRelation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Mis técnicos" del cliente particular (rol 4): historial de técnicos que trabajaron con él
 * o le cotizaron en la red, para marcarlos como favoritos o bloquearlos.
 *
 * Las consultas van con DB::table a propósito: el TenantScope filtraría propiedades y clientes
 * por el tenant del usuario conectado (el técnico, al revisar bloqueos).
 */
class MisTecnicosController extends Controller
{
    private const ROL_CLIENTE_PARTICULAR = 4;

    private const NOMBRES_ROL = [
        2 => 'Técnico',
        6 => 'Técnico independiente',
        7 => 'Contratista',
        8 => 'Técnico de cuadrilla',
    ];

    // IDs de las órdenes del cliente: por el cliente ligado a su usuario o por su tenant
    private static function idsOrdenesDe(User $user)
    {
        return DB::table('work_orders')
            ->leftJoin('properties', 'properties.id', '=', 'work_orders.property_id')
            ->leftJoin('clients', 'clients.id', '=', 'properties.client_id')
            ->where(function ($q) use ($user) {
                $q->where('clients.user_id', $user->id);
                if ($user->tenant_id) {
                    $q->orWhere('work_orders.tenant_id', $user->tenant_id);
                }
            })
            ->pluck('work_orders.id');
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if ((int) $user->role_id !== self::ROL_CLIENTE_PARTICULAR) {
            return response()->json(['message' => 'Solo disponible para clientes particulares.'], 403);
        }

        $idsOrdenes = self::idsOrdenesDe($user);

        $ordenes = DB::table('work_orders')
            ->whereIn('id', $idsOrdenes)
            ->orderByDesc('created_at')
            ->get(['id', 'zone', 'equipment', 'status', 'tecnico_id', 'created_at']);

        $equipo = DB::table('work_order_technician')->whereIn('work_order_id', $idsOrdenes)->get(['work_order_id', 'technician_id'])
            ->groupBy('work_order_id');
        $cotizaciones = DB::table('network_quotes')->whereIn('work_order_id', $idsOrdenes)->get(['work_order_id', 'technician_id', 'status'])
            ->groupBy('work_order_id');

        $porTecnico = [];
        foreach ($ordenes as $o) {
            $cotis = collect($cotizaciones->get($o->id, []));
            $asignados = collect([$o->tecnico_id])
                ->merge(collect($equipo->get($o->id, []))->pluck('technician_id'))
                ->merge($cotis->where('status', 'accepted')->pluck('technician_id'))
                ->filter()->map(fn ($i) => (int) $i)->unique();
            $cotizaron = $cotis->pluck('technician_id')->filter()->map(fn ($i) => (int) $i)->unique();

            foreach ($asignados->merge($cotizaron)->unique() as $tid) {
                if ($tid === (int) $user->id) continue;
                $trabajo = $asignados->contains($tid);
                $porTecnico[$tid] ??= ['trabajos' => 0, 'cotizaciones' => 0, 'ultima_fecha' => $o->created_at, 'servicios' => []];
                if ($trabajo) $porTecnico[$tid]['trabajos']++;
                if ($cotizaron->contains($tid)) $porTecnico[$tid]['cotizaciones']++;
                // Las órdenes vienen de la más nueva a la más vieja: se guardan las 5 últimas
                if (count($porTecnico[$tid]['servicios']) < 5) {
                    $porTecnico[$tid]['servicios'][] = [
                        'id' => $o->id,
                        'titulo' => trim(($o->zone ?: 'Servicio') . ' - ' . ($o->equipment ?: 'General')),
                        'estado' => $o->status,
                        'fecha' => $o->created_at,
                        'trabajo' => $trabajo,
                    ];
                }
            }
        }

        $relaciones = ClientTechnicianRelation::where('client_user_id', $user->id)
            ->where('set_by', ClientTechnicianRelation::POR_CLIENTE)->pluck('status', 'technician_id');
        $ids = collect(array_keys($porTecnico))->merge($relaciones->keys())->map(fn ($i) => (int) $i)->unique();

        // Solo datos públicos del técnico: sin correo, teléfono ni dirección
        $tecnicos = User::withoutGlobalScopes()
            ->with(['specialties' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereIn('id', $ids)
            ->get()
            ->map(function ($t) use ($porTecnico, $relaciones) {
                $h = $porTecnico[$t->id] ?? ['trabajos' => 0, 'cotizaciones' => 0, 'ultima_fecha' => null, 'servicios' => []];
                return [
                    'id' => $t->id,
                    'nombre' => $t->name ?: 'Técnico',
                    'foto' => $t->profile_picture,
                    'rol' => self::NOMBRES_ROL[(int) $t->role_id] ?? 'Técnico',
                    'especialidades' => $t->specialties->map(fn ($s) => $s->pivot->custom_name ?: $s->name)->filter()->values(),
                    'calificacion' => $t->total_reviews_count ? (float) $t->rating_stars_avg : null,
                    'puntualidad' => $t->total_reviews_count ? (float) $t->rating_time_avg : null,
                    'resenas' => (int) $t->total_reviews_count,
                    'miembro_desde' => optional($t->created_at)->toDateString(),
                    'trabajos_contigo' => $h['trabajos'],
                    'cotizaciones' => $h['cotizaciones'],
                    'ultima_fecha' => $h['ultima_fecha'],
                    'servicios' => $h['servicios'],
                    'estado' => $relaciones[$t->id] ?? null,
                ];
            })
            // Favoritos primero, luego por fecha más reciente; los bloqueados al final
            ->sortBy(fn ($t) => [
                $t['estado'] === ClientTechnicianRelation::FAVORITO ? 0 : ($t['estado'] === ClientTechnicianRelation::BLOQUEADO ? 2 : 1),
                -strtotime((string) ($t['ultima_fecha'] ?? '1970-01-01')),
            ])
            ->values();

        return response()->json(['success' => true, 'tecnicos' => $tecnicos]);
    }

    // estado: "favorito", "bloqueado" o null para quitar la marca
    public function marcar(Request $request, $tecnicoId)
    {
        $data = $request->validate(['estado' => 'nullable|in:favorito,bloqueado']);
        $user = $request->user();
        $tecnicoId = (int) $tecnicoId;

        if ((int) $user->role_id !== self::ROL_CLIENTE_PARTICULAR) {
            return response()->json(['message' => 'Solo disponible para clientes particulares.'], 403);
        }
        if ($tecnicoId === (int) $user->id || !User::withoutGlobalScopes()->whereKey($tecnicoId)->exists()) {
            return response()->json(['message' => 'Técnico no válido.'], 422);
        }

        $relacion = ClientTechnicianRelation::where('client_user_id', $user->id)->where('technician_id', $tecnicoId)
            ->where('set_by', ClientTechnicianRelation::POR_CLIENTE)->first();
        if (!$relacion && !$this->esDeSuHistorial($user, $tecnicoId)) {
            return response()->json(['message' => 'Este técnico no ha trabajado contigo.'], 403);
        }

        if (empty($data['estado'])) {
            $relacion?->delete();
            return response()->json(['success' => true, 'estado' => null]);
        }

        ClientTechnicianRelation::updateOrCreate(
            ['client_user_id' => $user->id, 'technician_id' => $tecnicoId, 'set_by' => ClientTechnicianRelation::POR_CLIENTE],
            ['status' => $data['estado'], 'tenant_id' => ClientTechnicianRelation::tenantDeBloqueo($user->tenant_id)]
        );

        return response()->json(['success' => true, 'estado' => $data['estado']]);
    }

    private function esDeSuHistorial(User $user, int $tecnicoId): bool
    {
        $ids = self::idsOrdenesDe($user);
        return DB::table('work_orders')->whereIn('id', $ids)->where('tecnico_id', $tecnicoId)->exists()
            || DB::table('work_order_technician')->whereIn('work_order_id', $ids)->where('technician_id', $tecnicoId)->exists()
            || DB::table('network_quotes')->whereIn('work_order_id', $ids)->where('technician_id', $tecnicoId)->exists();
    }

    // ── Bloqueos: los usan las rutas de la red ──
    // Cuentan los dos sentidos: el cliente bloqueó al técnico o el técnico bloqueó al cliente.

    private static function bloqueosDe(int $tecnicoId)
    {
        return ClientTechnicianRelation::where('technician_id', $tecnicoId)
            ->where('status', ClientTechnicianRelation::BLOQUEADO)
            ->get(['client_user_id', 'tenant_id']);
    }

    // Quita de una consulta de órdenes las de clientes bloqueados con el técnico (en cualquier sentido)
    public static function excluirBloqueados($query, ?int $tecnicoId): void
    {
        if (!$tecnicoId) return;
        $bloqueos = self::bloqueosDe($tecnicoId);
        if ($bloqueos->isEmpty()) return;

        $userIds = $bloqueos->pluck('client_user_id')->filter()->unique()->values()->all();
        $tenantIds = $bloqueos->pluck('tenant_id')->filter()->unique()->values()->all();

        $idsBloqueadas = DB::table('work_orders')
            ->leftJoin('properties', 'properties.id', '=', 'work_orders.property_id')
            ->leftJoin('clients', 'clients.id', '=', 'properties.client_id')
            ->where(function ($q) use ($userIds, $tenantIds) {
                $q->whereIn('clients.user_id', $userIds);
                if ($tenantIds) $q->orWhereIn('work_orders.tenant_id', $tenantIds);
            })
            ->pluck('work_orders.id');

        if ($idsBloqueadas->isNotEmpty()) {
            $query->whereNotIn('work_orders.id', $idsBloqueadas);
        }
    }

    // ¿El dueño de esta orden y el técnico están bloqueados (en cualquier sentido)?
    public static function estaBloqueado(int $workOrderId, int $tecnicoId): bool
    {
        $orden = DB::table('work_orders')
            ->leftJoin('properties', 'properties.id', '=', 'work_orders.property_id')
            ->leftJoin('clients', 'clients.id', '=', 'properties.client_id')
            ->where('work_orders.id', $workOrderId)
            ->first(['clients.user_id as client_user_id', 'work_orders.tenant_id']);
        if (!$orden || (!$orden->client_user_id && !$orden->tenant_id)) return false;

        return ClientTechnicianRelation::where('technician_id', $tecnicoId)
            ->where('status', ClientTechnicianRelation::BLOQUEADO)
            ->where(function ($q) use ($orden) {
                if ($orden->client_user_id) $q->orWhere('client_user_id', $orden->client_user_id);
                if ($orden->tenant_id) $q->orWhere('tenant_id', $orden->tenant_id);
            })
            ->exists();
    }
}
