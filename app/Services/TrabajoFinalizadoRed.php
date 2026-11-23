<?php

namespace App\Services;

use App\Models\NetworkQuote;
use App\Models\TechnicianReview;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\MovimientoNotificacion;
use Illuminate\Support\Facades\Log;

/**
 * Agente Market: trabajo terminado (el técnico envió el reporte al cliente).
 *  - Aviso "Trabajo finalizado" al cliente (dueño e invitados) y al técnico; al tocarlo abre el reporte.
 *  - Trabajos terminados que el cliente aún no califica (el técnico ya tiene su lista en CalificacionCliente).
 */
class TrabajoFinalizadoRed
{
    // Solo trabajos terminados desde que existe la calificación del cliente al técnico
    public const INICIO = '2026-11-23 00:00:00';

    public static function urlCliente(int $workOrderId): string
    {
        return "/reportes-globales?trabajo={$workOrderId}";
    }

    public static function urlTecnico(int $workOrderId): string
    {
        return "/galeria-reportes/work_order-{$workOrderId}";
    }

    /** Orden de la red: tiene una cotización aceptada. Devuelve el técnico titular o null. */
    private static function tecnicoTitular(WorkOrder $orden): ?int
    {
        $id = NetworkQuote::withoutGlobalScopes()
            ->where('work_order_id', $orden->id)->where('status', 'accepted')->value('technician_id');
        return $id ? (int) $id : null;
    }

    public static function avisar(WorkOrder $orden): void
    {
        try {
            $titular = self::tecnicoTitular($orden);
            if (!$titular) return; // no es de la red

            $titulo = trim(($orden->type ?: 'Servicio') . ($orden->equipment ? " - {$orden->equipment}" : ''));
            $base = [
                'titulo' => 'Trabajo finalizado',
                'alert_type' => 'trabajo_finalizado',
                'extra' => ['work_order_id' => (int) $orden->id],
            ];

            // Cliente: dueño de la orden e invitados de la propiedad
            foreach (AccesoPropiedad::interesadosDeOrden($orden->id, 0) as $clienteId) {
                $cliente = User::withoutGlobalScopes()->find($clienteId);
                $cliente?->notify(new MovimientoNotificacion($base + [
                    'mensaje' => sprintf('"%s" (folio #%d) ya terminó. Toca para ver el reporte y calificar al técnico.', $titulo, $orden->id),
                    'url' => self::urlCliente((int) $orden->id),
                ]));
            }

            // Técnico: el titular de la cotización y el asignado a la orden (si es otro)
            $tecnicos = array_unique(array_filter([$titular, (int) $orden->tecnico_id]));
            foreach ($tecnicos as $tecnicoId) {
                $tecnico = User::withoutGlobalScopes()->find($tecnicoId);
                $tecnico?->notify(new MovimientoNotificacion($base + [
                    'mensaje' => sprintf('Entregaste "%s" (folio #%d). Toca para ver el reporte.', $titulo, $orden->id),
                    'url' => self::urlTecnico((int) $orden->id),
                ]));
            }
        } catch (\Throwable $e) {
            Log::warning("Aviso de trabajo finalizado de la orden {$orden->id}: " . $e->getMessage());
        }
    }

    /** Trabajos de la red terminados que el cliente (o quien comparte la propiedad) aún no califica. */
    public static function pendientesDeCalificar(User $user): array
    {
        $propiedades = \Illuminate\Support\Facades\DB::table('properties')
            ->whereIn('client_id', AccesoPropiedad::clienteIds($user))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $propiedades = array_values(array_unique(array_merge($propiedades, AccesoPropiedad::compartidasCon($user))));
        if (empty($propiedades)) return [];

        $calificadas = TechnicianReview::whereNotNull('work_order_id')->pluck('work_order_id');

        return WorkOrder::withoutGlobalScopes()
            ->where('publish_network', 1)
            ->whereIn('property_id', $propiedades)
            ->whereIn('status', CalificacionCliente::ESTADOS_TERMINADO)
            ->where('updated_at', '>=', self::INICIO)
            ->whereNotIn('id', $calificadas)
            ->orderBy('updated_at')
            ->get()
            ->map(function (WorkOrder $orden) {
                $tecnicoId = self::tecnicoTitular($orden) ?: ($orden->tecnico_id ? (int) $orden->tecnico_id : null);
                if (!$tecnicoId) return null;
                $tecnico = User::withoutGlobalScopes()->find($tecnicoId, ['id', 'first_name', 'last_name', 'profile_picture']);
                return [
                    'work_order_id' => (int) $orden->id,
                    'titulo' => trim(($orden->type ?: 'Servicio') . ($orden->equipment ? " - {$orden->equipment}" : '')),
                    'terminado_at' => optional($orden->updated_at)->toIso8601String(),
                    'scheduled_at' => optional($orden->scheduled_at)->toIso8601String(),
                    'arrived_at' => $orden->arrived_at ? \Carbon\Carbon::parse($orden->arrived_at)->toIso8601String() : null,
                    'tecnico' => [
                        'id' => $tecnicoId,
                        'nombre' => $tecnico ? trim("{$tecnico->first_name} {$tecnico->last_name}") : 'Técnico',
                        'foto' => $tecnico?->profile_picture,
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
