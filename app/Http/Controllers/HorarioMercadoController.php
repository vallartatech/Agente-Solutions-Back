<?php

namespace App\Http\Controllers;

use App\Models\NetworkQuote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\HorarioMercado as H;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Market: acciones del horario en la cotización (ver App\Services\HorarioMercado).
 *
 * - Técnico: "Comenzar ruta" queda registrado (el cliente ve "va en camino" y no sale el recordatorio de 10 min).
 * - Cliente: el técnico no llegó → seguir esperando o regresar el trabajo al Mercado ("No llegó").
 * - Cliente: nadie cotizó a tiempo → programar para otro día.
 * - Después de aceptar: 1 sola re-programación del técnico, que el cliente aprueba o rechaza.
 */
class HorarioMercadoController extends Controller
{
    private function orden($id): WorkOrder
    {
        $orden = WorkOrder::withoutGlobalScopes()->find($id);
        if (!$orden) abort(response()->json(['success' => false, 'message' => 'Trabajo no encontrado'], 404));
        return $orden;
    }

    private function exigirDueno(?User $user, WorkOrder $orden): void
    {
        if (!AjusteCotizacionController::puedeResponderPorDueno($user, $orden)) {
            abort(response()->json(['success' => false, 'message' => 'Solo el dueño del trabajo puede hacer esto.'], 403));
        }
    }

    // POST /mercado-trabajos/{id}/en-camino — el técnico presionó "Comenzar ruta"
    public function enCamino(Request $request, $id)
    {
        $user = $request->user();
        $orden = $this->orden($id);
        if (!H::listo()) return response()->json(['success' => true]);
        if (!AccesoQrRedController::esTecnicoDelTrabajo($user, $orden)) {
            return response()->json(['success' => false, 'message' => 'Este trabajo no es tuyo.'], 403);
        }
        if (!$orden->ruta_iniciada_at && !$orden->arrived_at) {
            $orden->forceFill(['ruta_iniciada_at' => H::guardar(H::ahora())])->save();
        }
        return response()->json(['success' => true, 'ruta_iniciada_at' => $orden->ruta_iniciada_at]);
    }

    // POST /mercado-trabajos/{id}/seguir-esperando — el siguiente aviso de tardanza sale en 15 min
    public function seguirEsperando(Request $request, $id)
    {
        $orden = $this->orden($id);
        $this->exigirDueno($request->user(), $orden);
        if (H::listo()) {
            $orden->forceFill(['aviso_tardanza_at' => H::guardar(H::ahora())])->save();
        }
        return response()->json([
            'success' => true,
            'message' => 'Listo. Si en ' . H::REPETIR_TARDANZA_MIN . ' minutos no ha llegado, te volvemos a avisar.',
        ]);
    }

