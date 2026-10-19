<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Property;
use App\Models\PropertyFolder;

/**
 * Carpetas del catálogo de propiedades.
 * Solo sirven para organizar: no cambian ningún dato de la propiedad.
 * Las ven y administran todos los admins (rol 0 y 1) del mismo tenant.
 */
class PropertyFolderController extends Controller
{
    private function esAdmin($user)
    {
        return $user && in_array((int) $user->role_id, [0, 1]);
    }

    // Root (rol 0) ve todas; los demás solo las de su tenant (sin tenant = Agente Solutions)
    private function carpetasVisibles($user)
    {
        $query = PropertyFolder::query();

        if ((int) $user->role_id !== 0) {
            $tenantId = $user->tenant_id ?? 1;
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
                if ((int) $tenantId === 1) {
                    $q->orWhereNull('tenant_id');
                }
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        try {
            $user = $request->user();
            if (!$this->esAdmin($user)) {
                return response()->json([], 200);
            }

            $carpetas = $this->carpetasVisibles($user)
                ->withCount(['properties' => function ($q) {
                    $q->withoutGlobalScopes();
                }])
                ->orderBy('name')
                ->get(['id', 'tenant_id', 'name', 'color', 'created_by', 'created_at']);

            return response()->json($carpetas, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener carpetas: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$this->esAdmin($user)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'color' => 'nullable|string|max:30',
        ]);

        $carpeta = PropertyFolder::create([
            'tenant_id' => $user->tenant_id ?? 1,
            'created_by' => $user->id,
            'name' => trim($data['name']),
            'color' => $data['color'] ?? '#F26522',
        ]);
        $carpeta->properties_count = 0;

        return response()->json($carpeta, 201);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$this->esAdmin($user)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $carpeta = $this->carpetasVisibles($user)->find($id);
        if (!$carpeta) {
            return response()->json(['error' => 'Carpeta no encontrada'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:80',
            'color' => 'nullable|string|max:30',
        ]);

        if (isset($data['name'])) {
            $carpeta->name = trim($data['name']);
        }
        if (!empty($data['color'])) {
            $carpeta->color = $data['color'];
        }
        $carpeta->save();

        return response()->json($carpeta, 200);
    }

    // Borrar la carpeta NO borra las propiedades: solo quedan fuera de carpeta
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$this->esAdmin($user)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $carpeta = $this->carpetasVisibles($user)->find($id);
        if (!$carpeta) {
            return response()->json(['error' => 'Carpeta no encontrada'], 404);
        }

        DB::transaction(function () use ($carpeta) {
            Property::withoutGlobalScopes()->where('folder_id', $carpeta->id)->update(['folder_id' => null]);
            $carpeta->delete();
        });

        return response()->json(['message' => 'Carpeta eliminada'], 200);
    }

    // Mover una propiedad a una carpeta (folder_id = null la saca de cualquier carpeta)
    public function moverPropiedad(Request $request, $propertyId)
    {
        $user = $request->user();
        if (!$this->esAdmin($user)) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'folder_id' => 'nullable|integer',
        ]);

        // Misma regla de visibilidad que PropertyController@index para admins
        $query = Property::withoutGlobalScopes()->where('id', $propertyId);
        if ((int) $user->role_id !== 0 && $user->tenant_id) {
            $query->where(function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id)
                    ->orWhereHas('client', function ($qc) use ($user) {
                        $qc->where('tenant_id', $user->tenant_id);
                    });
            });
        }
        $propiedad = $query->first();
        if (!$propiedad) {
            return response()->json(['error' => 'Propiedad no encontrada'], 404);
        }

        $folderId = $data['folder_id'] ?? null;
        if ($folderId !== null && !$this->carpetasVisibles($user)->whereKey($folderId)->exists()) {
            return response()->json(['error' => 'Carpeta no encontrada'], 404);
        }

        $propiedad->folder_id = $folderId;
        $propiedad->save();

        return response()->json(['message' => 'Propiedad movida', 'folder_id' => $folderId], 200);
    }
}
