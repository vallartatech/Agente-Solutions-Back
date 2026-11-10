<?php

namespace App\Http\Controllers;

use App\Models\ClientReview;
use App\Models\WorkOrder;
use App\Services\CalificacionCliente;
use Illuminate\Http\Request;

/**
 * Calificación de clientes de la red (★ trato, ⏱ pago).
 *  Técnico: lista de trabajos terminados por calificar y guardar la calificación.
 *  Es exclusiva de los técnicos: el cliente no sabe que lo califican ni ve su calificación.
 */
class CalificacionClienteController extends Controller
{
    private const ROLES_TECNICO_RED = [6, 7, 8];

    public function pendientes(Request $request)
    {
        $user = $request->user();
        if (!in_array((int) $user->role_id, self::ROLES_TECNICO_RED, true)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        return response()->json(['success' => true, 'data' => CalificacionCliente::pendientes($user)]);
    }

    public function guardar(Request $request, $workOrderId)
    {
        $user = $request->user();
        $datos = $request->validate([
            'rating_trato' => 'required|integer|min:1|max:5',
            'rating_pago' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ], [
            'rating_trato.required' => 'Califica el trato del cliente.',
            'rating_pago.required' => 'Califica el pago y la puntualidad del cliente.',
        ]);

        $orden = WorkOrder::withoutGlobalScopes()->findOrFail($workOrderId);
        if ((int) $orden->tecnico_id !== (int) $user->id) {
            return response()->json(['message' => 'Solo el técnico que hizo el trabajo puede calificar al cliente.'], 403);
        }
        if (!in_array($orden->status, CalificacionCliente::ESTADOS_TERMINADO, true)) {
            return response()->json(['message' => 'Podrás calificar al cliente cuando termines el trabajo.'], 422);
        }

        $clienteId = CalificacionCliente::duenoDe($orden);
        if (!$clienteId) {
            return response()->json(['message' => 'No encontramos al cliente de este trabajo.'], 422);
        }

        ClientReview::updateOrCreate(
            ['technician_id' => $user->id, 'work_order_id' => $orden->id],
            [
                'client_id' => $clienteId,
                'rating_trato' => $datos['rating_trato'],
                'rating_pago' => $datos['rating_pago'],
                'comment' => isset($datos['comment']) ? trim($datos['comment']) : null,
            ]
        );
        CalificacionCliente::recalcular($clienteId);

        return response()->json([
            'success' => true,
            'message' => '¡Gracias! Tu calificación ayuda a otros técnicos de la red.',
            'pendientes' => CalificacionCliente::pendientes($user),
        ]);
    }
}
