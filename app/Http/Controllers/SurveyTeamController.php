<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\User;
use App\Notifications\SurveyTeamNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equipo de trabajo del levantamiento de una propiedad.
 *
 * El levantamiento (zonas, habitaciones, elementos) pertenece a la propiedad, así que todos
 * los técnicos del equipo ven y editan exactamente lo mismo. El equipo vive en la tabla
 * service_technician del servicio "Levantamiento"; services.assigned_to es el responsable.
 * Solo administradores (rol 0 y 1) administran el equipo y finalizan el levantamiento.
 */
class SurveyTeamController extends Controller
{
    private const ADMIN_ROLES = [0, 1];
    private const CLOSED_STATUSES = ['completed', 'Finalizado', 'Listo'];

    public function show(Request $request, $propertyId)
    {
        if ($error = $this->denyIfNotAdmin($request)) {
            return $error;
        }

        $property = Property::withoutGlobalScopes()->find((int) $propertyId);
        if (!$property) {
            return response()->json(['error' => 'Propiedad no encontrada.'], 404);
        }

        $service = $this->surveyService($property->id);
        // Zonas ya registradas (p. ej. un admin empezó el levantamiento por su cuenta)
        $zonas = DB::table('property_areas')->where('property_id', $property->id)->count();

        if (!$service) {
            // Sin levantamiento asignado: se puede crear eligiendo al primer técnico
            return response()->json([
                'property_id' => $property->id,
                'property_name' => $property->property_name,
                'zonas_registradas' => $zonas,
                'service' => null,
                'members' => [],
                'available' => $this->availableTechs($request->user(), [])->values(),
            ]);
        }

        $members = $this->members($service);

        return response()->json([
            'property_id' => $property->id,
            'property_name' => $property->property_name,
            'zonas_registradas' => $zonas,
            'service' => [
                'id' => $service->id,
                'title' => $service->title,
                'status' => $service->status,
                'finalizado' => in_array($service->status, self::CLOSED_STATUSES, true),
                'finalizado_at' => $service->real_end,
            ],
            'members' => $members->values(),
            'available' => $this->availableTechs($request->user(), $members->pluck('id')->all())->values(),
        ]);
    }

