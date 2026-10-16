<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use App\Notifications\TeamCalendarNoticeNotification;

class CalendarEventController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $query = CalendarEvent::with('creator:id,first_name,last_name,profile_picture')
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
                'start_time' => 'nullable|string|max:20',
                'end_time' => 'nullable|string|max:20',
                'type' => 'nullable|string|max:50',
                'color' => 'nullable|string|max:30',
                'notify_team' => 'nullable|boolean',
            ]);

            $event = new CalendarEvent();
            $event->tenant_id = $user->tenant_id;
            $event->created_by = $user->id;
            $event->title = $validated['title'];
            $event->description = $validated['description'] ?? null;
            $event->event_date = $validated['event_date'];
            $event->start_time = $validated['start_time'] ?? null;
            $event->end_time = $validated['end_time'] ?? null;
            $event->type = $validated['type'] ?? 'aviso';
            $event->color = $validated['color'] ?? '#F26522';
            $event->notify_team = isset($validated['notify_team']) ? (bool)$validated['notify_team'] : true;
            $event->save();

            // Enviar notificacion al equipo si esta marcado
            if ($event->notify_team) {
                try {
                    $teamQuery = User::withoutGlobalScopes()
                        ->where('is_active', 1)
                        ->whereIn('role_id', [1, 2]);

                    if (!empty($user->tenant_id)) {
                        $teamQuery->where('tenant_id', $user->tenant_id);
                    } else {
                        $teamQuery->where(function($q) {
                            $q->whereNull('tenant_id')->orWhere('tenant_id', 1);
                        });
                    }

                    $recipients = $teamQuery->get();
                    if ($recipients->isNotEmpty()) {
                        Notification::send($recipients, new TeamCalendarNoticeNotification($event));
                    }
                } catch (\Throwable $notifErr) {
                    \Log::error("Error enviando notificaciones de evento calendario: " . $notifErr->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Aviso registrado exitosamente.',
                'event' => $event->load('creator:id,first_name,last_name,profile_picture')
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al crear evento: ' . $e->getMessage()], 500);
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
