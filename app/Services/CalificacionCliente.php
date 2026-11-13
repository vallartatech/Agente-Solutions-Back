<?php

namespace App\Services;

use App\Models\ClientReview;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Calificación de los clientes de la red (★ trato, ⏱ pago), hecha por el técnico.
 *
 * - Es obligatoria: mientras el técnico tenga trabajos terminados sin calificar al cliente,
 *   no puede enviar nuevas cotizaciones (el mercado le muestra la ventana para calificar).
 * - Solo cuentan los trabajos de la red terminados desde que existe la función
 *   (INICIO), para no pedirle al técnico calificar trabajos viejos.
 * - Exclusiva de los técnicos: la ven al cotizar. El cliente no sabe que lo califican.
 */
class CalificacionCliente
{
    public const INICIO = '2026-11-09 00:00:00';

    // Estados en los que el técnico ya terminó su parte del trabajo
    public const ESTADOS_TERMINADO = ['Terminado', 'Por aprobar', 'Listo', 'Finalizado', 'Completado', 'completed'];

    /** users.id del cliente dueño de la orden (el mismo criterio del mercado de trabajos). */
    public static function duenoDe(WorkOrder $orden): ?int
    {
        $orden->loadMissing([
            'property' => fn ($q) => $q->withoutGlobalScopes(),
            'property.client' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        $userId = $orden->property?->client?->user_id;
        if ($userId) {
            return (int) $userId;
        }

        $tenantId = $orden->tenant_id ?: ($orden->property?->tenant_id ?? null);
        if ($tenantId) {
            $dueno = DB::table('tenants')->where('id', $tenantId)->value('owner_user_id');
            if ($dueno) {
                return (int) $dueno;
            }
            $cliente = User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('role_id', [3, 4, 5, 7])
                ->value('id');
            if ($cliente) {
                return (int) $cliente;
            }
        }

        return null;
    }

    /** Trabajos de la red terminados por el técnico donde aún no califica al cliente. */
    public static function pendientes(User $tecnico)
    {
        $calificadas = ClientReview::where('technician_id', $tecnico->id)->pluck('work_order_id');

        return WorkOrder::withoutGlobalScopes()
            ->where('publish_network', 1)
            ->where('tecnico_id', $tecnico->id)
            ->whereIn('status', self::ESTADOS_TERMINADO)
            ->where('updated_at', '>=', self::INICIO)
            ->whereNotIn('id', $calificadas)
            ->orderBy('updated_at')
            ->get()
            ->map(function (WorkOrder $orden) {
                $clienteId = self::duenoDe($orden);
                if (!$clienteId) {
                    return null; // Sin cliente identificable no se puede calificar: no bloquea
                }
                $cliente = User::withoutGlobalScopes()->find($clienteId, ['id', 'first_name', 'last_name', 'profile_picture']);
                $titulo = trim(($orden->type ?: 'Servicio') . ($orden->equipment ? " - {$orden->equipment}" : ''));

                return [
                    'work_order_id' => $orden->id,
                    'titulo' => $titulo,
                    'estado' => $orden->status,
                    'terminado_at' => optional($orden->updated_at)->toIso8601String(),
                    'cliente' => [
                        'id' => $clienteId,
                        'nombre' => $cliente ? trim("{$cliente->first_name} {$cliente->last_name}") : 'Cliente',
                        'foto' => $cliente?->profile_picture,
                    ],
                ];
            })
            ->filter()
            ->concat(self::cancelacionesPendientes($tecnico))
            ->values();
    }

    /** El cliente le canceló al técnico un trabajo asignado: también debe calificarlo (trato y seriedad). */
    public static function cancelacionesPendientes(User $tecnico)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('client_cancellations')) {
            return collect();
        }

        return DB::table('client_cancellations')
            ->where('technician_id', $tecnico->id)
            ->where('rated', false)
            ->orderBy('cancelled_at')
            ->get()
            ->map(function ($c) {
                $orden = WorkOrder::withoutGlobalScopes()->find($c->work_order_id);
                $clienteId = $c->client_user_id ?: ($orden ? self::duenoDe($orden) : null);
                if (!$clienteId) {
                    return null;
                }
                $cliente = User::withoutGlobalScopes()->find($clienteId, ['id', 'first_name', 'last_name', 'profile_picture']);
                $titulo = $orden ? trim(($orden->type ?: 'Servicio') . ($orden->equipment ? " - {$orden->equipment}" : '')) : 'Servicio';

                return [
                    'work_order_id' => (int) $c->work_order_id,
                    'titulo' => $titulo,
                    'estado' => 'Cancelado por el cliente',
                    'cancelacion' => true,
                    'motivo' => $c->reason,
                    'terminado_at' => $c->cancelled_at ? \Carbon\Carbon::parse($c->cancelled_at)->toIso8601String() : null,
                    'cliente' => [
                        'id' => $clienteId,
                        'nombre' => $cliente ? trim("{$cliente->first_name} {$cliente->last_name}") : 'Cliente',
                        'foto' => $cliente?->profile_picture,
                    ],
                ];
            })
            ->filter()
            ->values();
    }

    public static function tienePendientes(User $tecnico): bool
    {
        return self::pendientes($tecnico)->isNotEmpty();
    }

    /** Recalcula los promedios guardados en users. */
    public static function recalcular(int $clienteId): void
    {
        $r = ClientReview::where('client_id', $clienteId)
            ->selectRaw('AVG(rating_trato) as trato, AVG(rating_pago) as pago, COUNT(*) as total')
            ->first();

        User::withoutGlobalScopes()->whereKey($clienteId)->update([
            'client_trato_avg' => $r->total ? round((float) $r->trato, 2) : null,
            'client_pago_avg' => $r->total ? round((float) $r->pago, 2) : null,
            'client_reviews_count' => (int) $r->total,
        ]);
    }

    /** Lo que ven los técnicos: promedios y total (null si no hay cliente). */
    public static function resumen(?int $clienteId): ?array
    {
        if (!$clienteId) {
            return null;
        }
        $u = User::withoutGlobalScopes()->find($clienteId, ['id', 'client_trato_avg', 'client_pago_avg', 'client_reviews_count', 'created_at']);
        if (!$u) {
            return null;
        }
        $total = (int) ($u->client_reviews_count ?? 0);

        return [
            'trato' => $total ? round((float) $u->client_trato_avg, 1) : null,
            'pago' => $total ? round((float) $u->client_pago_avg, 1) : null,
            'total' => $total,
            'miembro_desde' => optional($u->created_at)->toDateString(),
        ];
    }
}
