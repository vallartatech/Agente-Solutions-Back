<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Specialty;
use App\Models\User;

class SpecialtyController extends Controller
{
    /**
     * Obtener todas las especialidades disponibles
     */
    public function index()
    {
        $specialties = Specialty::orderBy('name')->get();
        return response()->json($specialties, 200);
    }

    /**
     * Crear una nueva especialidad
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:191|unique:specialties',
            'icon' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:191'
        ]);

        $spec = Specialty::create([
            'name' => trim($request->name),
            'icon' => $request->icon ?? '🔧',
            'category' => $request->category ?? 'General'
        ]);

        return response()->json(['success' => true, 'specialty' => $spec], 201);
    }

    /**
     * Actualizar las especialidades de un técnico
     */
    public function syncUserSpecialties(Request $request, $userId)
    {
        $realId = str_replace('u_', '', $userId);
        $user = User::find($realId);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        // Solo el propio técnico, Root/Admin, o un contratista/gestor de la misma empresa
        $actor = $request->user();
        $mismoTenant = !empty($actor?->tenant_id) && (int) $actor->tenant_id === (int) $user->tenant_id
            && in_array((int) $actor->role_id, [4, 5, 7], true);
        $esAdmin = $actor && in_array((int) $actor->role_id, [0, 1], true);
        if (!$actor || ((int) $actor->id !== (int) $user->id && !$esAdmin && !$mismoTenant)) {
            return response()->json(['success' => false, 'message' => 'No tienes permiso para cambiar las especialidades de este usuario.'], 403);
        }

        $specialties = $request->input('specialties', []);
        $specialtyIds = [];

        foreach ((array) $specialties as $specItem) {
            if (is_numeric($specItem)) {
                $specialtyIds[] = (int) $specItem;
            } elseif (is_string($specItem) && trim($specItem) !== '') {
                $specObj = Specialty::firstOrCreate(
                    ['name' => trim($specItem)],
                    ['icon' => '🔧', 'category' => 'General']
                );
                $specialtyIds[] = $specObj->id;
            }
        }

        $user->specialties()->sync($specialtyIds);

        return response()->json([
            'success' => true,
            'message' => 'Especialidades actualizadas correctamente.',
            'specialties' => $user->specialties
        ], 200);
    }

    /**
     * Obtener las especialidades actuales de un usuario
     */
    public function getUserSpecialties($userId)
    {
        $realId = str_replace('u_', '', $userId);
        $user = User::find($realId);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        return response()->json([
            'success' => true,
            'specialties' => $user->specialties
        ], 200);
    }
}