    // POST /mercado-trabajos/{id}/tecnico-no-llego — se le quita el trabajo y vuelve al Mercado
    public function tecnicoNoLlego(Request $request, $id)
    {
        $user = $request->user();
        $orden = $this->orden($id);
        $this->exigirDueno($user, $orden);

        if (!H::esFlujoNuevo($orden) || $orden->status !== 'Asignado') {
            return response()->json(['success' => false, 'message' => 'Este trabajo no está esperando al técnico.'], 422);
        }
        if ($orden->arrived_at) {
            return response()->json(['success' => false, 'message' => 'El técnico ya marcó que llegó a tu propiedad.'], 422);
        }
        $hora = H::crudo($orden, 'scheduled_at');
        if (!$hora || H::ahora()->lt($hora->copy()->addMinutes(H::TOLERANCIA_MIN))) {
            return response()->json([
                'success' => false,
                'message' => 'Podrás regresar el trabajo al Mercado ' . H::TOLERANCIA_MIN . ' minutos después de la hora acordada.',
            ], 422);
        }

        $quote = NetworkQuote::withoutGlobalScopes()->where('work_order_id', $orden->id)->where('status', 'accepted')->latest('updated_at')->first();
        $tecnicoId = (int) ($orden->tecnico_id ?: $quote?->technician_id);
        $equipo = H::tecnicosDe($orden);
        $horaTxt = H::textoHora($hora);

        DB::transaction(function () use ($orden, $quote, $tecnicoId, $hora, $user) {
            if ($tecnicoId) {
                DB::table('tecnicos_no_llegaron')->insert([
                    'work_order_id' => $orden->id,
                    'technician_id' => $tecnicoId,
                    'client_user_id' => $user?->id ?? H::duenoId($orden),
                    'scheduled_at' => H::guardar($hora),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            if ($quote) {
                $quote->status = 'rejected';
                if (Schema::hasColumn('network_quotes', 'motivo_rechazo')) $quote->motivo_rechazo = 'No llegó a la visita.';
                $quote->save();
            }

            $orden->forceFill([
                'status' => 'Por Hacer',
                'tecnico_id' => null,
                'scheduled_at' => null,
                'ruta_iniciada_at' => null,
                'recordatorio_20_at' => null,
                'recordatorio_10_at' => null,
                'aviso_tardanza_at' => null,
                'sin_tecnico_avisado_at' => null,
                'reprogramacion_propuesta' => null,
                'reprogramacion_usada' => false,
                'publish_network' => 1,
                'tecnico_dirigido_id' => null,
            ]);
            // Las otras cotizaciones vigentes se pueden elegir; el técnico confirma que sigue disponible
            if (Schema::hasColumn('work_orders', 'reabierta_at')) $orden->reabierta_at = now();
            $orden->save();

            DB::table('work_order_technician')->where('work_order_id', $orden->id)->delete();
            if (Schema::hasTable('network_quote_ajustes')) {
                DB::table('network_quote_ajustes')->where('work_order_id', $orden->id)->where('status', 'pending')
                    ->update(['status' => 'retirado', 'updated_at' => now()]);
            }
        });

        try {
            \App\Services\AccesoQr::purgarTrabajo($orden, 'El técnico no llegó');
        } catch (\Throwable $e) {
            Log::warning("No llegó: no se pudo borrar el acceso del trabajo {$orden->id}: " . $e->getMessage());
        }

        if ($quote) {
            $historial = $quote->chat_history ?? [];
            $historial[] = [
                'sender_id' => $user?->id ?? 0,
                'sender_name' => H::nombre($user),
                'sender_role' => 'Cliente',
                'message' => "🚫 El cliente regresó el trabajo al Mercado: el técnico no llegó a la visita de las {$horaTxt}.",
                'is_cancellation' => true,
                'created_at' => now()->toIso8601String(),
            ];
            $quote->chat_history = $historial;
            $quote->save();
        }

        if ($tecnicoId) {
            // Sus avisos de este trabajo quedan solo como información; este también
            H::avisosSoloInformativos($tecnicoId, (int) $orden->id);
            H::avisar($tecnicoId, '🚫 Te quitaron el trabajo',
                'El cliente regresó "' . H::titulo($orden) . "\" al Mercado porque no llegaste a la visita de las {$horaTxt}. Quedó registrado como \"No llegó\".",
                null, 'tecnico_no_llego', (int) $orden->id, ['solo_informativa' => true]);
        }
        // Técnicos de su cuadrilla que iban
        foreach (array_diff($equipo, [$tecnicoId]) as $id) {
            H::avisosSoloInformativos((int) $id, (int) $orden->id);
            H::avisar((int) $id, 'Trabajo cancelado', 'El cliente regresó "' . H::titulo($orden) . '" al Mercado. Ya no tienes que ir.',
                null, 'tecnico_no_llego', (int) $orden->id, ['solo_informativa' => true]);
        }

        $reenviadoPor = $user?->id;
        app()->terminating(fn () => \App\Services\AvisoTrabajosRed::nuevoTrabajo($orden->fresh(), $reenviadoPor));

        return response()->json([
            'success' => true,
            'message' => 'Tu solicitud volvió al Mercado. Puedes elegir otra cotización vigente o esperar nuevas.',
        ]);
    }

    // POST /mercado-trabajos/{id}/reprogramar-solicitud { urgente, fecha, hora } — nadie cotizó a tiempo
    public function reprogramarSolicitud(Request $request, $id)
    {
        $datos = $request->validate([
            'urgente' => 'required|boolean',
            'fecha' => 'nullable|required_if:urgente,false|date_format:Y-m-d',
            'hora' => 'nullable|date_format:H:i',
        ], ['fecha.required_if' => 'Elige el día que necesitas el trabajo.']);

        $user = $request->user();
        $orden = $this->orden($id);
        $this->exigirDueno($user, $orden);
        if (!H::listo()) return response()->json(['success' => false, 'message' => 'Función no disponible todavía.'], 422);
        if ($orden->status !== 'Por Hacer' || $orden->tecnico_id) {
            return response()->json(['success' => false, 'message' => 'Esta solicitud ya tiene técnico.'], 422);
        }

        $hoy = H::ahora()->format('Y-m-d');
        $urgente = (bool) $datos['urgente'];
        $fecha = $urgente ? $hoy : $datos['fecha'];
        $hora = $urgente ? null : ($datos['hora'] ?? null);
        if ($fecha < $hoy || $fecha > H::ahora()->addDays(H::MAX_DIAS_ADELANTE)->format('Y-m-d')) {
            return response()->json(['success' => false, 'message' => 'Elige una fecha entre hoy y los próximos ' . H::MAX_DIAS_ADELANTE . ' días.'], 422);
        }
        if ($hora && H::leer("$fecha $hora")->lt(H::ahora()->addMinutes(H::MINIMO_ADELANTO_MIN))) {
            return response()->json(['success' => false, 'message' => 'Esa hora ya pasó. Elige una más tarde.'], 422);
        }

        $orden->forceFill([
            'priority' => $urgente ? 'Urgente' : 'Normal',
            'fecha_deseada' => $fecha,
            'hora_deseada' => $hora,
            'sin_tecnico_avisado_at' => null,
            'publish_network' => 1,
            'description' => H::notaEnDescripcion((string) $orden->description, $urgente, $fecha, $hora),
        ]);
        // Las cotizaciones anteriores se pueden elegir, pero el técnico confirma que sigue disponible
        if (Schema::hasColumn('work_orders', 'reabierta_at')) $orden->reabierta_at = now();
        $orden->save();

        $por = $user?->id;
        app()->terminating(fn () => \App\Services\AvisoTrabajosRed::nuevoTrabajo($orden->fresh(), $por));

        return response()->json([
            'success' => true,
            'message' => $urgente
                ? 'Listo. Tu solicitud se publicó otra vez como urgente.'
                : 'Listo. Tu solicitud se publicó otra vez para el ' . H::textoFecha(H::leer($fecha)) . ($hora ? ' a las ' . H::textoHora(H::leer("$fecha $hora")) : '') . '.',
        ]);
    }

    /**
     * Técnico, después de aceptar: propone 1 sola vez otro horario (lo llama /programar-visita).
     * La visita no cambia hasta que el cliente lo apruebe.
     */
    public static function proponerReprogramacion(Request $request, WorkOrder $orden, User $user)
    {
        if (!AccesoQrRedController::esTecnicoDelTrabajo($user, $orden) || $orden->status !== 'Asignado') {
            return response()->json(['success' => false, 'message' => 'Este trabajo no está asignado a ti.'], 403);
        }
        if ($orden->arrived_at) {
            return response()->json(['success' => false, 'message' => 'Ya marcaste que llegaste; el horario ya no se puede cambiar.'], 422);
        }
        if ($orden->reprogramacion_usada) {
            return response()->json([
                'success' => false,
                'reason' => 'limite_agendados',
                'message' => 'Ya usaste tu única re-programación. Si no puedes ir, cancela el servicio.',
            ], 422);
        }
        $nuevo = H::leer($request->input('scheduled_at'));
        if (!$nuevo || $nuevo->lt(H::ahora()->addMinutes(H::MINIMO_ADELANTO_MIN))) {
            return response()->json(['success' => false, 'message' => 'Elige un horario al menos ' . H::MINIMO_ADELANTO_MIN . ' minutos después de ahora.'], 422);
        }
        $nuevo->second(0);
        $actual = H::crudo($orden, 'scheduled_at');
        $nota = trim((string) $request->input('notes'));

        $orden->forceFill(['reprogramacion_propuesta' => H::guardar($nuevo), 'reprogramacion_usada' => true])->save();

        $quote = NetworkQuote::withoutGlobalScopes()->where('work_order_id', $orden->id)->where('status', 'accepted')->latest('updated_at')->first();
        if ($quote) {
            $historial = $quote->chat_history ?? [];
            $historial[] = [
                'sender_id' => $user->id,
                'sender_name' => H::nombre($user),
                'sender_role' => 'Técnico de la Red',
                'message' => '🗓️ Pide cambiar la visita al ' . H::texto($nuevo) . ($actual ? ' (antes ' . H::texto($actual) . ')' : '') . '.' . ($nota ? " Nota: \"{$nota}\"" : ''),
                'is_reprogramacion' => true,
                'created_at' => now()->toIso8601String(),
            ];
            $quote->chat_history = $historial;
            $quote->save();
        }

        H::avisarCliente($orden, '🗓️ ' . H::nombre($user) . ' pide cambiar la visita',
            'Propone el ' . H::texto($nuevo) . ($actual ? ' en lugar del ' . H::texto($actual) : '') . '. Acéptalo o mantén el horario original.'
            . ($nota ? " Nota: \"{$nota}\"" : ''),
            'horario_reprogramacion');

        return response()->json([
            'success' => true,
            'message' => 'Le pedimos al cliente que apruebe el cambio. Mientras tanto, la visita sigue el ' . H::texto($actual) . '. Ya no podrás cambiarla otra vez.',
            'reprogramacion_propuesta' => H::guardar($nuevo),
            'cambios_restantes' => 0,
            'chat_history' => $quote?->chat_history ?? [],
        ]);
    }

    /** Cliente: aprueba (confirm) o rechaza (reschedule) la re-programación (lo llama /responder-visita-cliente). */
    public static function responderReprogramacion(Request $request, WorkOrder $orden, ?User $user)
    {
        if (!AjusteCotizacionController::puedeResponderPorDueno($user, $orden)) {
            return response()->json(['success' => false, 'message' => 'Solo el dueño del trabajo puede responder.'], 403);
        }
        $propuesta = H::crudo($orden, 'reprogramacion_propuesta');
        if (!$propuesta) {
            return response()->json(['success' => false, 'message' => 'No hay ningún cambio de horario pendiente.'], 422);
        }
        $acepta = $request->input('action') === 'confirm';
        $anterior = H::crudo($orden, 'scheduled_at');

        $cambios = ['reprogramacion_propuesta' => null];
        if ($acepta) {
            $cambios += [
                'scheduled_at' => H::guardar($propuesta),
                'recordatorio_20_at' => null,
                'recordatorio_10_at' => null,
                'aviso_tardanza_at' => null,
            ];
        }
        $orden->forceFill($cambios)->save();

        $quote = NetworkQuote::withoutGlobalScopes()->where('work_order_id', $orden->id)->where('status', 'accepted')->latest('updated_at')->first();
        $nombre = H::nombre($user);
        if ($quote) {
            $historial = $quote->chat_history ?? [];
            $historial[] = [
                'sender_id' => $user?->id ?? 0,
                'sender_name' => $nombre,
                'sender_role' => 'Cliente',
                'message' => $acepta
                    ? '✅ Aceptó el cambio: la visita queda el ' . H::texto($propuesta) . '.'
                    : '↩️ Rechazó el cambio: la visita sigue el ' . H::texto($anterior) . '.',
                'is_reprogramacion_respuesta' => true,
                'created_at' => now()->toIso8601String(),
            ];
            $quote->chat_history = $historial;
            $quote->save();
        }

        foreach (H::tecnicosDe($orden) as $id) {
            H::avisar($id,
                $acepta ? '✅ El cliente aceptó el nuevo horario' : '↩️ El cliente mantuvo el horario original',
                $acepta
                    ? '"' . H::titulo($orden) . '" queda el ' . H::texto($propuesta) . '.'
                    : '"' . H::titulo($orden) . '" sigue el ' . H::texto($anterior) . '. Si no puedes ir, cancela el servicio.',
                '/mercado-trabajos?trabajo=' . $orden->id, 'horario_reprogramacion_respuesta', (int) $orden->id);
        }

        return response()->json([
            'success' => true,
            'message' => $acepta ? 'Horario actualizado: ' . H::texto($propuesta) . '.' : 'Se mantiene el horario original.',
            'chat_history' => $quote?->chat_history ?? [],
        ]);
    }
}
