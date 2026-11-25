<?php

namespace App\Services;

use App\Http\Controllers\MisClientesController;
use App\Models\NetworkQuote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\MovimientoNotificacion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Market: el horario va en la cotización (plan 2026-11-25). Solo solicitudes nuevas (flujo_horario = 1).
 *
 * - El cliente publica con fecha y hora (o "a cualquier hora"); urgente = hoy, lo antes posible.
 * - El técnico cotiza con una hora exacta. Cada hora distinta a la del cliente cuenta 1 cambio, máximo 2.
 *   Reenviar la misma hora, o la que pidió el cliente, o una nueva después de que la anterior venció, no cuenta.
 * - El cliente acepta precio y horario juntos.
 * - La cotización vence 1 h antes de su horario (si se envió con menos de 2 h de anticipación, vence a la hora).
 * - Recordatorios al técnico 20 y 10 min antes; 15 min tarde sin "Llegué" se avisa al cliente cada 15 min.
 * - Urgente sin cotizaciones vigentes a las 8 pm, o programada con la fecha ya pasada: el cliente elige
 *   programar otro día o cancelar.
 *
 * Todas las horas se guardan en hora de Mérida sin zona, igual que scheduled_at.
 */
class HorarioMercado
{
    public const ZONA = 'America/Merida';
    public const MAX_CAMBIOS = 2;
    public const VENCE_ANTES_MIN = 60;
    public const ANTICIPACION_CORTA_MIN = 120;
    public const MINIMO_ADELANTO_MIN = 15;
    public const RECORDATORIOS_MIN = [20, 10];
    public const TOLERANCIA_MIN = 15;
    public const REPETIR_TARDANZA_MIN = 15;
    public const ESPERA_CONFIRMAR_LLEGADA_MIN = 15;
    public const CORTE_URGENTES = '20:00';
    public const MAX_DIAS_ADELANTE = 90;

    private static ?bool $listo = null;

    /** ¿Ya corrió la migración 2026_11_25_130000? Sin ella todo sigue como antes. */
    public static function listo(): bool
    {
        return self::$listo ??= Schema::hasColumn('work_orders', 'flujo_horario')
            && Schema::hasColumn('network_quotes', 'horario_propuesto')
            && Schema::hasTable('tecnicos_no_llegaron');
    }

    public static function ahora(): Carbon
    {
        return Carbon::now(self::ZONA);
    }

