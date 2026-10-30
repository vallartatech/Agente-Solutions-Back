<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\Request;

// Validación de trabajos terminados: un validador fijo por equipo recibe los trabajos "Por Aprobar"
// y en el menú ve una alerta (mascota + contador) mientras tenga pendientes.
class WorkValidationController extends Controller
{
    public const MASCOTAS = ['gato', 'perro', 'fantasma', 'numero'];

    // Cuentas que ven el fantasma flotante "Verifica tus reportes"
    private const CUENTAS_ALERTA_REPORTES = ['ppechmena@gmail.com'];

    // Mismo equipo: mismo tenant; tenant nulo y tenant 1 son el equipo de Agente Solutions
    public static function mismoEquipo($query, ?int $tenantId, string $columna = 'tenant_id')
    {
        if (!empty($tenantId) && $tenantId !== 1) {
            return $query->where($columna, $tenantId);
        }
        return $query->where(fn ($q) => $q->whereNull($columna)->orWhere($columna, 1));
    }

    // Quién recibe los trabajos terminados de un equipo: el validador; si no hay, sus admins activos
    public static function destinatarios(?int $tenantId)
    {
        $base = fn () => self::mismoEquipo(User::withoutGlobalScopes(), $tenantId)
            ->where('is_active', 1)
            ->whereIn('role_id', [0, 1]);

        $validadores = $base()->where('valida_trabajos', true)->get();
        return $validadores->isNotEmpty() ? $validadores : $base()->get();
    }

    // Contador del menú: trabajos que esperan validación y si este usuario debe ver la alerta
    public function pendientes(Request $request)
    {
        $user = $request->user();
        if (!in_array((int) $user->role_id, [0, 1])) {
            return response()->json(['mostrar' => false, 'pendientes' => 0, 'reportes_por_revisar' => null], 200);
        }

        $tenantId = $user->tenant_id ? (int) $user->tenant_id : null;
        $hayValidador = self::mismoEquipo(User::withoutGlobalScopes(), $tenantId)
            ->where('is_active', 1)
            ->where('valida_trabajos', true)
            ->exists();

        $pendientes = self::mismoEquipo(WorkOrder::withoutGlobalScopes(), $tenantId)
            ->where('status', 'Por Aprobar')
            ->count();

        return response()->json([
            'pendientes' => $pendientes,
            'es_validador' => (bool) $user->valida_trabajos,
            // Lo ve el validador; si el equipo aún no tiene uno, todos sus admins
            'mostrar' => (bool) $user->valida_trabajos || !$hayValidador,
            'mascota' => in_array($user->alert_mascot, self::MASCOTAS, true) ? $user->alert_mascot : 'gato',
            'reportes_por_revisar' => $this->reportesPorRevisar($user, $tenantId),
        ], 200);
    }

    // Trabajos sin cerrar de su equipo donde un técnico ya subió evidencia. Null si la cuenta no ve esta alerta.
    private function reportesPorRevisar(User $user, ?int $tenantId): ?int
    {
        if (!in_array(mb_strtolower((string) $user->email), self::CUENTAS_ALERTA_REPORTES, true)) {
            return null;
        }

        return self::mismoEquipo(WorkOrder::withoutGlobalScopes(), $tenantId)
            ->whereNotIn('status', ['Listo', 'Finalizado', 'Rechazado', 'Cancelado', 'cancelado_tecnico'])
            ->whereHas('workReports', fn ($q) => $q->whereIn(
                'technician_id',
                User::withoutGlobalScopes()->where('role_id', 2)->select('id')
            ))
            ->count();
    }

    // Marcar o quitar al validador. Solo hay uno por equipo: marcar a alguien se lo quita al anterior.
    public function setValidador(Request $request, $userId)
    {
        $actor = $request->user();
        if (!in_array((int) $actor->role_id, [0, 1])) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $validated = $request->validate(['enabled' => 'required|boolean']);
        $target = User::withoutGlobalScopes()->find((int) preg_replace('/\D/', '', (string) $userId));
        if (!$target || !in_array((int) $target->role_id, [0, 1])) {
            return response()->json(['error' => 'Solo un administrador puede validar trabajos.'], 422);
        }

        $tenantId = $target->tenant_id ? (int) $target->tenant_id : null;
        // Un admin solo cambia a los de su propio equipo (Root, a cualquiera)
        if ((int) $actor->role_id !== 0) {
            $mismo = self::mismoEquipo(User::withoutGlobalScopes(), $actor->tenant_id ? (int) $actor->tenant_id : null)
                ->whereKey($target->id)->exists();
            if (!$mismo) {
                return response()->json(['error' => 'Ese usuario no es de tu equipo.'], 403);
            }
        }

        if ($validated['enabled']) {
            self::mismoEquipo(User::withoutGlobalScopes(), $tenantId)
                ->where('id', '!=', $target->id)
                ->where('valida_trabajos', true)
                ->update(['valida_trabajos' => false]);
        }
        $target->valida_trabajos = $validated['enabled'];
        $target->save();

        return response()->json(['user_id' => $target->id, 'valida_trabajos' => (bool) $target->valida_trabajos], 200);
    }

    // Cada quien elige la mascota de su alerta (o "numero" para solo el contador)
    public function setMascota(Request $request)
    {
        $validated = $request->validate(['mascota' => 'required|in:' . implode(',', self::MASCOTAS)]);
        $user = $request->user();
        $user->alert_mascot = $validated['mascota'];
        $user->save();

        return response()->json(['mascota' => $user->alert_mascot], 200);
    }
}
