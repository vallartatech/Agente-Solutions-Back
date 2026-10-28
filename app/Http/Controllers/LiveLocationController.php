<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserLocation;
use App\Services\PlaceVisitService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ubicación en vivo del equipo (admins rol 1 y técnicos rol 2).
 * - Solo se guarda la ubicación de quien dio su consentimiento.
 * - Solo los usuarios con can_view_live_map pueden consultar el mapa (validado aquí, no solo en la UI).
 * - Cada consulta al mapa queda registrada en location_access_logs.
 * - Quien tiene can_pause_location puede pausar su ubicación: no se guarda nada y el mapa
 *   muestra "Ubicación pausada" en lugar de su posición (visible para quien consulta el mapa).
 */
class LiveLocationController extends Controller
{
    private const TRACKED_ROLES = [1, 2];
    private const ONLINE_MINUTES = 5;
    private const MIN_LIVE_SECONDS = 20;
    private const RETENTION_DAYS = 30;
    private const ZONA_LOCAL = 'America/Merida'; // el "día" del recorrido es el día local

    // Estado del usuario actual: consentimiento y permiso de mapa
    // (no se expone quién puede ver el mapa)
    public function status(Request $request)
    {
        $user = $request->user();
        $tracked = in_array((int) $user->role_id, self::TRACKED_ROLES, true);

        return response()->json([
            'tracked_role' => $tracked,
            'consent' => !is_null($user->location_consent_at),
            'consent_at' => $user->location_consent_at,
            'can_view_map' => (bool) $user->can_view_live_map,
            'can_pause' => (bool) $user->can_pause_location,
            'paused' => !is_null($user->location_paused_at),
            'paused_at' => $user->location_paused_at,
            'pause_status' => $user->location_pause_status,
        ]);
    }

    // Pausar (con un estado: "En junta", "Ocupado"...) o reanudar la ubicación propia.
    // Solo con el permiso can_pause_location.
    public function pause(Request $request)
    {
        $user = $request->user();
        if (!$user->can_pause_location) {
            return response()->json(['error' => 'No tienes permiso para pausar tu ubicación.'], 403);
        }

        $validated = $request->validate([
            'paused' => 'required|boolean',
            'status' => 'nullable|string|max:40',
        ]);

        if ($validated['paused']) {
            $estado = trim((string) ($validated['status'] ?? ''));
            $user->location_paused_at = $user->location_paused_at ?? now();
            $user->location_pause_status = $estado !== '' ? $estado : 'Ocupado';
            // Mientras está pausada no se sabe dónde está: se cierra su visita sin avisar
            app(PlaceVisitService::class)->cerrarSinAviso($user);
        } else {
            $user->location_paused_at = null;
            $user->location_pause_status = null;
        }
        $user->save();

        return response()->json([
            'paused' => !is_null($user->location_paused_at),
            'paused_at' => $user->location_paused_at,
            'pause_status' => $user->location_pause_status,
        ]);
    }

