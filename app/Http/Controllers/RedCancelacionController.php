<?php

namespace App\Http\Controllers;

use App\Models\NetworkQuote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\MovimientoNotificacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Red (Agente Market): cancelaciones y cotizaciones vigentes.
 *
 * - El cliente cancela un trabajo asignado antes de que comience (motivo + aviso al técnico).
 *   Después elige: reenviarlo a la red o cancelarlo por completo (rutas /client/resolve-cancelled-work-order
 *   y /mercado-trabajos/{id}/reabrir-red).
 * - Al reenviarlo, las cotizaciones anteriores que sigan vigentes se pueden elegir; como pudieron
 *   hacerse días antes, el técnico confirma que sigue disponible antes de asignarle el trabajo.
 * - El técnico puede retirar su cotización mientras no se la acepten.
 */
class RedCancelacionController extends Controller
{
    // ───────────── Cliente: cancelar un trabajo asignado ─────────────

    // POST /mercado-trabajos/{id}/cancelar-cliente
    public function cancelarCliente(Request $request, $workOrderId)
    {
        $request->validate(['motivo' => 'required|string|min:5|max:1000'], [
            'motivo.required' => 'Cuéntale al técnico por qué cancelas.',
            'motivo.min' => 'Cuéntale al técnico por qué cancelas.',
        ]);
        $user = $request->user();

        $orden = WorkOrder::withoutGlobalScopes()->find($workOrderId);
        if (!$orden) return response()->json(['success' => false, 'message' => 'Trabajo no encontrado'], 404);
        if (!AjusteCotizacionController::puedeResponderPorDueno($user, $orden)) {
            return response()->json(['success' => false, 'message' => 'Solo el dueño del trabajo puede cancelarlo'], 403);
        }
        if ($orden->status !== 'Asignado' || self::yaComenzo($orden->id)) {
            return response()->json(['success' => false, 'message' => 'El técnico ya comenzó el trabajo; ya no se puede cancelar.'], 422);
        }

        $quote = NetworkQuote::withoutGlobalScopes()
            ->where('work_order_id', $orden->id)->where('status', 'accepted')->latest('updated_at')->first();
        $tecnicoId = $orden->tecnico_id ?: $quote?->technician_id;
        $motivo = trim($request->input('motivo'));

        DB::transaction(function () use ($orden, $quote, $tecnicoId, $motivo) {
            $orden->status = 'Cancelado_Cliente';
            $orden->cancelled_by_client = true;
            $orden->cancelled_by_tech = false;
            $orden->cancelled_technician_id = $tecnicoId;
            $orden->cancellation_reason = $motivo;
            $orden->cancelled_at = now();
            $orden->tecnico_id = null;
            $orden->save();

            if ($quote) {
                $quote->status = 'rejected';
                $quote->save();
            }
            DB::table('work_order_technician')->where('work_order_id', $orden->id)->delete();

            // El técnico califica a este cliente (obligatorio, como en los trabajos terminados)
            if ($tecnicoId && Schema::hasTable('client_cancellations')) {
                DB::table('client_cancellations')->insert([
                    'work_order_id' => $orden->id,
                    'technician_id' => $tecnicoId,
                    'client_user_id' => MisClientesController::duenoDeOrden((int) $orden->id),
                    'reason' => $motivo,
                    'cancelled_at' => now(),
                    'rated' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            if (Schema::hasTable('network_quote_ajustes')) {
                DB::table('network_quote_ajustes')->where('work_order_id', $orden->id)->where('status', 'pending')
                    ->update(['status' => 'retirado', 'updated_at' => now()]);
            }
        });

        if ($quote) {
            self::anotarEnChat($quote, $user, 'Cliente', "🚫 [SERVICIO CANCELADO POR EL CLIENTE]: \"{$motivo}\"", ['is_cancellation' => true]);
        }
        if ($tecnicoId) {
            self::avisar((int) $tecnicoId, 'El cliente canceló el servicio',
                sprintf('%s canceló "%s". Motivo: %s', self::nombre($user), self::titulo($orden), $motivo),
                '/mercado-trabajos', 'work_order_cancelled_by_client', $orden->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Servicio cancelado. Le avisamos al técnico. Ahora elige si lo reenvías a la red o lo cancelas por completo.',
        ]);
    }

    // ───────────── Técnico: retirar su cotización ─────────────

    // POST /network-quotes/{id}/retirar
    public function retirarCotizacion(Request $request, $quoteId)
    {
        $user = $request->user();
        $quote = NetworkQuote::withoutGlobalScopes()->find($quoteId);
        if (!$quote || (int) $quote->technician_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Cotización no encontrada'], 404);
        }
        if ($quote->status !== 'pending') {
            return response()->json(['success' => false, 'message' => $quote->status === 'accepted'
                ? 'Esta cotización ya fue aceptada; para dejar el trabajo usa "Cancelar servicio".'
                : 'Esta cotización ya no está activa.'], 422);
        }

        $habiaConfirmacion = (bool) $quote->confirmacion_pedida_at;
        $quote->status = 'rejected';
        $quote->retirada_at = now();
        $quote->confirmacion_pedida_at = null;
        $quote->save();

        self::anotarEnChat($quote, $user, 'Técnico de la Red', '↩️ [COTIZACIÓN RETIRADA]: el técnico retiró su cotización.');

        $orden = WorkOrder::withoutGlobalScopes()->find($quote->work_order_id);
        $duenoId = $orden ? MisClientesController::duenoDeOrden((int) $orden->id) : null;
        if ($duenoId && $habiaConfirmacion) {
            self::avisar((int) $duenoId, 'El técnico ya no está disponible',
                sprintf('%s retiró su cotización para "%s". Puedes elegir otra cotización.', self::nombre($user), self::titulo($orden)),
                '/red-autonomos?trabajo=' . $orden->id, 'network_quote_retirada', $orden->id);
        }

        return response()->json(['success' => true, 'message' => 'Retiraste tu cotización.', 'quote' => $quote]);
    }

    // ───────────── Técnico: confirmar que sigue disponible ─────────────

    // POST /network-quotes/{id}/confirmar  { accion: confirmar | declinar }
    public function confirmarDisponibilidad(Request $request, $quoteId)
    {
        $request->validate(['accion' => 'required|in:confirmar,declinar']);
        $user = $request->user();

        $quote = NetworkQuote::withoutGlobalScopes()->find($quoteId);
        if (!$quote || (int) $quote->technician_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Cotización no encontrada'], 404);
        }
        if ($quote->status !== 'pending' || !$quote->confirmacion_pedida_at) {
            return response()->json(['success' => false, 'message' => 'El cliente ya no está esperando tu confirmación.'], 422);
        }

        $orden = WorkOrder::withoutGlobalScopes()->find($quote->work_order_id);
        $duenoId = $orden ? MisClientesController::duenoDeOrden((int) $orden->id) : null;
        $dueno = $duenoId ? User::withoutGlobalScopes()->find($duenoId) : null;

        if ($request->input('accion') === 'declinar') {
            $quote->status = 'rejected';
            $quote->retirada_at = now();
            $quote->confirmacion_pedida_at = null;
            $quote->save();
            self::anotarEnChat($quote, $user, 'Técnico de la Red', '↩️ [NO DISPONIBLE]: el técnico ya no puede tomar este trabajo.');
            if ($dueno) {
                self::avisar((int) $dueno->id, 'El técnico no está disponible',
                    sprintf('%s ya no puede tomar "%s". Elige otra cotización o espera nuevas.', self::nombre($user), self::titulo($orden)),
                    '/red-autonomos?trabajo=' . $orden->id, 'network_quote_declinada', $orden->id);
            }
            return response()->json(['success' => true, 'message' => 'Listo, le avisamos al cliente que no estás disponible.']);
        }

        if (!$orden || $orden->status !== 'Por Hacer' || $orden->tecnico_id) {
            return response()->json(['success' => false, 'message' => 'Este trabajo ya no está disponible.'], 422);
        }

        self::asignarCotizacion($quote, $dueno);

        return response()->json(['success' => true, 'message' => '¡Confirmado! El trabajo ya es tuyo; aparece en tu tablero.']);
    }

    // POST /network-quotes/{id}/cancelar-confirmacion — el cliente deja de esperar a ese técnico
    public function cancelarSolicitudConfirmacion(Request $request, $quoteId)
    {
        $user = $request->user();
        $quote = NetworkQuote::withoutGlobalScopes()->find($quoteId);
        $orden = $quote ? WorkOrder::withoutGlobalScopes()->find($quote->work_order_id) : null;
        if (!$quote || !AjusteCotizacionController::puedeResponderPorDueno($user, $orden)) {
            return response()->json(['success' => false, 'message' => 'Cotización no encontrada'], 404);
        }
        if (!$quote->confirmacion_pedida_at || $quote->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'No hay una solicitud pendiente.'], 422);
        }

        $quote->confirmacion_pedida_at = null;
        $quote->save();

        return response()->json(['success' => true, 'message' => 'Dejaste de esperar a este técnico. Puedes elegir otra cotización.']);
    }

    // ───────────── Ayudantes compartidos ─────────────

    /**
     * ¿La cotización es anterior al último reenvío a la red? Entonces el técnico confirma primero.
     */
    public static function requiereConfirmacion(NetworkQuote $quote, ?WorkOrder $orden): bool
    {
        if (!$orden || !$orden->reabierta_at) return false;
        $cotizada = $quote->cotizada_at ?: $quote->created_at;
        return $cotizada && \Carbon\Carbon::parse($cotizada)->lt(\Carbon\Carbon::parse($orden->reabierta_at));
    }

    /**
     * El cliente eligió una cotización anterior: se le pide al técnico que confirme.
     * Devuelve la respuesta JSON para /network-quotes/{id}/accept.
     */
    public static function pedirConfirmacion(NetworkQuote $quote, WorkOrder $orden, ?User $cliente)
    {
        $otraEsperando = NetworkQuote::withoutGlobalScopes()
            ->where('work_order_id', $orden->id)->where('id', '!=', $quote->id)
            ->where('status', 'pending')->whereNotNull('confirmacion_pedida_at')->exists();
        if ($otraEsperando) {
            return response()->json([
                'success' => false,
                'message' => 'Ya estás esperando la confirmación de otro técnico. Cancela esa solicitud para elegir otra cotización.',
            ], 422);
        }

        $quote->confirmacion_pedida_at = now();
        $quote->save();

        self::avisar((int) $quote->technician_id, '¿Sigues disponible?',
            sprintf('%s quiere contratarte para "%s" con tu cotización de $%s. Confirma si sigues disponible.',
                $cliente ? self::nombre($cliente) : 'El cliente', self::titulo($orden), number_format((float) $quote->price, 2)),
            '/mercado-trabajos?trabajo=' . $orden->id, 'network_quote_confirmacion', $orden->id);

        return response()->json([
            'success' => true,
            'requiere_confirmacion' => true,
            'message' => 'Le pedimos al técnico que confirme que sigue disponible. Te avisaremos cuando responda.',
        ]);
    }

    /**
     * Asigna el trabajo al técnico de la cotización (lo que hacía /network-quotes/{id}/accept).
     */
    public static function asignarCotizacion(NetworkQuote $quote, ?User $cliente): void
    {
        $hayColumnas = self::hayColumnasNuevas();

        $quote->status = 'accepted';
        if ($hayColumnas) $quote->confirmacion_pedida_at = null;
        $quote->save();

        $workOrder = WorkOrder::withoutGlobalScopes()->find($quote->work_order_id);
        if (!$workOrder) return;

        $workOrder->tecnico_id = $quote->technician_id;
        $workOrder->status = 'Asignado';
        if ($hayColumnas) $workOrder->cancelled_by_client = false;
        $workOrder->save();

        // Solo puede haber una esperando confirmación: las demás dejan de esperar
        if ($hayColumnas) {
            NetworkQuote::withoutGlobalScopes()->where('work_order_id', $workOrder->id)->where('id', '!=', $quote->id)
                ->whereNotNull('confirmacion_pedida_at')->update(['confirmacion_pedida_at' => null]);
        }

        try {
            DB::table('work_order_technician')->insertOrIgnore([
                'work_order_id' => $workOrder->id,
                'technician_id' => $quote->technician_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("No se pudo insertar en work_order_technician: " . $e->getMessage());
        }

        $title = $workOrder->type . ($workOrder->equipment ? ' - ' . $workOrder->equipment : '');
        $tecnico = User::withoutGlobalScopes()->find($quote->technician_id);
        $techName = $tecnico ? trim($tecnico->first_name . ' ' . $tecnico->last_name) : 'Técnico';

        try {
            $tecnico?->notify(new \App\Notifications\NetworkQuoteAccepted($quote, $title));
        } catch (\Throwable $e) {
            Log::error("Error notificando al técnico: " . $e->getMessage());
        }

        try {
            // Siempre al dueño de la orden (no al primer usuario del tenant, que puede ser un Admin)
            $duenoId = MisClientesController::duenoDeOrden((int) $workOrder->id);
            $dueno = $duenoId ? User::withoutGlobalScopes()->find($duenoId) : $cliente;
            $dueno?->notify(new \App\Notifications\NetworkQuoteAcceptedClient($quote, $techName, $title));
        } catch (\Throwable $e) {
            Log::error("Error notificando al cliente: " . $e->getMessage());
        }
    }

    /**
     * El técnico canceló (con bloqueo de 24 h): el cliente debe calificarlo antes de reenviar o cerrar el servicio.
     * Devuelve la respuesta 422 si falta la calificación, o null si puede continuar.
     */
    public static function exigirCalificacionDelTecnico(int $workOrderId)
    {
        if (!Schema::hasTable('technician_cancellations')) return null;
        $pendiente = DB::table('technician_cancellations')
            ->where('work_order_id', $workOrderId)->where('rated', false)->orderByDesc('id')->first();
        if (!$pendiente) return null;

        $calificado = Schema::hasTable('technician_reviews') && DB::table('technician_reviews')
            ->where('work_order_id', $workOrderId)->where('technician_id', $pendiente->technician_id)->exists();
        if ($calificado) return null;

        return response()->json([
            'success' => false,
            'reason' => 'calificacion_pendiente',
            'message' => 'Antes de continuar, califica al técnico que canceló el servicio.',
        ], 422);
    }

    // Mientras no corra la migración 2026_11_13_110000 no se tocan las columnas nuevas
    public static function hayColumnasNuevas(): bool
    {
        return Schema::hasColumn('network_quotes', 'confirmacion_pedida_at')
            && Schema::hasColumn('work_orders', 'cancelled_by_client');
    }

    // El técnico ya subió evidencia: el trabajo comenzó (el estado "En Progreso" no se guarda en el servidor)
    private static function yaComenzo(int $workOrderId): bool
    {
        try {
            return Schema::hasTable('work_reports') && Schema::hasColumn('work_reports', 'work_order_id')
                && DB::table('work_reports')->where('work_order_id', $workOrderId)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function anotarEnChat(NetworkQuote $quote, User $user, string $rol, string $mensaje, array $extra = []): void
    {
        try {
            $historial = $quote->chat_history ?? [];
            $historial[] = array_merge([
                'sender_id' => $user->id,
                'sender_name' => self::nombre($user),
                'sender_role' => $rol,
                'message' => $mensaje,
                'created_at' => now()->toIso8601String(),
            ], $extra);
            $quote->chat_history = $historial;
            $quote->save();
        } catch (\Throwable $e) {
            Log::warning('Red: no se pudo anotar en el chat: ' . $e->getMessage());
        }
    }

    private static function avisar(int $userId, string $titulo, string $mensaje, string $url, string $tipo, int $workOrderId): void
    {
        try {
            $destino = User::withoutGlobalScopes()->find($userId);
            if (!$destino) return;
            $destino->notify(new MovimientoNotificacion([
                'titulo' => $titulo,
                'mensaje' => $mensaje,
                'url' => $url,
                'alert_type' => $tipo,
                'extra' => ['work_order_id' => $workOrderId],
            ]));
            // El push sale solo: PushForDatabaseNotifications lo envía por cada aviso de la campanita
        } catch (\Throwable $e) {
            Log::warning("Red: no se pudo avisar al usuario {$userId}: " . $e->getMessage());
        }
    }

    private static function nombre(User $u): string
    {
        return trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->name ?? 'Usuario');
    }

    private static function titulo(?WorkOrder $o): string
    {
        if (!$o) return 'el trabajo';
        return trim($o->type . ($o->equipment ? ' - ' . $o->equipment : '')) . " (folio #{$o->id})";
    }
}
