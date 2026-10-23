<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\PropertyFolder;
use App\Models\ReportLogo;
use App\Models\Service;
use App\Models\WorkOrder;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// Logos que se pueden poner en el encabezado del reporte oficial de trabajo
class ReportLogoController extends Controller
{
    // Root (rol 0) ve todos; los demás solo los de su tenant (sin tenant = Agente Solutions)
    private function logosVisibles($user)
    {
        $query = ReportLogo::query();

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

    // Logo de la empresa (carpeta) a la que pertenece la propiedad del trabajo
    private function logoEmpresaDelTrabajo($trabajo)
    {
        if (!$trabajo) {
            return null;
        }

        [$prefix, $realId] = str_contains($trabajo, '-') ? explode('-', $trabajo, 2) : ['servicio', $trabajo];
        $modelo = $prefix === 'work_order'
            ? WorkOrder::withoutGlobalScopes()->find($realId)
            : Service::withoutGlobalScopes()->find($realId);

        $propiedad = $modelo ? Property::withoutGlobalScopes()->find($modelo->property_id) : null;
        $carpeta = $propiedad && $propiedad->folder_id ? PropertyFolder::find($propiedad->folder_id) : null;

        return $carpeta && $carpeta->logo_url
            ? ['url' => $carpeta->logo_url, 'nombre' => $carpeta->name]
            : null;
    }

    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'empresa' => $this->logoEmpresaDelTrabajo($request->query('trabajo')),
            'galeria' => $this->logosVisibles($user)->orderByDesc('id')->get(['id', 'url', 'created_at']),
        ], 200);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if ((int) $user->role_id === 3) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        try {
            $request->validate(
                ['logo' => 'required|file|mimes:jpeg,png,jpg,webp,svg|max:4096'],
                ['logo.required' => 'Elige una imagen para el logo.', 'logo.max' => 'El logo debe pesar menos de 4 MB.', 'logo.mimes' => 'El logo debe ser una imagen (PNG, JPG, WebP o SVG).']
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => collect($e->errors())->flatten()->first()], 422);
        }

        try {
            $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
            $url = $cloudinary->uploadApi()->upload($request->file('logo')->getRealPath(), ['folder' => 'agente_logos_reportes'])['secure_url'];
        } catch (\Throwable $e) {
            Log::error('Error subiendo logo de reporte: ' . $e->getMessage());
            return response()->json(['error' => 'No se pudo subir el logo.'], 500);
        }

        $logo = ReportLogo::create([
            'url' => $url,
            'tenant_id' => $user->tenant_id,
            'uploaded_by' => $user->id,
        ]);

        return response()->json($logo, 201);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array((int) $user->role_id, [0, 1])) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $logo = $this->logosVisibles($user)->find($id);
        if (!$logo) {
            return response()->json(['error' => 'Logo no encontrado'], 404);
        }

        // Los reportes ya guardados conservan su copia de la URL; solo se quita de la galería
        $logo->delete();
        return response()->json(['success' => true], 200);
    }
}