    public function add(Request $request, $propertyId)
    {
        if ($error = $this->denyIfNotAdmin($request)) {
            return $error;
        }

        $validated = $request->validate(['technician_id' => 'required|integer']);
        $property = Property::withoutGlobalScopes()->find((int) $propertyId);
        if (!$property) {
            return response()->json(['error' => 'Propiedad no encontrada.'], 404);
        }

        $tech = $this->sameTeam(User::withoutGlobalScopes(), $request->user())
            ->where('id', $validated['technician_id'])
            ->whereIn('role_id', [1, 2]) // técnicos y admins pueden formar parte de la cuadrilla
            ->where('is_active', 1)
            ->first();
        if (!$tech) {
            return response()->json(['error' => 'Técnico no válido para este equipo.'], 422);
        }

        // Si la propiedad aún no tiene levantamiento asignado, se crea con este técnico como responsable.
        // Si un admin ya había registrado zonas, el equipo continúa sobre ese mismo levantamiento.
        $service = $this->surveyService($property->id);
        $creado = false;
        if (!$service) {
            $techName = trim("{$tech->first_name} {$tech->last_name}");
            $serviceId = DB::table('services')->insertGetId([
                'tenant_id' => $property->tenant_id,
                'property_id' => $property->id,
                'title' => 'Levantamiento Inicial',
                'description' => "Levantamiento asignado al equipo desde Propiedades. Técnico asignado: {$techName}",
                'status' => 'Programado',
                'priority' => 'Media',
                'supervisor_name' => $techName ?: 'Equipo de levantamiento',
                'assigned_to' => $tech->id,
                'scheduled_start' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $service = DB::table('services')->where('id', $serviceId)->first();
            $creado = true;
        }

        DB::table('service_technician')->insertOrIgnore([
            'service_id' => $service->id,
            'technician_id' => $tech->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (empty($service->assigned_to)) {
            DB::table('services')->where('id', $service->id)->update(['assigned_to' => $tech->id, 'updated_at' => now()]);
        }

        $yaIniciado = DB::table('property_areas')->where('property_id', $property->id)->exists();
        [$titulo, $mensaje] = $creado
            ? ['📋 Nuevo levantamiento asignado', $yaIniciado
                ? "Te asignaron el levantamiento de {$property->property_name}. Ya tiene zonas registradas: continúa desde donde se quedó."
                : "Te asignaron el levantamiento de {$property->property_name}."]
            : ['👥 Te agregaron a un equipo', "Ahora formas parte del equipo del levantamiento de {$property->property_name}. Puedes ver y editar el levantamiento."];
        $tech->notify(new SurveyTeamNotification($titulo, $mensaje, $property->id, $service->id));

        return $this->show($request, $property->id);
    }

    public function remove(Request $request, $propertyId, $technicianId)
    {
        if ($error = $this->denyIfNotAdmin($request)) {
            return $error;
        }

        $property = Property::withoutGlobalScopes()->find((int) $propertyId);
        $service = $property ? $this->surveyService($property->id) : null;
        if (!$service) {
            return response()->json(['error' => 'La propiedad no tiene un levantamiento asignado.'], 404);
        }

        $members = $this->members($service);
        if ($members->count() <= 1) {
            return response()->json(['error' => 'El equipo debe tener al menos un técnico.'], 422);
        }

        $technicianId = (int) $technicianId;
        DB::table('service_technician')
            ->where('service_id', $service->id)
            ->where('technician_id', $technicianId)
            ->delete();

        // Si se quitó al responsable, el siguiente del equipo toma su lugar
        if ((int) $service->assigned_to === $technicianId) {
            $next = $members->firstWhere('id', '!=', $technicianId);
            DB::table('services')->where('id', $service->id)->update(['assigned_to' => $next['id'] ?? null, 'updated_at' => now()]);
        }

        return $this->show($request, $property->id);
    }

    // Marca el levantamiento como completo. Se puede seguir editando después.
    public function finalize(Request $request, $propertyId)
    {
        if ($error = $this->denyIfNotAdmin($request)) {
            return $error;
        }

        $property = Property::withoutGlobalScopes()->find((int) $propertyId);
        $service = $property ? $this->surveyService($property->id) : null;
        if (!$service) {
            return response()->json(['error' => 'La propiedad no tiene un levantamiento asignado.'], 404);
        }

        DB::table('services')->where('id', $service->id)->update([
            'status' => 'completed',
            'real_end' => now(),
            'updated_at' => now(),
        ]);
        DB::table('properties')->where('id', $property->id)->update(['levantamiento_realizado' => true]);

        $memberIds = $this->members($service)->pluck('id');
        User::withoutGlobalScopes()->whereIn('id', $memberIds)->get()->each(fn ($u) => $u->notify(new SurveyTeamNotification(
            '✅ Levantamiento finalizado',
            "El administrador marcó como completo el levantamiento de {$property->property_name}. Si hace falta, todavía puedes editarlo.",
            $property->id,
            $service->id
        )));

        return $this->show($request, $property->id);
    }

    // Levantamiento más reciente de la propiedad (misma regla que el resto del sistema: el título dice "levantamiento")
    private function surveyService(int $propertyId)
    {
        return DB::table('services')
            ->where('property_id', $propertyId)
            ->where('title', 'like', '%evantamiento%')
            ->orderByDesc('id')
            ->first();
    }

    private function members($service)
    {
        $ids = DB::table('service_technician')
            ->where('service_id', $service->id)
            ->pluck('technician_id')
            ->push($service->assigned_to)
            ->filter()
            ->unique()
            ->values();

        return User::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name', 'profile_picture'])
            ->map(fn ($u) => $this->userPayload($u, (int) $u->id === (int) $service->assigned_to))
            ->sortByDesc('is_lead')
            ->values();
    }

    // Técnicos (rol 2) y admins (rol 1) activos, del mismo equipo, que aún no están en el levantamiento
    // (sin contar a quien está asignando)
    private function availableTechs(User $viewer, array $excludeIds)
    {
        return $this->sameTeam(User::withoutGlobalScopes(), $viewer)
            ->whereIn('role_id', [1, 2])
            ->where('is_active', 1)
            ->where('id', '!=', $viewer->id)
            ->whereNotIn('id', $excludeIds)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'profile_picture'])
            ->map(fn ($u) => $this->userPayload($u, false));
    }

    private function userPayload($u, bool $isLead): array
    {
        return [
            'id' => $u->id,
            'name' => trim("{$u->first_name} {$u->last_name}"),
            'profile_picture' => $u->profile_picture,
            'is_lead' => $isLead,
        ];
    }

    private function denyIfNotAdmin(Request $request)
    {
        $user = $request->user();
        if (!$user || !in_array((int) $user->role_id, self::ADMIN_ROLES, true)) {
            return response()->json(['error' => 'Solo un administrador puede administrar el equipo del levantamiento.'], 403);
        }
        return null;
    }

    // Mismo equipo: mismo tenant; tenant nulo y tenant 1 son el equipo de Agente Solutions; root ve a todos
    private function sameTeam($query, User $user)
    {
        if ((int) $user->role_id === 0) {
            return $query;
        }
        if (!empty($user->tenant_id) && (int) $user->tenant_id !== 1) {
            return $query->where('tenant_id', $user->tenant_id);
        }
        return $query->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', 1));
    }
}
