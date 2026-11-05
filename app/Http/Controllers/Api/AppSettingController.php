<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AppSetting;

class AppSettingController extends Controller
{
    public function updateLoginBackground(Request $request)
    {
        $user = auth('sanctum')->user();
        if ($user && $user->role_id !== 0) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado: Solo el ROOT puede modificar el fondo de login.'], 403);
        }

        $request->validate([
            'background_image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if ($request->hasFile('background_image')) {
            $cloudinary = new \Cloudinary\Cloudinary('cloudinary://942191234587844:VmNYB6w4vj3DdLqI9SZSKVofOi0@dcj5rcpi8');
            $respuestaNube = $cloudinary->uploadApi()->upload($request->file('background_image')->getRealPath(), [
                'folder' => 'login_backgrounds'
            ]);
            $imageUrl = $respuestaNube['secure_url'];

            $setting = AppSetting::updateOrCreate(
                ['setting_key' => 'login_background_image'], 
                ['setting_value' => $imageUrl]
            );

            return response()->json(['success' => true, 'data' => $setting], 200);
        }
        return response()->json(['success' => false, 'message' => 'No image found'], 400);
    }

    public function updateLoginColor(Request $request)
    {
        $user = auth('sanctum')->user();
        if ($user && $user->role_id !== 0) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado: Solo el ROOT puede modificar el color de login.'], 403);
        }

        $request->validate([
            'color_hex' => ['required', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
        ]);

        $setting = AppSetting::updateOrCreate(
            ['setting_key' => 'login_background_color'],
            ['setting_value' => $request->color_hex]
        );

        return response()->json([
            'success' => true,
            'message' => 'Color actualizado correctamente.',
            'data' => $setting
        ], 200);
    }
    public function deleteLoginBackground()
    {
        $user = auth('sanctum')->user();
        if ($user && $user->role_id !== 0) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        \App\Models\AppSetting::where('setting_key', 'login_background_image')->delete();

        return response()->json([
            'success' => true,
            'message' => 'Imagen eliminada correctamente.'
        ], 200);
    }

    public function getLoginSettings(Request $request)
    {
        $user = auth('sanctum')->user();
        $tenantId = $user ? $user->tenant_id : null;

        $imageSetting = AppSetting::where('setting_key', 'login_background_image')->first();
        $colorSetting = AppSetting::where('setting_key', 'login_background_color')->first();
        
        // Si hay un tenantId, buscamos primero el logo de ese tenant
        $logoSetting = null;
        if ($tenantId) {
            $logoSetting = AppSetting::where('setting_key', 'app_logo_' . $tenantId)->first();
        }
        // Si no encontró logo del tenant (o si no tiene tenant), usamos el logo global de Root
        if (!$logoSetting) {
            $logoSetting = AppSetting::where('setting_key', 'app_logo')->first();
        }

        return response()->json([
            'success' => true,
            'settings' => [
                'imageUrl' => $imageSetting ? $imageSetting->setting_value : null,
                'colorHex' => $colorSetting ? $colorSetting->setting_value : '#000000',
                'appLogo' => $logoSetting ? $logoSetting->setting_value : null
            ]
        ], 200);
    }

    public function updateAppLogo(Request $request)
    {
        $request->validate([
            'app_logo' => 'required|file|mimes:jpeg,png,jpg,webp,svg|max:5120',
        ]);

        if ($request->hasFile('app_logo')) {
            $user = auth('sanctum')->user();
            $tenantId = ($user && $user->role_id != 0) ? $user->tenant_id : null;
            $settingKey = $tenantId ? ('app_logo_' . $tenantId) : 'app_logo';

            $cloudinary = new \Cloudinary\Cloudinary('cloudinary://942191234587844:VmNYB6w4vj3DdLqI9SZSKVofOi0@dcj5rcpi8');
            $respuestaNube = $cloudinary->uploadApi()->upload($request->file('app_logo')->getRealPath(), [
                'folder' => 'app_logos'
            ]);
            $imageUrl = $respuestaNube['secure_url'];

            $setting = AppSetting::updateOrCreate(
                ['setting_key' => $settingKey], 
                ['setting_value' => $imageUrl]
            );

            return response()->json(['success' => true, 'data' => $setting], 200);
        }
        return response()->json(['success' => false, 'message' => 'No image found'], 400);
    }

    public function deleteAppLogo(Request $request)
    {
        $user = auth('sanctum')->user();
        $tenantId = ($user && $user->role_id != 0) ? $user->tenant_id : null;
        $settingKey = $tenantId ? ('app_logo_' . $tenantId) : 'app_logo';

        \App\Models\AppSetting::where('setting_key', $settingKey)->delete();
        return response()->json(['success' => true, 'message' => 'Logo eliminado correctamente.'], 200);
    }

    public function updateSidebarLinks(Request $request)
    {
        $request->validate([
            'links' => 'present|array',
        ]);

        $user = auth('sanctum')->user();
        $tenantId = ($user && $user->role_id != 0) ? $user->tenant_id : null;
        $settingKey = $tenantId ? ('sidebar_client_links_' . $tenantId) : 'sidebar_client_links';

        $setting = AppSetting::updateOrCreate(
            ['setting_key' => $settingKey],
            ['setting_value' => json_encode($request->links)]
        );

        return response()->json(['success' => true, 'data' => $setting], 200);
    }

    public function getSidebarLinks(Request $request)
    {
        $user = auth('sanctum')->user();
        $tenantId = $user ? $user->tenant_id : null;

        $setting = null;
        if ($tenantId) {
            $setting = AppSetting::where('setting_key', 'sidebar_client_links_' . $tenantId)->first();
        }
        if (!$setting) {
            $setting = AppSetting::where('setting_key', 'sidebar_client_links')->first();
        }

        $links = $setting ? json_decode($setting->setting_value, true) : [];
        return response()->json(['success' => true, 'links' => $links], 200);
    }

    // ── Datos bancarios para pagos por transferencia (sustituyen a Mercado Pago) ──
    private const DATOS_BANCARIOS_DEFAULT = [
        'banco' => 'Banorte',
        'titular' => '',
        'cuenta' => '1195878544',
        'clabe' => '072910011958785445',
    ];

    public function getBankInfo()
    {
        $setting = AppSetting::where('setting_key', 'bank_info')->first();
        $datos = $setting ? json_decode($setting->setting_value, true) : null;
        return response()->json([
            'success' => true,
            'data' => array_merge(self::DATOS_BANCARIOS_DEFAULT, is_array($datos) ? $datos : []),
        ], 200);
    }

    public function updateBankInfo(Request $request)
    {
        $validated = $request->validate([
            'banco' => 'required|string|max:80',
            'titular' => 'nullable|string|max:120',
            'cuenta' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 ]*$/'],
            'clabe' => ['required', 'string', 'regex:/^[0-9]{18}$/'],
        ]);
        $validated['cuenta'] = preg_replace('/\s+/', '', (string) ($validated['cuenta'] ?? ''));
        $validated['titular'] = $validated['titular'] ?? '';

        AppSetting::updateOrCreate(
            ['setting_key' => 'bank_info'],
            ['setting_value' => json_encode($validated)]
        );

        return response()->json(['success' => true, 'data' => $validated], 200);
    }
}
