<?php

namespace App\Services;

use App\Models\NetworkQuote;
use App\Models\TechnicianReview;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\MovimientoNotificacion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Market: trabajo terminado (el técnico envió el reporte al cliente).
 *  - Aviso "Reporte recibido" al cliente (dueño e invitados) y "Trabajo finalizado" al técnico; al tocarlo abre el reporte.
 *  - El cliente revisa el reporte y lo confirma; si no lo hace, se confirma solo a las HORAS_AUTO horas
 *    (sin scheduler: se revisa cada vez que el cliente consulta sus pendientes).
 *  - Ya confirmado, el cliente califica al técnico (el técnico ya tiene su lista en CalificacionCliente).
 */
class TrabajoFinalizadoRed
{
    // Solo trabajos terminados desde que existe la calificación del cliente al técnico
    public const INICIO = '2026-11-23 00:00:00';

    // Si el cliente no confirma el reporte, se confirma solo después de estas horas
    public const HORAS_AUTO = 48;

    /** La base ya tiene las columnas de la confirmación (las pruebas viejas arman su propio esquema). */
    private static function conColumnas(): bool
    {
        return Schema::hasColumn('work_orders', 'client_confirmed_at');
    }

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

    private static function titulo($orden): string
    {
        return trim(($orden->type ?: 'Servicio') . ($orden->equipment ? " - {$orden->equipment}" : ''));
    }

    /** Técnicos a avisar: el titular de la cotización y el asignado a la orden (si es otro). */
    private static function tecnicosDe(WorkOrder $orden): array
    {
        return array_values(array_unique(array_filter([self::tecnicoTitular($orden), (int) $orden->tecnico_id])));
    }

    public static function avisar(WorkOrder $orden): void
    {
        try {
            $titular = self::tecnicoTitular($orden);
            if (!$titular) return; // no es de la red

            // Empieza la ventana para que el cliente confirme el reporte
            if (self::conColumnas()) {
                DB::table('work_orders')->where('id', $orden->id)->update([
                    'report_sent_at' => now(), 'client_confirmed_at' => null, 'client_confirmed_by' => null,
                ]);
            }

            $titulo = self::titulo($orden);
            $extra = ['work_order_id' => (int) $orden->id];

            // Cliente: dueño de la orden e invitados de la propiedad
            foreach (AccesoPropiedad::interesadosDeOrden($orden->id, 0) as $clienteId) {
                $cliente = User::withoutGlobalScopes()->find($clienteId);
                $cliente?->notify(new MovimientoNotificacion([
                    'titulo' => 'Reporte recibido',
                    'mensaje' => sprintf('Te llegó el reporte de "%s" (folio #%d). Revísalo y confírmalo; si no, se confirmará solo en %d horas.', $titulo, $orden->id, self::HORAS_AUTO),
                    'url' => self::urlCliente((int) $orden->id),
                    'alert_type' => 'trabajo_finalizado',
                    'extra' => $extra,
                ]));
            }

            // Técnico: el titular de la cotización y el asignado a la orden (si es otro)
            foreach (self::tecnicosDe($orden) as $tecnicoId) {
                $tecnico = User::withoutGlobalScopes()->find($tecnicoId);
                $tecnico?->notify(new MovimientoNotificacion([
                    'titulo' => 'Trabajo finalizado',
                    'mensaje' => sprintf('Entregaste "%s" (folio #%d). El cliente tiene %d horas para confirmar el reporte.', $titulo, $orden->id, self::HORAS_AUTO),
                    'url' => self::urlTecnico((int) $orden->id),
                    'alert_type' => 'trabajo_finalizado',
                    'extra' => $extra,
                ]));
            }
        } catch (\Throwable $e) {
            Log::warning("Aviso de trabajo finalizado de la orden {$orden->id}: " . $e->getMessage());
        }
    }

    /** Propiedades del cliente: las suyas (y de su equipo) más las que le compartieron. */
    private static function propiedadesDe(User $user): array
    {
        $propias = DB::table('properties')
            ->whereIn('client_id', AccesoPropiedad::clienteIds($user))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        return array_values(array_unique(array_merge($propias, AccesoPropiedad::compartidasCon($user))));
    }

    /** La orden es de una propiedad del cliente (o compartida con él). */
    public static function esDelCliente(User $user, WorkOrder $orden): bool
    {
        return in_array((int) $orden->property_id, self::propiedadesDe($user), true);
    }

    /** Órdenes de la red terminadas en esas propiedades (desde INICIO). */
    private static function terminadas(array $propiedades)
    {
        return WorkOrder::withoutGlobalScopes()
            ->where('publish_network', 1)
            ->whereIn('property_id', $propiedades)
            ->whereIn('status', CalificacionCliente::ESTADOS_TERMINADO)
            ->where('updated_at', '>=', self::INICIO);
    }

    /** Reporte enviado y aún sin confirmar (los enviados antes de la confirmación cuentan como confirmados). */
    public static function sinConfirmar($orden): bool
    {
        return self::conColumnas() && $orden->report_sent_at && !$orden->client_confirmed_at;
    }

