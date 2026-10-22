<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use App\Notifications\TeamCalendarNoticeNotification;
use App\Services\OneSignalPush;
use Illuminate\Support\Facades\DB;

class CalendarEventController extends Controller
{
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

    // Avisos del mismo equipo que el usuario (misma regla que index)
    private function teamEvents($user)
    {
        $query = CalendarEvent::query();
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

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'event_date' => 'required|date',
                'description' => 'nullable|string|max:1000',
                'start_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
                'end_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
                'type' => 'nullable|string|max:50',
                'color' => 'nullable|string|max:30',
                'notify_team' => 'nullable|boolean',
                'label' => 'nullable|string|max:60',
                'parent_id' => 'nullable|integer',
                'participant_ids' => 'nullable|array|max:100',
                'participant_ids.*' => 'integer',
            ], [
                'start_time.regex' => 'La hora de inicio no es válida.',
                'end_time.regex' => 'La hora de fin no es válida.',
            ]);

            // Horario: la hora de fin requiere inicio y debe ser posterior
            $inicio = $validated['start_time'] ?? null;
            $fin = $validated['end_time'] ?? null;
            if ($fin && !$inicio) {
                return response()->json(['error' => 'Indica también la hora de inicio.'], 422);
            }
            if ($inicio && $fin && substr($fin, 0, 5) <= substr($inicio, 0, 5)) {
                return response()->json(['error' => 'La hora de fin debe ser posterior a la de inicio.'], 422);
            }

            // Etiqueta libre: se reutiliza la ya escrita por el equipo (mismas mayúsculas) si coincide
            $label = trim(preg_replace('/\s+/', ' ', (string) ($validated['label'] ?? '')));
            if ($label !== '') {
                $existente = $this->teamEvents($user)
                    ->whereNotNull('label')
                    ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
                    ->value('label');
                $label = $existente ?: $label;
            }

            // Seguimiento: el aviso anterior debe ser del mismo equipo
            $parent = null;
            if (!empty($validated['parent_id'])) {
                $parent = $this->teamEvents($user)->find($validated['parent_id']);
                if (!$parent) {
                    return response()->json(['error' => 'El aviso anterior ya no existe.'], 422);
                }
            }

            // Participantes: solo se aceptan miembros del equipo; vacío = todo el equipo
            $participantIds = [];
            if (!empty($validated['participant_ids'])) {
                $participantIds = $this->teamMembers($user)
                    ->whereIn('id', array_unique($validated['participant_ids']))
                    ->pluck('id')
                    ->all();
                if (!$participantIds) {
                    return response()->json(['error' => 'Las personas elegidas ya no están activas en el equipo.'], 422);
                }
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
            // Todo aviso se notifica: a sus participantes o, si no tiene, a todo el equipo
            $event->notify_team = true;
            $event->save();
            if ($participantIds) {
                $event->participants()->sync($participantIds);
            }

            $event->setRelation('parent', $parent);
            $push = $event->notify_team
                ? $this->notifyTeam($user, $event, new TeamCalendarNoticeNotification($event))
                : null;

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
            $event->completed_at = now();
            $event->completed_by = $user->id;
            $event->completion_note = $note !== '' ? $note : null;
            $event->save();

            $push = null;
            if ($note !== '') {
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
            if (!$event) {
                return response()->json(['error' => 'Evento no encontrado.'], 404);
            }

            $isRoot = (int)$user->role_id === 0;
            $isCreator = (int)$event->created_by === (int)$user->id;
            $isSameTenant = !empty($user->tenant_id) && (int)$event->tenant_id === (int)$user->tenant_id;

            if (!$isRoot && !$isCreator && !$isSameTenant) {
                return response()->json(['error' => 'No autorizado para eliminar este evento.'], 403);
            }

            $event->delete();
            return response()->json(['success' => true, 'message' => 'Evento eliminado correctamente.'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al eliminar: ' . $e->getMessage()], 500);
        }
    }
}
