<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserLocation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ubicación en vivo del equipo (admins rol 1 y técnicos rol 2).
 * - Solo se guarda la ubicación de quien dio su consentimiento.
 * - Solo los usuarios con can_view_live_map pueden consultar el mapa (validado aquí, no solo en la UI).
 * - Cada consulta al mapa queda registrada en location_access_logs.
 */
class LiveLocationController extends Controller
{
    private const TRACKED_ROLES = [1, 2];
    private const ONLINE_MINUTES = 5;
    private const MIN_LIVE_SECONDS = 20;
    private const RETENTION_DAYS = 30;

    // Estado del usuario actual: consentimiento, permiso de mapa y quién puede verlo
    public function status(Request $request)
    {
        $user = $request->user();
        $tracked = in_array((int) $user->role_id, self::TRACKED_ROLES, true);

        return response()->json([
            'tracked_role' => $tracked,
            'consent' => !is_null($user->location_consent_at),
            'consent_at' => $user->location_consent_at,
            'can_view_map' => (bool) $user->can_view_live_map,
            // Transparencia: quién puede ver la ubicación de este usuario
            'viewers' => $tracked
                ? $this->viewersFor($user)->map(fn ($u) => trim("{$u->first_name} {$u->last_name}"))->values()
                : [],
        ]);
    }

    public function consent(Request $request)
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);
        $user = $request->user();

        if (!in_array((int) $user->role_id, self::TRACKED_ROLES, true)) {
            return response()->json(['error' => 'Tu rol no comparte ubicación.'], 403);
        }

        $user->location_consent_at = $validated['enabled'] ? now() : null;
        $user->save();

        return response()->json(['consent' => $validated['enabled']]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!in_array((int) $user->role_id, self::TRACKED_ROLES, true) || is_null($user->location_consent_at)) {
            return response()->json(['stored' => false, 'reason' => 'no_consent'], 202);
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
            ->get(['id', 'first_name', 'last_name', 'role_id', 'profile_picture', 'phone_number', 'location_consent_at']);

        $latestIds = UserLocation::whereIn('user_id', $members->pluck('id'))
            ->groupBy('user_id')
            ->select(DB::raw('MAX(id) as id'))
            ->pluck('id');
        $latest = UserLocation::whereIn('id', $latestIds)->get()->keyBy('user_id');

        $onlineSince = now()->subMinutes(self::ONLINE_MINUTES);

        $data = $members->map(function ($m) use ($latest, $onlineSince) {
            $loc = $latest->get($m->id);
            return [
                'id' => $m->id,
                'name' => trim("{$m->first_name} {$m->last_name}"),
                'role_id' => (int) $m->role_id,
                'profile_picture' => $m->profile_picture,
                'phone_number' => $m->phone_number,
                'sharing' => !is_null($m->location_consent_at),
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

        $date = $request->query('date') ? Carbon::parse($request->query('date')) : now();
        $this->logAccess($viewer->id, $member->id, 'view_route');

        $points = UserLocation::where('user_id', $member->id)
            ->whereBetween('recorded_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
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

        return response()->json(['user_id' => $member->id, 'points' => $points]);
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

    private function viewersFor(User $user)
    {
        $query = User::withoutGlobalScopes()->where('can_view_live_map', true)->where('is_active', 1);

        return $this->sameTeam($query, $user)->get(['first_name', 'last_name']);
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

    // Borrado automático del historial antiguo (sin depender de un cron)
    private function pruneOldLocations(): void
    {
        UserLocation::where('recorded_at', '<', now()->subDays(self::RETENTION_DAYS))->limit(1000)->delete();
    }
}