    /**
     * Confirma el reporte. $por = quien lo confirma; null = confirmación automática.
     * Devuelve false si ya estaba confirmado o no había reporte enviado.
     */
    public static function confirmar(WorkOrder $orden, ?User $por = null): bool
    {
        if (!self::conColumnas()) return false;
        $fecha = $por ? now() : Carbon::parse($orden->report_sent_at)->addHours(self::HORAS_AUTO);
        $cambio = DB::table('work_orders')->where('id', $orden->id)
            ->whereNotNull('report_sent_at')->whereNull('client_confirmed_at')
            ->update(['client_confirmed_at' => $fecha, 'client_confirmed_by' => $por?->id]);
        if (!$cambio) return false;

        try {
            $titulo = self::titulo($orden);
            foreach (self::tecnicosDe($orden) as $tecnicoId) {
                $tecnico = User::withoutGlobalScopes()->find($tecnicoId);
                $tecnico?->notify(new MovimientoNotificacion([
                    'titulo' => 'Reporte confirmado',
                    'mensaje' => $por
                        ? sprintf('El cliente confirmó el reporte de "%s" (folio #%d).', $titulo, $orden->id)
                        : sprintf('El reporte de "%s" (folio #%d) se confirmó automáticamente: pasaron %d horas.', $titulo, $orden->id, self::HORAS_AUTO),
                    'url' => self::urlTecnico((int) $orden->id),
                    'alert_type' => 'reporte_confirmado',
                    'extra' => ['work_order_id' => (int) $orden->id],
                ]));
            }
        } catch (\Throwable $e) {
            Log::warning("Aviso de reporte confirmado de la orden {$orden->id}: " . $e->getMessage());
        }
        return true;
    }

    /** Confirma solas las órdenes cuyo reporte lleva HORAS_AUTO horas sin confirmar. */
    private static function autoConfirmarVencidas(array $propiedades): void
    {
        if (!self::conColumnas()) return;
        $vencidas = self::terminadas($propiedades)
            ->whereNotNull('report_sent_at')->whereNull('client_confirmed_at')
            ->where('report_sent_at', '<=', now()->subHours(self::HORAS_AUTO))
            ->get();
        foreach ($vencidas as $orden) self::confirmar($orden);
    }

    /** Datos del trabajo y del técnico, para la ventana de calificar y el aviso de confirmar. */
    public static function resumen(WorkOrder $orden): ?array
    {
        $tecnicoId = self::tecnicoTitular($orden) ?: ($orden->tecnico_id ? (int) $orden->tecnico_id : null);
        if (!$tecnicoId) return null;
        $tecnico = User::withoutGlobalScopes()->find($tecnicoId, ['id', 'first_name', 'last_name', 'profile_picture']);
        $enviado = self::conColumnas() && $orden->report_sent_at ? Carbon::parse($orden->report_sent_at) : null;
        return [
            'work_order_id' => (int) $orden->id,
            'titulo' => self::titulo($orden),
            'terminado_at' => optional($orden->updated_at)->toIso8601String(),
            'scheduled_at' => optional($orden->scheduled_at)->toIso8601String(),
            'arrived_at' => $orden->arrived_at ? Carbon::parse($orden->arrived_at)->toIso8601String() : null,
            'reporte_enviado_at' => $enviado?->toIso8601String(),
            'confirma_solo_at' => $enviado?->copy()->addHours(self::HORAS_AUTO)->toIso8601String(),
            'tecnico' => [
                'id' => $tecnicoId,
                'nombre' => $tecnico ? trim("{$tecnico->first_name} {$tecnico->last_name}") : 'Técnico',
                'foto' => $tecnico?->profile_picture,
            ],
        ];
    }

    /**
     * Pendientes del cliente (o de quien comparte la propiedad) en trabajos sin calificar:
     *  - calificar: reporte confirmado (o enviado antes de existir la confirmación).
     *  - por_confirmar: reporte enviado que el cliente todavía no confirma.
     */
    public static function pendientes(User $user): array
    {
        $propiedades = self::propiedadesDe($user);
        if (empty($propiedades)) return ['calificar' => [], 'por_confirmar' => []];

        self::autoConfirmarVencidas($propiedades);

        $calificadas = TechnicianReview::whereNotNull('work_order_id')->pluck('work_order_id');
        $ordenes = self::terminadas($propiedades)
            ->whereNotIn('id', $calificadas)
            ->orderBy('updated_at')
            ->get();

        $calificar = [];
        $porConfirmar = [];
        foreach ($ordenes as $orden) {
            $dato = self::resumen($orden);
            if (!$dato) continue;
            if (self::sinConfirmar($orden)) $porConfirmar[] = $dato;
            else $calificar[] = $dato;
        }
        return ['calificar' => $calificar, 'por_confirmar' => $porConfirmar];
    }

    /** Trabajos de la red terminados y confirmados que el cliente aún no califica. */
    public static function pendientesDeCalificar(User $user): array
    {
        return self::pendientes($user)['calificar'];
    }
}
