<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\PropertyComponent;
// Importamos Cloudinary
use Cloudinary\Cloudinary;

class PropertyComponentController extends Controller
{
    public function getByArea($areaId)
    {
        try {
            // Protección de Sanctum
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado'], 401);

            // Obtener el área principal
            $mainArea = DB::table('property_areas')->where('id', $areaId)->first();
            if (!$mainArea)
                return response()->json(['error' => 'Área no encontrada'], 404);

            // Obtener subáreas
            $subAreas = DB::table('property_areas')->where('parent_id', $areaId)->get();
            $areaIds = $subAreas->pluck('id')->toArray();
            $areaIds[] = (int) $areaId;

            // Obtener todos los componentes de estas áreas
            $components = DB::table('property_components')
                ->whereIn('property_area_id', $areaIds)
                ->get();

            foreach ($components as $component) {
                $component->galleries = DB::table('component_galleries')
                    ->where('property_component_id', $component->id)
                    ->get();
            }

            return response()->json($components, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            // Protección de Sanctum
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado'], 401);

            $request->validate([
                'property_area_id' => 'required',
                'category' => 'required|string',
                'sub_category' => 'required|string',
                'quantity' => 'required|numeric',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
                'image_secondary' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240'
            ]);

            // Lógica para Cloudinary
            $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));

            // IMAGEN PRINCIPAL
            $imagePath = null;
            if ($request->hasFile('image')) {
                $respuestaNube = $cloudinary->uploadApi()->upload($request->file('image')->getRealPath(), [
                    'folder' => 'agente_componentes'
                ]);
                $imagePath = $respuestaNube['secure_url'];
            }

            // IMAGEN SECUNDARIA
            $imagePathSecondary = null;
            if ($request->hasFile('image_secondary')) {
                $resSec = $cloudinary->uploadApi()->upload($request->file('image_secondary')->getRealPath(), [
                    'folder' => 'agente_componentes'
                ]);
                $imagePathSecondary = $resSec['secure_url'];
            }

            // Insertar en BD
            $id = DB::table('property_components')->insertGetId([
                'property_area_id' => $request->property_area_id,
                'category' => $request->category,
                'sub_category' => $request->sub_category,
                'brand' => $request->brand ?? '',
                'model_or_color' => $request->model_or_color ?? '',
                'serial_number' => $request->serial_number ?? '',
                'quantity' => $request->quantity,
                'unit' => $request->unit ?? 'PZA',
                'status' => $request->status ?? 'Bueno',
                'observations' => $request->observations ?? '',
                'image_path' => $imagePath,
                'image_path_secondary' => $imagePathSecondary,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Lógica para GALERÍA
            if ($request->hasFile('gallery')) {
                foreach ($request->file('gallery') as $file) {
                    $resGaleria = $cloudinary->uploadApi()->upload($file->getRealPath(), [
                        'folder' => 'agente_componentes_galeria'
                    ]);

                    DB::table('component_galleries')->insert([
                        'property_component_id' => $id,
                        'image_path' => $resGaleria['secure_url'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return response()->json(['success' => true, 'id' => $id], 201);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * ==========================================================
     * TUS FUNCIONES ORIGINALES (BLINDADAS CON SANCTUM)
     * ==========================================================
     */

    public function getCatalogSummary()
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado'], 401);

            $summary = DB::table('property_components')
                ->select(
                    'brand',
                    'model_or_color',
                    'category',
                    DB::raw('SUM(quantity) as total_installed')
                )
                ->whereNotNull('brand')
                ->whereNotNull('model_or_color')
                ->groupBy('brand', 'model_or_color', 'category')
                ->orderByDesc('total_installed')
                ->get()
                ->map(function ($item, $index) {
                    return [
                        'id' => '#PROD-' . str_pad($index + 1, 2, '0', STR_PAD_LEFT),
                        'product_model' => $item->model_or_color,
                        'brand' => strtoupper($item->brand),
                        'category' => $item->category,
                        'total_installed' => (int) $item->total_installed,
                        'average_warranty' => '12 Months'
                    ];
                });

            return response()->json($summary, 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error retrieving catalog summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getCatalogDetails(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado'], 401);

            $brand = $request->query('brand');
            $model = $request->query('model');

            // 1. Buscamos todas las instalaciones de este modelo
            $installations = DB::table('property_components as pc')
                ->join('property_areas as pa', 'pc.property_area_id', '=', 'pa.id')
                ->join('properties as p', 'pa.property_id', '=', 'p.id')
                ->select('p.id as property_id', 'p.custom_curp', 'pa.name as area_name', 'pc.id as component_id', 'pc.created_at', 'pc.quantity')
                ->where('pc.brand', $brand)
                ->where('pc.model_or_color', $model)
                ->get();

            $ubicaciones = $installations->map(function ($inst) {
                // 2. BUSQUEDA EXACTA
                $reportes = DB::table('services as s')
                    ->join('service_component as sc', 's.id', '=', 'sc.service_id')
                    ->leftJoin('users as u', 's.assigned_to', '=', 'u.id')
                    ->select('s.scheduled_start', 's.service_type', 'u.first_name', 's.status')
                    ->where('sc.property_component_id', $inst->component_id)
                    ->orderByDesc('s.scheduled_start')
                    ->get()
                    ->map(function ($service) {
                        return [
                            'fecha' => date('d/m/Y', strtotime($service->scheduled_start)),
                            'tecnico' => $service->first_name ?? 'Técnico Agente',
                            'tipo' => $service->service_type,
                            'vencimiento' => 'N/A'
                        ];
                    });

                return [
                    'nombre' => $inst->custom_curp . ' - ' . $inst->area_name,
                    'totalInstalados' => (int) $inst->quantity,
                    'reportes' => $reportes
                ];
            });

            return response()->json([
                'proveedor' => 'Proveedor Master Agente',
                'ubicaciones' => $ubicaciones
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getComponentsByProperty($propertyId)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado'], 401);

            $components = DB::table('property_components as pc')
                ->join('property_areas as pa', 'pc.property_area_id', '=', 'pa.id')
                ->select(
                    'pc.id',
                    'pc.brand',
                    'pc.model_or_color as model',
                    'pa.name as area_name',
                    'pc.category'
                )
                ->where('pa.property_id', $propertyId)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'display_name' => "{$item->model} ({$item->brand}) - Ubicación: {$item->area_name}"
                    ];
                });

            return response()->json($components, 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los componentes de la propiedad',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado'], 401);

            DB::table('property_components')->where('id', $id)->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user)
                return response()->json(['error' => 'No autorizado'], 401);

            $component = DB::table('property_components')->where('id', $id)->first();
            if (!$component) {
                return response()->json(['error' => 'No encontrado'], 404);
            }

            // ELIMINAR FOTOS DE LA GALERÍA SELECCIONADAS
            if ($request->has('deleted_gallery_ids')) {
                $deletedIds = $request->deleted_gallery_ids;
                if (is_string($deletedIds)) {
                    $decoded = json_decode($deletedIds, true);
                    $deletedIds = is_array($decoded) ? $decoded : explode(',', $deletedIds);
                }
                if (is_array($deletedIds) && count($deletedIds) > 0) {
                    DB::table('component_galleries')
                        ->where('property_component_id', $id)
                        ->whereIn('id', $deletedIds)
                        ->delete();
                }
            }

            $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));

            // ACTUALIZAR IMAGEN PRINCIPAL
            $imagePath = $component->image_path;
            if ($request->hasFile('image')) {
                $respuestaNube = $cloudinary->uploadApi()->upload($request->file('image')->getRealPath(), [
                    'folder' => 'agente_componentes'
                ]);
                $imagePath = $respuestaNube['secure_url'];
            }

            // ACTUALIZAR IMAGEN SECUNDARIA
            $imagePathSecondary = $component->image_path_secondary ?? null;
            if ($request->hasFile('image_secondary')) {
                $resSec = $cloudinary->uploadApi()->upload($request->file('image_secondary')->getRealPath(), [
                    'folder' => 'agente_componentes'
                ]);
                $imagePathSecondary = $resSec['secure_url'];
            }

            DB::table('property_components')->where('id', $id)->update([
                'sub_category' => $request->sub_category,
                'brand' => $request->brand ?? '',
                'model_or_color' => $request->model_or_color ?? '',
                'serial_number' => $request->serial_number ?? '',
                'quantity' => $request->quantity,
                'status' => $request->status ?? 'Bueno',
                'observations' => $request->observations ?? '',
                'image_path' => $imagePath,
                'image_path_secondary' => $imagePathSecondary,
                'updated_at' => now(),
            ]);

            // GUARDAR NUEVAS FOTOS EN LA GALERÍA AL EDITAR
            if ($request->hasFile('gallery')) {
                foreach ($request->file('gallery') as $file) {
                    $resGaleria = $cloudinary->uploadApi()->upload($file->getRealPath(), [
                        'folder' => 'agente_componentes_galeria'
                    ]);

                    DB::table('component_galleries')->insert([
                        'property_component_id' => $id,
                        'image_path' => $resGaleria['secure_url'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return response()->json(['success' => true], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}