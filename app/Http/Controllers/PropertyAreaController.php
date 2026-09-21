<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PropertyArea;
use App\Models\PropertyComponent;
use Illuminate\Support\Facades\Log;
// Importamos la Opción Nuclear de Cloudinary
use Cloudinary\Cloudinary;

class PropertyAreaController extends Controller
{
    /**
     * Obtener todas las áreas de una propiedad específica.
     * GET /api/properties/{id}/areas
     */
    public function getByProperty($propertyId)
    {
        try {
            // Protección de Sanctum
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado'], 401);

            $areas = PropertyArea::with('subAreas')
                ->where('property_id', $propertyId)
                ->whereNull('parent_id')
                ->orderBy('created_at', 'desc')
                ->get();
            return response()->json($areas, 200);
        } catch (\Exception $e) {
            Log::error("Error en getByProperty: " . $e->getMessage());
            return response()->json([
                'error' => 'Error al obtener las áreas',
                'description' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Guardar una nueva zona.
     * POST /api/property-areas
     */
    public function store(Request $request)
    {
        // Protección de Sanctum
        $user = auth('sanctum')->user();
        if (!$user)
            return response()->json(['error' => 'No autorizado'], 401);

        $request->validate([
            'property_id' => 'required|exists:properties,id',
            'name' => 'required|string|max:191',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240', // 10MB
        ]);

        $area = new PropertyArea();
        $area->property_id = $request->property_id;
        $area->name = $request->name;
        $area->parent_id = $request->parent_id ?? null;
        $area->description = $request->description ?? '';

        // --- SUBIDA A CLOUDINARY ---
        if ($request->hasFile('image')) {
            try {
                $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
                $respuestaNube = $cloudinary->uploadApi()->upload($request->file('image')->getRealPath(), [
                    'folder' => 'agente_zonas' // Carpeta específica para las áreas
                ]);
                // Guardamos la URL absoluta
                $area->image_path = $respuestaNube['secure_url'];
            } catch (\Exception $e) {
                Log::error("Error subiendo zona a Cloudinary: " . $e->getMessage());
                // Si falla la nube, no guardamos la zona y avisamos
                return response()->json(['error' => 'Fallo al subir la imagen a la nube'], 500);
            }
        }

        $area->save();

        return response()->json([
            'success' => true,
            'area' => $area
        ], 201);
    }

    public function getSubAreas($parentId)
    {
        // Protección de Sanctum
        $user = auth('sanctum')->user();
        if (!$user)
            return response()->json(['error' => 'No autorizado'], 401);

        $subareas = PropertyArea::where('parent_id', $parentId)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($subareas, 200);
    }

    /**
     * Actualizar una zona (imagen o descripción).
     * PUT /api/property-areas/{id}
     */
    public function update(Request $request, $id)
    {
        // Protección de Sanctum
        $user = auth('sanctum')->user();
        if (!$user)
            return response()->json(['error' => 'No autorizado'], 401);

        $area = PropertyArea::findOrFail($id);

        $request->validate([
            'name' => 'nullable|string|max:191',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
            'description' => 'nullable|string'
        ]);

        if ($request->has('name')) {
            $area->name = $request->name;
        }

        if ($request->has('description')) {
            $area->description = $request->description;
        }

        // --- SUBIDA A CLOUDINARY (Al editar) ---
        if ($request->hasFile('image')) {
            try {
                $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
                $respuestaNube = $cloudinary->uploadApi()->upload($request->file('image')->getRealPath(), [
                    'folder' => 'agente_zonas'
                ]);
                $area->image_path = $respuestaNube['secure_url'];
            } catch (\Exception $e) {
                Log::error("Error subiendo zona a Cloudinary en Update: " . $e->getMessage());
                return response()->json(['error' => 'Fallo al subir la imagen a la nube'], 500);
            }
        }

        $area->save();

        return response()->json([
            'success' => true,
            'area' => $area
        ], 200);
    }

    /**
     * Eliminar una zona.
     * DELETE /api/property-areas/{id}
     */
    public function destroy($id)
    {
        try {
            // Protección de Sanctum
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado'], 401);

            $area = PropertyArea::with(['subAreas', 'components'])->findOrFail($id);

            // 1. Borrar equipos de esta zona
            $area->components()->delete();

            // 2. Borrar sub-zonas y sus equipos
            foreach ($area->subAreas as $sub) {
                $sub->components()->delete();
                $sub->delete();
            }

            // 3. Borrar la zona principal
            $area->delete();

            return response()->json(['success' => true, 'message' => 'Área y sus contenidos eliminados correctamente'], 200);
        } catch (\Exception $e) {
            Log::error("Error al eliminar área: " . $e->getMessage());
            return response()->json([
                'error' => 'No se pudo eliminar el área',
                'description' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar solo la foto de una zona.
     * POST /api/property-areas/{id}/update-photo
     */
    public function updatePhoto(Request $request, $id)
    {
        $user = auth('sanctum')->user();
        if (!$user)
            return response()->json(['error' => 'No autorizado'], 401);

        $area = PropertyArea::findOrFail($id);

        if ($request->hasFile('image')) {
            try {
                $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
                $respuestaNube = $cloudinary->uploadApi()->upload($request->file('image')->getRealPath(), [
                    'folder' => 'agente_zonas'
                ]);
                $area->image_path = $respuestaNube['secure_url'];
                $area->save();

                return response()->json([
                    'success' => true,
                    'image_path' => $area->image_path
                ], 200);
            } catch (\Exception $e) {
                Log::error("Error subiendo foto de zona: " . $e->getMessage());
                return response()->json(['error' => 'Fallo al subir la imagen'], 500);
            }
        }

        return response()->json(['error' => 'No se recibió ninguna imagen'], 400);
    }
}