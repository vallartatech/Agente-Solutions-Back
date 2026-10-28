<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use App\Notifications\TeamCalendarNoticeNotification;
use App\Services\OneSignalPush;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CalendarEventController extends Controller
{
    // La hora de los avisos es hora local (el servidor puede estar en UTC)
    private const ZONA_LOCAL = 'America/Merida';

    private const EVENT_RELATIONS = [
        'creator:id,first_name,last_name,profile_picture',
        'completer:id,first_name,last_name',
        'participants:id,first_name,last_name,profile_picture,role_id',
    ];

    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $query = CalendarEvent::with(self::EVENT_RELATIONS)
                ->orderBy('event_date', 'asc');

            if ($user && $user->role_id === 0) {
                // ROOT ve todos
            } elseif ($user && !empty($user->tenant_id)) {
                $query->where('tenant_id', $user->tenant_id);
            } else {
                $query->where(function($q) {
                    $q->whereNull('tenant_id')->orWhere('tenant_id', 1);
                });
            }

            // Los privados solo los ve quien los publicó, sin excepción (ni Root ni "ver todo el calendario")
            $this->ocultarPrivadosAjenos($query, $user);

            // Solo quien tiene "ver todo el calendario" ve todos los avisos; los demás (admins incluidos)
            // ven los de todo el equipo, en los que participan y los que publicaron
            if ($user && !$user->can_view_all_calendar) {
                $query->where(function ($q) use ($user) {
                    $q->whereDoesntHave('participants')
                        ->orWhereHas('participants', fn ($p) => $p->where('users.id', $user->id))
                        ->orWhere('created_by', $user->id);
                });
            }

            $events = $query->get();
            return response()->json($events, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener eventos: ' . $e->getMessage()], 500);
        }
    }

    public function getBirthdays(Request $request)
    {
        try {
            $user = $request->user();
            $query = User::withoutGlobalScopes()
                ->whereNotNull('birth_date')
                ->where('birth_date', '!=', '')
                ->where('is_active', 1)
                ->whereIn('role_id', [1, 2]); // Admins y Tecnicos

            if ($user && $user->role_id === 0) {
                // ROOT ve los de todos los tecnicos y admins
            } elseif ($user && !empty($user->tenant_id)) {
                $query->where('tenant_id', $user->tenant_id);
            } else {
                $query->where(function($q) {
                    $q->whereNull('tenant_id')->orWhere('tenant_id', 1);
                });
            }

            $users = $query->get(['id', 'first_name', 'last_name', 'profile_picture', 'role_id', 'birth_date']);

            $birthdays = $users->map(function($u) {
                $fotoUrl = $u->profile_picture ? (str_starts_with($u->profile_picture, 'http') ? $u->profile_picture : asset('storage/' . $u->profile_picture)) : null;
                $dateTimestamp = strtotime($u->birth_date);
                return [
                    'id' => 'bday_' . $u->id,
                    'user_id' => $u->id,
                    'name' => trim("{$u->first_name} {$u->last_name}"),
                    'role_id' => $u->role_id,
                    'profile_picture' => $fotoUrl,
                    'birth_date' => $u->birth_date,
                    'month' => (int) date('m', $dateTimestamp),
                    'day' => (int) date('d', $dateTimestamp),
                ];
            });

            return response()->json($birthdays, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener cumpleaños: ' . $e->getMessage()], 500);
        }
    }

    // Etiquetas ya usadas por el equipo, las más usadas primero (sugerencias del formulario)
    public function labels(Request $request)
    {
        try {
            $labels = $this->teamEvents($request->user())
                ->whereNotNull('label')
                ->where('label', '!=', '')
                ->select('label', DB::raw('COUNT(*) as usos'), DB::raw('MAX(created_at) as ultimo'))
                ->groupBy('label')
                ->orderByDesc('usos')
                ->orderByDesc('ultimo')
                ->limit(30)
                ->pluck('label');

            return response()->json($labels->values(), 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener etiquetas: ' . $e->getMessage()], 500);
        }
    }

    // Admins y técnicos activos del equipo: opciones del selector de participantes
    public function team(Request $request)
    {
        try {
            $members = $this->teamMembers($request->user())
                ->orderBy('role_id')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'profile_picture', 'role_id']);

            return response()->json($members, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener el equipo: ' . $e->getMessage()], 500);
        }
    }

    // Root, admins y técnicos activos del mismo equipo que el usuario
    private function teamMembers($user)
    {
        $query = User::withoutGlobalScopes()
            ->where('is_active', 1)
            ->whereIn('role_id', [0, 1, 2]);

        if (!empty($user?->tenant_id)) {
            return $query->where('tenant_id', $user->tenant_id);
        }
        return $query->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', 1));
    }

    private function ocultarPrivadosAjenos($query, $user)
    {
        return $query->where(fn ($q) => $q->where('is_private', false)->orWhere('created_by', $user?->id));
    }

    // Avisos del mismo equipo que el usuario (misma regla que index); los privados de otros no cuentan
    private function teamEvents($user)
    {
        $query = $this->ocultarPrivadosAjenos(CalendarEvent::query(), $user);
        if ($user && (int) $user->role_id === 0) {
            return $query;
        }
        if ($user && !empty($user->tenant_id)) {
            return $query->where('tenant_id', $user->tenant_id);
        }
        return $query->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', 1));
    }

    public function store(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user || !in_array((int)$user->role_id, [0, 1])) {
                return response()->json(['error' => 'No autorizado. Solo administradores pueden publicar avisos.'], 403);
            }

            $validated = $request->validate(self::reglasAviso() + [
                'notify_team' => 'nullable|boolean',
                'parent_id' => 'nullable|integer',
            ], self::MENSAJES_HORARIO);

            // Horario: la hora de fin requiere inicio y debe ser posterior
            $inicio = $validated['start_time'] ?? null;
            $fin = $validated['end_time'] ?? null;
            if ($error = $this->errorHorario($inicio, $fin)) {
                return response()->json(['error' => $error], 422);
            }

            $label = $this->normalizarEtiqueta($user, $validated['label'] ?? null);

            // Seguimiento: el aviso anterior debe ser del mismo equipo
            $parent = null;
            if (!empty($validated['parent_id'])) {
                $parent = $this->teamEvents($user)->find($validated['parent_id']);
                if (!$parent) {
                    return response()->json(['error' => 'El aviso anterior ya no existe.'], 422);
                }
            }

            // Privado: solo para quien lo publica, sin participantes ni notificaciones
            $esPrivado = (bool) ($validated['is_private'] ?? false);

            $participantIds = $esPrivado ? [] : $this->participantesValidos($user, $validated['participant_ids'] ?? []);
            if ($participantIds === null) {
                return response()->json(['error' => 'Las personas elegidas ya no están activas en el equipo.'], 422);
            }

            $event = new CalendarEvent();
            $event->parent_id = $parent?->id;
            $event->tenant_id = $user->tenant_id;
            $event->created_by = $user->id;
            $event->title = $validated['title'];
            $event->description = $validated['description'] ?? null;
            $event->event_date = $validated['event_date'];
            $event->start_time = $inicio ? substr($inicio, 0, 5) : null;
            $event->end_time = $fin ? substr($fin, 0, 5) : null;
            $event->label = $label !== '' ? $label : null;
            $event->type = $validated['type'] ?? 'aviso';
            $event->color = $validated['color'] ?? '#F26522';
            $event->is_private = $esPrivado;
            // Todo aviso se notifica (a sus participantes o, si no tiene, a todo el equipo), salvo los privados
            $event->notify_team = !$esPrivado;
            $event->save();
            if ($participantIds) {
                $event->participants()->sync($participantIds);
            }

            $event->setRelation('parent', $parent);
            $push = $event->notify_team
                ? $this->notifyTeam($user, $event, new TeamCalendarNoticeNotification($event))
                : null;

            // Alarma 1 hora antes (solo avisos con hora fija; "Todo el día" no lleva)
            $this->programarRecordatorio($user, $event);

            return response()->json([
                'success' => true,
                'message' => 'Aviso registrado exitosamente.',
                'push' => $push,
                'event' => $event->unsetRelation('parent')->load(self::EVENT_RELATIONS)
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Datos inválidos del formulario: 422 con el mensaje (no un error del servidor)
            return response()->json(['error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al crear evento: ' . $e->getMessage()], 500);
        }
    }

    // Campos que se pueden escribir al publicar o editar un aviso
    private static function reglasAviso(): array
    {
        return [
            'title' => 'required|string|max:255',
            'event_date' => 'required|date',
            'description' => 'nullable|string|max:1000',
            'start_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'end_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'type' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:30',
            'label' => 'nullable|string|max:60',
            'participant_ids' => 'nullable|array|max:100',
            'participant_ids.*' => 'integer',
            'is_private' => 'nullable|boolean',
        ];
    }

    private const MENSAJES_HORARIO = [
        'start_time.regex' => 'La hora de inicio no es válida.',
        'end_time.regex' => 'La hora de fin no es válida.',
    ];

    // La hora de fin requiere inicio y debe ser posterior
    private function errorHorario(?string $inicio, ?string $fin): ?string
    {
        if ($fin && !$inicio) {
            return 'Indica también la hora de inicio.';
        }
        if ($inicio && $fin && substr($fin, 0, 5) <= substr($inicio, 0, 5)) {
            return 'La hora de fin debe ser posterior a la de inicio.';
        }
        return null;
    }

    // Etiqueta libre: se reutiliza la ya escrita por el equipo (mismas mayúsculas) si coincide
    private function normalizarEtiqueta($user, ?string $label): string
    {
        $label = trim(preg_replace('/\s+/', ' ', (string) $label));
        if ($label === '') {
            return '';
        }
        $existente = $this->teamEvents($user)
            ->whereNotNull('label')
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->value('label');
        return $existente ?: $label;
    }

    // Participantes: solo miembros activos del equipo; [] = todo el equipo; null = ninguno elegido sigue activo
    private function participantesValidos($user, array $ids): ?array
    {
        if (!$ids) {
            return [];
        }
        $validos = $this->teamMembers($user)->whereIn('id', array_unique($ids))->pluck('id')->all();
        return $validos ?: null;
    }

    // Editar un aviso: solo quien lo publicó. Si cambia la fecha u hora se avisa a los involucrados
    // y la alarma de 1 hora antes se vuelve a programar.
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user || !in_array((int)$user->role_id, [0, 1])) {
                return response()->json(['error' => 'No autorizado.'], 403);
            }

            $event = $this->teamEvents($user)->find($id);
            if (!$event) {
                return response()->json(['error' => 'Aviso no encontrado.'], 404);
            }
            if ((int) $event->created_by !== (int) $user->id) {
                return response()->json(['error' => 'Solo quien publicó el aviso puede editarlo.'], 403);
            }

            $validated = $request->validate(self::reglasAviso(), self::MENSAJES_HORARIO);

            $inicio = $validated['start_time'] ?? null;
            $fin = $validated['end_time'] ?? null;
            if ($error = $this->errorHorario($inicio, $fin)) {
                return response()->json(['error' => $error], 422);
            }

            $esPrivado = (bool) ($validated['is_private'] ?? false);
            $participantIds = $esPrivado ? [] : $this->participantesValidos($user, $validated['participant_ids'] ?? []);
            if ($participantIds === null) {
                return response()->json(['error' => 'Las personas elegidas ya no están activas en el equipo.'], 422);
            }

            $fechaAntes = substr((string) $event->getRawOriginal('event_date'), 0, 10);
            $horaAntes = $event->start_time ? substr($event->start_time, 0, 5) : null;
            $label = $this->normalizarEtiqueta($user, $validated['label'] ?? null);

            $event->title = $validated['title'];
            $event->description = $validated['description'] ?? null;
            $event->event_date = $validated['event_date'];
            $event->start_time = $inicio ? substr($inicio, 0, 5) : null;
            $event->end_time = $fin ? substr($fin, 0, 5) : null;
            $event->label = $label !== '' ? $label : null;
            $event->type = $validated['type'] ?? $event->type;
            $event->color = $validated['color'] ?? $event->color;
            $event->is_private = $esPrivado;
            $event->notify_team = !$esPrivado;
            $event->save();
            $event->participants()->sync($participantIds);

            $cambioFecha = $fechaAntes !== substr((string) $validated['event_date'], 0, 10)
                || $horaAntes !== $event->start_time;

            // La alarma anterior ya no corresponde
            $this->cancelarRecordatorio($event);
            $event->save();
            $this->programarRecordatorio($user, $event);

            $push = null;
            if ($cambioFecha && !$esPrivado && !$event->completed_at) {
                $cuando = Carbon::parse($validated['event_date'], self::ZONA_LOCAL)->locale('es')->isoFormat('dddd D [de] MMMM')
                    . ($event->start_time ? " a las {$event->start_time}" : '');
                $titulo = '📅 Cambio de fecha · ' . (!empty($event->label) ? "{$event->label} · " : '') . $event->title;
                $push = $this->notifyTeam($user, $event, new TeamCalendarNoticeNotification($event, $titulo, "Ahora es el {$cuando}"));
            }

            return response()->json([
                'success' => true,
                'message' => 'Aviso actualizado.',
                'push' => $push,
                'event' => $event->load(self::EVENT_RELATIONS)
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al editar el aviso: ' . $e->getMessage()], 500);
        }
    }

    // Marca el aviso como completado. El comentario (opcional) le llega a sus participantes o a todo el equipo.
    public function complete(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user || !in_array((int)$user->role_id, [0, 1])) {
                return response()->json(['error' => 'No autorizado. Solo administradores pueden completar avisos.'], 403);
            }

            $validated = $request->validate([
                'note' => 'nullable|string|max:1000',
            ]);

            $event = $this->teamEvents($user)->find($id);
            if (!$event) {
                return response()->json(['error' => 'Aviso no encontrado.'], 404);
            }
            if ($event->completed_at) {
                return response()->json(['error' => 'Este aviso ya estaba completado.'], 422);
            }

            $note = trim((string) ($validated['note'] ?? ''));
            // Ya no es un pendiente: si su alarma no ha sonado, se cancela
            $this->cancelarRecordatorio($event);
            $event->completed_at = now();
            $event->completed_by = $user->id;
            $event->completion_note = $note !== '' ? $note : null;
            $event->save();

            $push = null;
            if ($note !== '' && !$event->is_private) {
                $autor = trim("{$user->first_name} {$user->last_name}");
                $titulo = '✅ Completado · ' . (!empty($event->label) ? "{$event->label} · " : '') . $event->title;
                $push = $this->notifyTeam($user, $event, new TeamCalendarNoticeNotification($event, $titulo, "{$autor}: {$note}"));
            }

            return response()->json([
                'success' => true,
                'message' => 'Aviso completado.',
                'push' => $push,
                'event' => $event->load(self::EVENT_RELATIONS)
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al completar el aviso: ' . $e->getMessage()], 500);
        }
    }

    // Alarma 1 hora antes de un aviso con hora fija, solo a los involucrados:
    // privado → quien lo creó; con participantes → ellos; sin participantes → todo el equipo.
    // La programa OneSignal (send_after): no depende del scheduler ni agrega nada a la campanita.
    private function programarRecordatorio($user, CalendarEvent $event): void
    {
        try {
            if (empty($event->start_time) || $event->completed_at) {
                return;
            }

            $fecha = $event->event_date instanceof \DateTimeInterface
                ? $event->event_date->format('Y-m-d')
                : substr((string) $event->event_date, 0, 10);
            $inicio = Carbon::parse($fecha . ' ' . substr($event->start_time, 0, 5), self::ZONA_LOCAL);
            $alarma = $inicio->copy()->subHour();
            // Si falta menos de una hora, ya se avisó al publicarlo: no se programa nada
            if ($alarma->lte(now()->addMinute())) {
                return;
            }

            if ($event->is_private) {
                $destinatarios = [(int) $event->created_by];
            } else {
                $participantes = $event->participants()->pluck('users.id');
                $q = $this->teamMembers($user);
                if ($participantes->isNotEmpty()) {
                    $q->whereIn('id', $participantes);
                }
                $destinatarios = $q->pluck('id')->all();
            }
            if (!$destinatarios) {
                return;
            }

            $hora = $inicio->format('g:i') . ($inicio->format('A') === 'AM' ? ' a.m.' : ' p.m.');
            $cuando = $alarma->isSameDay($inicio) ? 'hoy' : 'mañana';
            $detalle = (!empty($event->label) ? "{$event->label} · " : '') . $event->title;

            $res = OneSignalPush::sendToUsers(
                $destinatarios,
                "⏰ Tienes un pendiente {$cuando} a las {$hora}",
                $detalle,
                '/calendario',
                ['event_id' => $event->id, 'alert_type' => 'calendar_reminder'],
                $alarma
            );

            if (!empty($res['id'])) {
                $event->reminder_push_id = $res['id'];
                $event->save();
            }
        } catch (\Throwable $e) {
            \Log::error('No se pudo programar la alarma del aviso ' . $event->id . ': ' . $e->getMessage());
        }
    }

    private function cancelarRecordatorio(CalendarEvent $event): void
    {
        if (!empty($event->reminder_push_id)) {
            OneSignalPush::cancel($event->reminder_push_id);
            $event->reminder_push_id = null;
        }
    }

    // Campanita + push a los participantes del aviso o, si no tiene, a root, admins y técnicos activos del equipo.
    // Devuelve el resultado de OneSignal para que el frontend avise si no se envió.
    private function notifyTeam($user, CalendarEvent $event, TeamCalendarNoticeNotification $notification): array
    {
        try {
            $participantIds = $event->participants()->pluck('users.id');
            $teamQuery = $this->teamMembers($user);
            if ($participantIds->isNotEmpty()) {
                $teamQuery->whereIn('id', $participantIds);
            }

            $recipients = $teamQuery->get();
            if ($recipients->isEmpty()) {
                return ['sent' => false, 'recipients' => 0, 'subscribed' => 0, 'id' => null, 'error' => 'No hay administradores ni técnicos activos para notificar.'];
            }

            // Campanita (base de datos) para cada miembro
            Notification::send($recipients, $notification);

            // Push: una sola petición a OneSignal para todo el equipo
            return OneSignalPush::sendToUsers(
                $recipients->pluck('id')->all(),
                $notification->pushTitle(),
                $notification->pushBody(),
                '/calendario',
                ['event_id' => $event->id, 'alert_type' => 'calendar_event']
            );
        } catch (\Throwable $notifErr) {
            \Log::error("Error enviando notificaciones de evento calendario: " . $notifErr->getMessage());
            return ['sent' => false, 'recipients' => 0, 'subscribed' => null, 'id' => null, 'error' => 'Error interno al notificar al equipo.'];
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user || !in_array((int)$user->role_id, [0, 1])) {
                return response()->json(['error' => 'No autorizado.'], 403);
            }

            $event = CalendarEvent::find($id);
            $isCreator = $event && (int)$event->created_by === (int)$user->id;
            // Un privado ajeno ni siquiera existe para los demás
            if (!$event || ($event->is_private && !$isCreator)) {
                return response()->json(['error' => 'Evento no encontrado.'], 404);
            }

            $isRoot = (int)$user->role_id === 0;
            $isSameTenant = !empty($user->tenant_id) && (int)$event->tenant_id === (int)$user->tenant_id;

            if (!$isRoot && !$isCreator && !$isSameTenant) {
                return response()->json(['error' => 'No autorizado para eliminar este evento.'], 403);
            }

            // Que no suene la alarma de un aviso que ya no existe
            $this->cancelarRecordatorio($event);
            $event->delete();
            return response()->json(['success' => true, 'message' => 'Evento eliminado correctamente.'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al eliminar: ' . $e->getMessage()], 500);
        }
    }
}