    /** Fecha y hora de Mérida sin zona ('Y-m-d H:i:s', 'Y-m-dTH:i', Carbon) → Carbon en Mérida. */
    public static function leer($valor): ?Carbon
    {
        if ($valor instanceof Carbon) {
            return Carbon::parse($valor->format('Y-m-d H:i:s'), self::ZONA);
        }
        if (!$valor) return null;
        try {
            return Carbon::parse(str_replace('T', ' ', (string) $valor), self::ZONA);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Valor guardado tal cual (sin el cast de Eloquent, que lo interpretaría en la zona de la app). */
    public static function crudo($modelo, string $campo): ?Carbon
    {
        return self::leer($modelo->getAttributes()[$campo] ?? null);
    }

    public static function guardar(Carbon $c): string
    {
        return $c->copy()->setTimezone(self::ZONA)->format('Y-m-d H:i:s');
    }

    public static function esFlujoNuevo(?WorkOrder $orden): bool
    {
        return $orden && self::listo() && (bool) $orden->flujo_horario;
    }

    public static function esUrgente(WorkOrder $orden): bool
    {
        return $orden->priority === 'Urgente';
    }

    /** [fecha 'Y-m-d', hora 'H:i' o null] que pidió el cliente. */
    public static function horarioDelCliente(WorkOrder $orden): array
    {
        $attrs = $orden->getAttributes();
        $fecha = !empty($attrs['fecha_deseada']) ? substr((string) $attrs['fecha_deseada'], 0, 10) : null;
        if (!$fecha) {
            $creada = $orden->created_at ? $orden->created_at->copy()->setTimezone(self::ZONA) : self::ahora();
            $fecha = $creada->format('Y-m-d');
        }
        $hora = !empty($attrs['hora_deseada']) ? substr((string) $attrs['hora_deseada'], 0, 5) : null;
        return [$fecha, $hora ?: null];
    }

    public static function coincideConCliente(WorkOrder $orden, Carbon $horario): bool
    {
        [$fecha, $hora] = self::horarioDelCliente($orden);
        if ($horario->format('Y-m-d') !== $fecha) return false;
        return $hora === null || $horario->format('H:i') === $hora;
    }

    /** Cuándo vence la cotización (null si no tiene horario). */
    public static function venceEn(NetworkQuote $quote): ?Carbon
    {
        $horario = self::crudo($quote, 'horario_propuesto');
        if (!$horario) return null;
        $cotizada = $quote->cotizada_at ?? $quote->updated_at ?? $quote->created_at;
        $cotizada = $cotizada ? Carbon::parse($cotizada)->setTimezone(self::ZONA) : self::ahora();
        // Cotización de último momento (urgentes): si venciera 1 h antes, nacería vencida
        if ($cotizada->diffInMinutes($horario, false) < self::ANTICIPACION_CORTA_MIN) {
            return $horario;
        }
        return $horario->copy()->subMinutes(self::VENCE_ANTES_MIN);
    }

    public static function estaVencida(NetworkQuote $quote): bool
    {
        if (!self::listo() || $quote->status !== 'pending') return false;
        if ($quote->vencida_at) return true;
        $vence = self::venceEn($quote);
        return $vence !== null && self::ahora()->gte($vence);
    }

    /**
     * Valida la hora que propone el técnico al cotizar.
     * Devuelve ['horario' => Carbon, 'cambios' => int] o ['error' => mensaje].
     */
    public static function validarCotizacion(WorkOrder $orden, ?NetworkQuote $anterior, $valor): array
    {
        $horario = self::leer($valor);
        if (!$horario) {
            return ['error' => 'Elige el día y la hora en que puedes hacer el trabajo.'];
        }
        $horario->second(0);
        $ahora = self::ahora();
        if ($horario->lt($ahora->copy()->addMinutes(self::MINIMO_ADELANTO_MIN))) {
            return ['error' => 'El horario debe ser al menos ' . self::MINIMO_ADELANTO_MIN . ' minutos después de ahora.'];
        }
        if ($horario->gt($ahora->copy()->addDays(self::MAX_DIAS_ADELANTE))) {
            return ['error' => 'El horario no puede ser a más de ' . self::MAX_DIAS_ADELANTE . ' días.'];
        }

        $cambios = (int) ($anterior?->cambios_horario ?? 0);
        $previo = $anterior ? self::crudo($anterior, 'horario_propuesto') : null;
        $mismoQueAntes = $previo && $previo->format('Y-m-d H:i') === $horario->format('Y-m-d H:i');
        $anteriorVencida = $anterior && ($anterior->vencida_at || ($anterior->status === 'pending' && self::estaVencida($anterior)));

        if (self::coincideConCliente($orden, $horario) || $mismoQueAntes || $anteriorVencida) {
            return ['horario' => $horario, 'cambios' => $cambios];
        }
        if ($cambios >= self::MAX_CAMBIOS) {
            [$fecha, $hora] = self::horarioDelCliente($orden);
            $delCliente = self::textoFecha(Carbon::parse($fecha, self::ZONA)) . ($hora ? ' a las ' . self::textoHora(Carbon::parse("$fecha $hora", self::ZONA)) : '');
            return ['error' => "Ya cambiaste el horario " . self::MAX_CAMBIOS . " veces. Envía el horario del cliente ({$delCliente})"
                . ($previo ? ' o el último que propusiste (' . self::texto($previo) . ').' : '.')];
        }
        return ['horario' => $horario, 'cambios' => $cambios + 1];
    }

    // ───────────── Textos ─────────────

    public static function textoFecha(Carbon $c): string
    {
        return $c->copy()->locale('es')->isoFormat('dddd D [de] MMMM');
    }

    // "2:30 p. m." → se usa "2:30 pm" para no chocar con el punto final de las frases
    public static function textoHora(Carbon $c): string
    {
        return $c->format('g:i') . ($c->format('H') < 12 ? ' am' : ' pm');
    }

    /** "jueves 26 de noviembre a las 10:00 a. m." */
    public static function texto(?Carbon $c): string
    {
        return $c ? self::textoFecha($c) . ' a las ' . self::textoHora($c) : '';
    }

    public static function titulo(WorkOrder $o): string
    {
        return trim($o->type . ($o->equipment ? ' - ' . $o->equipment : '')) ?: 'el trabajo';
    }

    public static function nombre(?User $u): string
    {
        if (!$u) return 'El técnico';
        return trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->name ?? 'El técnico');
    }

    // ───────────── Avisos ─────────────

    /** Campanita + push (el push sale solo con PushForDatabaseNotifications). */
    public static function avisar(int $userId, string $titulo, string $mensaje, ?string $url, string $tipo, int $workOrderId, array $extra = []): void
    {
        try {
            $destino = User::withoutGlobalScopes()->find($userId);
            $destino?->notify(new MovimientoNotificacion([
                'titulo' => $titulo,
                'mensaje' => $mensaje,
                'url' => $url,
                'alert_type' => $tipo,
                'extra' => ['work_order_id' => $workOrderId] + $extra,
            ]));
        } catch (\Throwable $e) {
            Log::warning("Horario Mercado: no se pudo avisar al usuario {$userId}: " . $e->getMessage());
        }
    }

    /** Dueño e invitados de la propiedad (nunca Root/Admin ni el tenant). */
    public static function avisarCliente(WorkOrder $orden, string $titulo, string $mensaje, string $tipo, ?string $url = null, array $extra = []): void
    {
        foreach (AccesoPropiedad::interesadosDeOrden($orden->id) as $id) {
            self::avisar((int) $id, $titulo, $mensaje, $url ?? '/red-autonomos?trabajo=' . $orden->id, $tipo, (int) $orden->id, $extra);
        }
    }

    /** El titular y, si es contratista, los técnicos de su cuadrilla que van. */
    public static function tecnicosDe(WorkOrder $orden): array
    {
        $ids = [];
        if ($orden->tecnico_id) $ids[] = (int) $orden->tecnico_id;
        if (Schema::hasTable('work_order_technician')) {
            foreach (DB::table('work_order_technician')->where('work_order_id', $orden->id)->pluck('technician_id') as $id) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    // ───────────── "No llegó" ─────────────

    public static function tecnicoNoLlego(int $workOrderId, int $tecnicoId): bool
    {
        return self::listo() && DB::table('tecnicos_no_llegaron')
            ->where('work_order_id', $workOrderId)->where('technician_id', $tecnicoId)->exists();
    }

    /** El técnico ya no ve los trabajos que le quitaron por no llegar. */
    public static function excluirNoLlegados($query, ?int $tecnicoId): void
    {
        if (!$tecnicoId || !self::listo()) return;
        $query->whereNotIn('id', DB::table('tecnicos_no_llegaron')->where('technician_id', $tecnicoId)->select('work_order_id'));
    }

    /** Sus avisos de ese trabajo se quedan solo como información: sin enlace. */
    public static function avisosSoloInformativos(int $tecnicoId, int $workOrderId): void
    {
        try {
            $avisos = DB::table('notifications')
                ->where('notifiable_id', $tecnicoId)
                ->where(function ($q) use ($workOrderId) {
                    $q->where('data', 'like', '%"work_order_id":' . $workOrderId . ',%')
                      ->orWhere('data', 'like', '%"work_order_id":' . $workOrderId . '}%')
                      ->orWhere('data', 'like', '%"work_order_id":"' . $workOrderId . '"%');
                })
                ->get(['id', 'data']);
            foreach ($avisos as $aviso) {
                $data = json_decode($aviso->data, true);
                if (!is_array($data)) continue;
                $data['solo_informativa'] = true;
                $data['url'] = null;
                DB::table('notifications')->where('id', $aviso->id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
            }
        } catch (\Throwable $e) {
            Log::warning("Horario Mercado: no se pudieron dejar informativos los avisos del trabajo {$workOrderId}: " . $e->getMessage());
        }
    }

    // ───────────── Al aceptar ─────────────

    /** El cliente aceptó precio y horario juntos: la visita queda confirmada. */
    public static function alAceptar(NetworkQuote $quote, WorkOrder $orden): void
    {
        if (!self::esFlujoNuevo($orden)) return;
        $horario = self::crudo($quote, 'horario_propuesto');
        if (!$horario) return;

        $orden->forceFill([
            'scheduled_at' => self::guardar($horario),
            'recordatorio_20_at' => null,
            'recordatorio_10_at' => null,
            'aviso_tardanza_at' => null,
            'ruta_iniciada_at' => null,
            'reprogramacion_propuesta' => null,
            'reprogramacion_usada' => false,
        ])->save();

        // Para las pantallas que leen el horario del chat: propuesta del técnico + confirmación del cliente
        $historial = $quote->chat_history ?? [];
        $ahora = now()->toIso8601String();
        $historial[] = [
            'sender_id' => $quote->technician_id,
            'sender_name' => 'Agente Market',
            'sender_role' => 'Sistema',
            'message' => '📅 Visita acordada para el ' . self::texto($horario) . '.',
            'is_schedule' => true,
            'scheduled_at' => self::guardar($horario),
            'schedule_status' => 'confirmed',
            'created_at' => $ahora,
        ];
        $historial[] = [
            'sender_id' => 0,
            'sender_name' => 'Agente Market',
            'sender_role' => 'Sistema',
            'message' => '✅ El cliente aceptó la cotización con este horario.',
            'is_schedule_response' => true,
            'schedule_confirmed' => true,
            'created_at' => $ahora,
        ];
        $quote->chat_history = $historial;
        $quote->save();
    }

    // ───────────── Tareas automáticas (cada minuto) ─────────────

    public static function tareas(): array
    {
        if (!self::listo()) return ['listo' => false];
        $r = ['vencidas' => 0, 'recordatorios' => 0, 'tardanzas' => 0, 'sin_tecnico' => 0];
        foreach (['vencerCotizaciones', 'recordatorios', 'tardanzas', 'sinTecnico'] as $i => $tarea) {
            try {
                $r[array_keys($r)[$i]] = self::$tarea();
            } catch (\Throwable $e) {
                Log::error("Horario Mercado ({$tarea}): " . $e->getMessage());
            }
        }
        return $r;
    }

    private static function vencerCotizaciones(): int
    {
        $n = 0;
        $quotes = NetworkQuote::withoutGlobalScopes()
            ->where('status', 'pending')
            ->whereNotNull('horario_propuesto')
            ->whereNull('vencida_at')
            ->where('horario_propuesto', '<=', self::guardar(self::ahora()->addMinutes(self::VENCE_ANTES_MIN)))
            ->whereIn('work_order_id', WorkOrder::withoutGlobalScopes()->where('flujo_horario', true)->where('status', 'Por Hacer')->select('id'))
            ->get();

        foreach ($quotes as $quote) {
            if (!self::estaVencida($quote)) continue;
            $quote->vencida_at = self::guardar(self::ahora());
            if (Schema::hasColumn('network_quotes', 'confirmacion_pedida_at')) $quote->confirmacion_pedida_at = null;
            $quote->save();
            $n++;

            $orden = WorkOrder::withoutGlobalScopes()->find($quote->work_order_id);
            if (!$orden) continue;
            self::avisar((int) $quote->technician_id, '⏰ Tu cotización venció',
                'El cliente no aceptó a tiempo tu cotización para "' . self::titulo($orden) . '" ('
                . self::texto(self::crudo($quote, 'horario_propuesto')) . '). Envía un nuevo horario; este cambio no cuenta en tus ' . self::MAX_CAMBIOS . '.',
                '/mercado-trabajos?trabajo=' . $orden->id . '&editar=1', 'network_quote_vencida', (int) $orden->id);
        }
        return $n;
    }

    private static function ordenesAsignadasDelDia()
    {
        $ahora = self::ahora();
        return WorkOrder::withoutGlobalScopes()
            ->where('flujo_horario', true)
            ->where('status', 'Asignado')
            ->whereNull('arrived_at')
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [
                self::guardar($ahora->copy()->subHours(12)),
                self::guardar($ahora->copy()->addMinutes(max(self::RECORDATORIOS_MIN) + 1)),
            ])
            ->get();
    }

    private static function recordatorios(): int
    {
        $n = 0;
        $ahora = self::ahora();
        foreach (self::ordenesAsignadasDelDia() as $orden) {
            $hora = self::crudo($orden, 'scheduled_at');
            if (!$hora || $ahora->gte($hora)) continue;
            $faltan = $ahora->diffInMinutes($hora, false);

            // 10 min antes: solo si no va en camino (si ya va, se marca sin enviar). Si ya toca el de 10, el de 20 no sale.
            if ($faltan <= 10 && !$orden->recordatorio_10_at) {
                $orden->forceFill(['recordatorio_10_at' => self::guardar($ahora), 'recordatorio_20_at' => $orden->recordatorio_20_at ?: self::guardar($ahora)])->save();
                if ($orden->ruta_iniciada_at) continue;
                $n += self::recordar($orden, $hora, 10);
            } elseif ($faltan <= 20 && $faltan > 10 && !$orden->recordatorio_20_at) {
                $orden->forceFill(['recordatorio_20_at' => self::guardar($ahora)])->save();
                $n += self::recordar($orden, $hora, 20);
            }
        }
        return $n;
    }

    private static function recordar(WorkOrder $orden, Carbon $hora, int $minutos): int
    {
        $tecnicos = self::tecnicosDe($orden);
        foreach ($tecnicos as $id) {
            self::avisar($id, "⏰ Tu trabajo es en {$minutos} minutos",
                '"' . self::titulo($orden) . '" es a las ' . self::textoHora($hora) . '. ' . ($orden->ruta_iniciada_at ? 'Vas en camino.' : '¿Ya vas en camino? Presiona "Comenzar ruta".'),
                '/mercado-trabajos?trabajo=' . $orden->id, 'recordatorio_trabajo', (int) $orden->id, ['minutos' => $minutos]);
        }
        return count($tecnicos) ? 1 : 0;
    }

    private static function tardanzas(): int
    {
        $n = 0;
        $ahora = self::ahora();
        foreach (self::ordenesAsignadasDelDia() as $orden) {
            $hora = self::crudo($orden, 'scheduled_at');
            if (!$hora || $ahora->lt($hora->copy()->addMinutes(self::TOLERANCIA_MIN))) continue;
            $ultimo = self::crudo($orden, 'aviso_tardanza_at');
            if ($ultimo && $ahora->lt($ultimo->copy()->addMinutes(self::REPETIR_TARDANZA_MIN))) continue;

            $primera = !$ultimo;
            $orden->forceFill(['aviso_tardanza_at' => self::guardar($ahora)])->save();
            $tecnico = $orden->tecnico_id ? User::withoutGlobalScopes()->find($orden->tecnico_id) : null;
            $nombre = self::nombre($tecnico);
            $enCamino = (bool) $orden->ruta_iniciada_at;

            self::avisarCliente($orden,
                $enCamino ? "🚗 {$nombre} va en camino" : "⏰ {$nombre} no ha llegado",
                'La visita era a las ' . self::textoHora($hora) . '. ' . ($enCamino
                    ? 'Marcó que va en camino, pero aún no llega. Puedes seguir esperando o regresar el trabajo al Mercado.'
                    : 'Puedes seguir esperando o regresar el trabajo al Mercado.'),
                'tecnico_no_ha_llegado');

            if ($primera && $orden->tecnico_id) {
                self::avisar((int) $orden->tecnico_id, '⚠️ El cliente te está esperando',
                    'Tu visita para "' . self::titulo($orden) . '" era a las ' . self::textoHora($hora) . '. Si no llegas, el cliente puede quitarte el trabajo.',
                    '/mercado-trabajos?trabajo=' . $orden->id, 'tecnico_retrasado', (int) $orden->id);
            }
            $n++;
        }
        return $n;
    }

    private static function sinTecnico(): int
    {
        $n = 0;
        $ahora = self::ahora();
        $ordenes = WorkOrder::withoutGlobalScopes()
            ->where('flujo_horario', true)
            ->where('status', 'Por Hacer')
            ->where('publish_network', 1)
            ->whereNull('sin_tecnico_avisado_at')
            ->where(fn ($q) => $q->whereNull('tecnico_id')->orWhere('tecnico_id', 0))
            ->get();

        foreach ($ordenes as $orden) {
            [$fecha, $hora] = self::horarioDelCliente($orden);
            if (self::esUrgente($orden)) {
                $corte = Carbon::parse($fecha . ' ' . self::CORTE_URGENTES, self::ZONA);
                // Publicada (o reprogramada) ya de noche: al menos 2 h para recibir cotizaciones
                $creada = $orden->created_at ? $orden->created_at->copy()->setTimezone(self::ZONA) : $ahora;
                if (!empty($orden->reabierta_at)) {
                    $reabierta = Carbon::parse($orden->reabierta_at)->setTimezone(self::ZONA);
                    if ($reabierta->gt($creada)) $creada = $reabierta;
                }
                if ($creada->copy()->addHours(2)->gt($corte)) $corte = $creada->copy()->addHours(2);
            } else {
                $corte = Carbon::parse($fecha . ' ' . ($hora ?: '23:59'), self::ZONA);
            }
            if ($ahora->lt($corte)) continue;

            $vigentes = NetworkQuote::withoutGlobalScopes()->where('work_order_id', $orden->id)->where('status', 'pending')
                ->where('price', '>', 0)->get()->filter(fn ($q) => !self::estaVencida($q));
            if ($vigentes->isNotEmpty()) continue;

            $orden->forceFill(['sin_tecnico_avisado_at' => self::guardar($ahora)])->save();
            self::avisarCliente($orden,
                self::esUrgente($orden) ? '😕 Nadie estuvo disponible hoy' : '📅 La fecha de tu solicitud ya pasó',
                '"' . self::titulo($orden) . '" no tiene cotizaciones vigentes. Prográmala para otro día o cancélala.',
                'mercado_sin_tecnico', '/red-autonomos?trabajo=' . $orden->id . '&reprogramar=1');
            $n++;
        }
        return $n;
    }

    // ───────────── Nota en la descripción (los técnicos la leen) ─────────────

    /** Cambia la nota "[FECHA / HORARIO DESEADO]" o "[URGENCIA]" de la descripción. */
    public static function notaEnDescripcion(string $descripcion, bool $urgente, ?string $fecha, ?string $hora): string
    {
        $limpia = preg_replace('/\n*\s*\[(FECHA \/ HORARIO DESEADO|URGENCIA)\]:[^\n]*/u', '', $descripcion);
        if ($urgente) {
            return rtrim($limpia) . "\n\n[URGENCIA]: ⚡ Atención Urgente - Hoy Mismo";
        }
        $horaTxt = $hora ? self::textoHoraNota($hora) : 'Cualquier hora';
        return rtrim($limpia) . "\n\n[FECHA / HORARIO DESEADO]: {$fecha} - {$horaTxt}";
    }

    // '14:30' → '2:30 PM' (formato que ya leen las pantallas)
    private static function textoHoraNota(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return (($h % 12) ?: 12) . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . ($h >= 12 ? ' PM' : ' AM');
    }

    /** Dueño de la orden (para el historial "No llegó"). */
    public static function duenoId(WorkOrder $orden): ?int
    {
        $id = MisClientesController::duenoDeOrden((int) $orden->id);
        return $id ? (int) $id : null;
    }
}