    public function consent(Request $request)
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);
        $user = $request->user();

        if (!in_array((int) $user->role_id, self::TRACKED_ROLES, true)) {
            return response()->json(['error' => 'Tu rol no comparte ubicación.'], 403);
        }

        // Una vez activada, el usuario no puede desactivarla (decisión de la empresa)
        if (!$validated['enabled']) {
            return response()->json(['error' => 'La ubicación no se puede desactivar desde la app.'], 403);
        }

        $user->location_consent_at = $user->location_consent_at ?? now();
        $user->save();

        return response()->json(['consent' => $validated['enabled']]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!in_array((int) $user->role_id, self::TRACKED_ROLES, true) || is_null($user->location_consent_at)) {
            return response()->json(['stored' => false, 'reason' => 'no_consent'], 202);
        }
        // Ubicación pausada: no se guarda nada aunque llegue
        if ($user->can_pause_location && !is_null($user->location_paused_at)) {
            return response()->json(['stored' => false, 'reason' => 'paused'], 202);
        }

        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'source' => 'nullable|string|in:live,app_open,job_update',
            'work_order_id' => 'nullable|integer',
        ]);
        $source = $validated['source'] ?? 'live';

        // Evitar ráfagas: los envíos "live" se aceptan como máximo cada MIN_LIVE_SECONDS
        if ($source === 'live') {
            $last = UserLocation::where('user_id', $user->id)->orderByDesc('recorded_at')->value('recorded_at');
            if ($last && Carbon::parse($last)->diffInSeconds(now()) < self::MIN_LIVE_SECONDS) {
                return response()->json(['stored' => false, 'reason' => 'throttled'], 202);
            }
        }

        UserLocation::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'lat' => $validated['lat'],
            'lng' => $validated['lng'],
            'accuracy' => $validated['accuracy'] ?? null,
            'source' => $source,
            'work_order_id' => $validated['work_order_id'] ?? null,
            'recorded_at' => now(),
        ]);

        // ¿Llegó a una propiedad registrada o se fue de ella? (avisa a quienes ven el mapa)
        app(PlaceVisitService::class)->procesar(
            $user,
            (float) $validated['lat'],
            (float) $validated['lng'],
            isset($validated['accuracy']) ? (float) $validated['accuracy'] : null
        );

        return response()->json(['stored' => true], 201);
    }

    // Equipo con su última ubicación conocida
    public function team(Request $request)
    {
        $viewer = $request->user();
        if (!$viewer->can_view_live_map) {
            return response()->json(['error' => 'No tienes permiso para ver el mapa del equipo.'], 403);
        }

        $this->pruneOldLocations();
        $this->logAccess($viewer->id, null, 'view_team');

        $members = $this->teamQuery($viewer)
            ->get(['id', 'first_name', 'last_name', 'role_id', 'profile_picture', 'phone_number', 'location_consent_at', 'location_paused_at', 'location_pause_status']);

        $latestIds = UserLocation::whereIn('user_id', $members->pluck('id'))
            ->groupBy('user_id')
            ->select(DB::raw('MAX(id) as id'))
            ->pluck('id');
        $latest = UserLocation::whereIn('id', $latestIds)->get()->keyBy('user_id');

        $onlineSince = now()->subMinutes(self::ONLINE_MINUTES);
        // En qué propiedad registrada está cada quien ahora (detectado por GPS)
        $lugares = app(PlaceVisitService::class)->lugaresActuales($members->pluck('id')->all());

        $data = $members->map(function ($m) use ($latest, $onlineSince, $lugares) {
            // Pausada: se informa que está pausada y no se muestra ninguna posición
            $pausada = !is_null($m->location_paused_at);
            $loc = $pausada ? null : $latest->get($m->id);
            return [
                'id' => $m->id,
                'name' => trim("{$m->first_name} {$m->last_name}"),
                'role_id' => (int) $m->role_id,
                'profile_picture' => $m->profile_picture,
                'phone_number' => $m->phone_number,
                'sharing' => !is_null($m->location_consent_at),
                'paused' => $pausada,
                'paused_at' => $pausada ? Carbon::parse($m->location_paused_at)->toIso8601String() : null,
                'pause_status' => $pausada ? ($m->location_pause_status ?: 'Ocupado') : null,
                'place' => $pausada ? null : ($lugares[$m->id] ?? null),
                'online' => $loc && $loc->recorded_at->greaterThanOrEqualTo($onlineSince),
                'last_location' => $loc ? [
                    'lat' => $loc->lat,
                    'lng' => $loc->lng,
                    'accuracy' => $loc->accuracy,
                    'source' => $loc->source,
                    'work_order_id' => $loc->work_order_id,
                    'recorded_at' => $loc->recorded_at->toIso8601String(),
                ] : null,
            ];
        })->values();

        return response()->json([
            'online_minutes' => self::ONLINE_MINUTES,
            'server_time' => now()->toIso8601String(),
            'members' => $data,
        ]);
    }

    // Recorrido de una persona en un día (por defecto hoy)
    public function route(Request $request, $userId)
    {
        $viewer = $request->user();
        if (!$viewer->can_view_live_map) {
            return response()->json(['error' => 'No tienes permiso para ver el mapa del equipo.'], 403);
        }

        $member = $this->teamQuery($viewer)->where('id', (int) $userId)->first(['id']);
        if (!$member) {
            return response()->json(['error' => 'Usuario no encontrado en tu equipo.'], 404);
        }

        // "Hoy" en hora de Mérida (el servidor puede estar en UTC: sin esto, después de las 6 p.m.
        // locales el recorrido mostraba ya el día siguiente). Se convierte a la zona en que se guardan las fechas.
        $hoy = now(self::ZONA_LOCAL)->startOfDay();
        $dia = $hoy->copy();
        if ($request->query('date')) {
            try {
                $dia = Carbon::createFromFormat('Y-m-d', (string) $request->query('date'), self::ZONA_LOCAL)->startOfDay();
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Fecha inválida.'], 422);
            }
            // Historial de 30 días (hoy y los 29 anteriores); lo más viejo ya se borró
            if ($dia->gt($hoy) || $dia->lt($this->primerDiaConservado())) {
                return response()->json(['error' => 'Solo se guarda el historial de los últimos ' . self::RETENTION_DAYS . ' días.'], 422);
            }
        }
        $inicio = $dia->copy()->startOfDay()->setTimezone(config('app.timezone'));
        $fin = $dia->copy()->endOfDay()->setTimezone(config('app.timezone'));
        $this->logAccess($viewer->id, $member->id, 'view_route');

        $points = UserLocation::where('user_id', $member->id)
            ->whereBetween('recorded_at', [$inicio, $fin])
            ->orderBy('recorded_at')
            ->get(['lat', 'lng', 'accuracy', 'source', 'work_order_id', 'recorded_at'])
            ->map(fn ($p) => [
                'lat' => $p->lat,
                'lng' => $p->lng,
                'accuracy' => $p->accuracy,
                'source' => $p->source,
                'work_order_id' => $p->work_order_id,
                'recorded_at' => $p->recorded_at->toIso8601String(),
            ]);

        // En qué propiedades estuvo ese día, de qué hora a qué hora (detectado por GPS)
        $visitas = app(PlaceVisitService::class)->visitasDelRango($member->id, $inicio, $fin);

        return response()->json([
            'user_id' => $member->id,
            'date' => $dia->toDateString(),
            'min_date' => $this->primerDiaConservado()->toDateString(),
            'points' => $points,
            'visitas' => $visitas,
        ]);
    }

    // Solo root (rol 0) puede dar o quitar el permiso de ver el mapa
    public function setViewer(Request $request, $userId)
    {
        $actor = $request->user();
        if ((int) $actor->role_id !== 0) {
            return response()->json(['error' => 'Solo root puede cambiar este permiso.'], 403);
        }

        $validated = $request->validate(['enabled' => 'required|boolean']);
        $target = User::withoutGlobalScopes()->findOrFail((int) $userId);
        $target->can_view_live_map = $validated['enabled'];
        $target->save();

        return response()->json(['user_id' => $target->id, 'can_view_live_map' => (bool) $target->can_view_live_map]);
    }

    private function teamQuery(User $viewer)
    {
        $query = User::withoutGlobalScopes()
            ->where('is_active', 1)
            ->whereIn('role_id', self::TRACKED_ROLES);

        return $this->sameTeam($query, $viewer)->orderBy('first_name');
    }

    // Mismo equipo: mismo tenant; tenant nulo y tenant 1 son el equipo de Agente Solutions
    private function sameTeam($query, User $user)
    {
        if (!empty($user->tenant_id) && (int) $user->tenant_id !== 1) {
            return $query->where('tenant_id', $user->tenant_id);
        }

        return $query->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', 1));
    }

    private function logAccess(int $viewerId, ?int $viewedUserId, string $action): void
    {
        DB::table('location_access_logs')->insert([
            'viewer_id' => $viewerId,
            'viewed_user_id' => $viewedUserId,
            'action' => $action,
            'created_at' => now(),
        ]);
    }

    // Primer día (hora de Mérida) que todavía se conserva: hoy y los 29 días anteriores
    private function primerDiaConservado(): Carbon
    {
        return now(self::ZONA_LOCAL)->startOfDay()->subDays(self::RETENTION_DAYS - 1);
    }

    // Borrado automático del historial antiguo por día completo (sin depender de un cron):
    // al entrar un día nuevo se elimina el más viejo, de cada persona.
    private function pruneOldLocations(): void
    {
        $limite = $this->primerDiaConservado()->setTimezone(config('app.timezone'));
        UserLocation::where('recorded_at', '<', $limite)->limit(1000)->delete();
        // Visitas a propiedades que terminaron antes del límite (las abiertas se conservan)
        UserPlaceVisit::whereNotNull('left_at')->where('left_at', '<', $limite)->limit(1000)->delete();
    }
}
