<?php

namespace App\Http\Controllers;

use App\Models\ClientTechnicianRelation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Mis clientes" del técnico de la red: marca como favoritos o bloquea a los clientes a los que
 * les trabajó. El historial lo arma el frontend con sus trabajos aceptados (accepted_jobs, que
 * traen client_user_id); aquí se guardan las marcas. Un cliente bloqueado ya no le aparece al
 * técnico en la red y no puede mandarle mensajes (ver MisTecnicosController::estaBloqueado).
 */
class MisClientesController extends Controller
{
    private const ROLES_TECNICO = [2, 6, 8];

    // Usuario dueño de una orden: el ligado al cliente de la propiedad o, si no, el del tenant
    public static function duenoDeOrden(int $workOrderId): ?int
    {
        $orden = DB::table('work_orders')
            ->leftJoin('properties', 'properties.id', '=', 'work_orders.property_id')
            ->leftJoin('clients', 'clients.id', '=', 'properties.client_id')
            ->where('work_orders.id', $workOrderId)
            ->first(['clients.user_id as client_user_id', 'work_orders.tenant_id', 'properties.tenant_id as property_tenant_id']);
        if (!$orden) return null;
        if ($orden->client_user_id) return (int) $orden->client_user_id;

        $tenantId = ClientTechnicianRelation::tenantDeBloqueo($orden->tenant_id ?: $orden->property_tenant_id);
        if (!$tenantId) return null;

        $dueno = DB::table('tenants')->where('id', $tenantId)->value('owner_user_id')
            ?: DB::table('users')->where('tenant_id', $tenantId)->whereIn('role_id', [3, 4, 5, 7])->orderBy('id')->value('id');
        return $dueno ? (int) $dueno : null;
    }

    // Órdenes en las que trabajó el técnico: asignado directo, en el equipo o con cotización aceptada
    private static function idsOrdenesDelTecnico(int $tecnicoId)
    {
        return DB::table('work_orders')->where('tecnico_id', $tecnicoId)->pluck('id')
            ->merge(DB::table('work_order_technician')->where('technician_id', $tecnicoId)->pluck('work_order_id'))
            ->merge(DB::table('network_quotes')->where('technician_id', $tecnicoId)->where('status', 'accepted')->pluck('work_order_id'))
            ->map(fn ($i) => (int) $i)->unique()->values();
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!in_array((int) $user->role_id, self::ROLES_TECNICO, true)) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }

        $relaciones = ClientTechnicianRelation::where('technician_id', $user->id)
            ->where('set_by', ClientTechnicianRelation::POR_TECNICO)
            ->get(['client_user_id', 'status'])
            ->map(fn ($r) => ['client_user_id' => (int) $r->client_user_id, 'estado' => $r->status]);

        return response()->json(['success' => true, 'relaciones' => $relaciones]);
    }

    // estado: "favorito", "bloqueado" o null para quitar la marca
    public function marcar(Request $request, $clienteId)
    {
        $data = $request->validate(['estado' => 'nullable|in:favorito,bloqueado']);
        $user = $request->user();
        $clienteId = (int) $clienteId;

        if (!in_array((int) $user->role_id, self::ROLES_TECNICO, true)) {
            return response()->json(['message' => 'Solo disponible para técnicos.'], 403);
        }
        $cliente = User::withoutGlobalScopes()->find($clienteId);
        if (!$cliente || $clienteId === (int) $user->id) {
            return response()->json(['message' => 'Cliente no válido.'], 422);
        }

        $llave = ['client_user_id' => $clienteId, 'technician_id' => $user->id, 'set_by' => ClientTechnicianRelation::POR_TECNICO];
        $relacion = ClientTechnicianRelation::where($llave)->first();

        if (!$relacion) {
            $leTrabajo = self::idsOrdenesDelTecnico((int) $user->id)
                ->contains(fn ($id) => self::duenoDeOrden($id) === $clienteId);
            if (!$leTrabajo) {
                return response()->json(['message' => 'No has trabajado con este cliente.'], 403);
            }
        }

        if (empty($data['estado'])) {
            $relacion?->delete();
            return response()->json(['success' => true, 'estado' => null]);
        }

        ClientTechnicianRelation::updateOrCreate($llave, [
            'status' => $data['estado'],
            'tenant_id' => ClientTechnicianRelation::tenantDeBloqueo($cliente->tenant_id),
        ]);

        return response()->json(['success' => true, 'estado' => $data['estado']]);
    }
}
