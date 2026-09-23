<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Specialty;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function registro(Request $request)
    {
        // 1. Actualizamos la validación para requerir first_name y last_name
        $request->validate([
            'first_name' => 'required|string|max:191',
            'last_name' => 'required|string|max:191',
            'email' => 'required|string|email|max:191|unique:users',
            'password' => 'required|string|min:6',
            'role_id' => 'required|integer',
            'phone_number' => 'nullable|string|max:20|unique:users,phone_number',
            'company_code' => 'nullable|string',
            'tenant_id' => 'nullable|integer',
            'company_name' => 'nullable|string|max:191',
            'captcha_token' => $request->boolean('from_admin') ? 'nullable|string' : 'required|string'
        ], [
            'email.unique' => 'Este correo electrónico ya está registrado en otra cuenta.',
            'phone_number.unique' => 'Este número de teléfono ya está registrado en otra cuenta.',
            'captcha_token.required' => 'Por favor verifica que no eres un robot.'
        ]);

        $currentUser = auth('sanctum')->user();
        $isRootOrAdmin = ($currentUser && in_array($currentUser->role_id, [0, 1])) || $request->boolean('from_admin');

        // Validar con Google reCAPTCHA únicamente si no es un registro administrativo
        if (!$isRootOrAdmin) {
            $recaptchaResponse = \Illuminate\Support\Facades\Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => env('RECAPTCHA_SECRET_KEY', '6LfHnl4tAAAAAD5Ig1yZydacMJk_VOg8ObxIs8K3'),
                'response' => $request->captcha_token,
            ]);

            $recaptchaData = $recaptchaResponse->json();
            if (!$recaptchaData['success']) {
                return response()->json([
                    'errors' => ['captcha' => ['La validación del reCAPTCHA ha fallado. Por favor recarga la página e intenta de nuevo.']]
                ], 422);
            }
        }

        // Buscar tenant por código o ID
        $tenantId = $request->tenant_id ?? null;
        if (!empty($request->company_code)) {
            $t = Tenant::where('code', $request->company_code)
                       ->orWhere('phone', $request->company_code)
                       ->first();
            if ($t) {
                $tenantId = $t->id;
            }
        }

        $isTechnician = ($request->role_id == 2 || $request->role_id == 8);
        if ($request->role_id == 2 && empty($tenantId)) {
            $tenantId = 1; // Si el técnico de Agente no ingresó código de empresa, se va directo a Agente Solutions
        }

        $currentUser = auth('sanctum')->user();
        $isRootOrAdmin = ($currentUser && in_array($currentUser->role_id, [0, 1])) || $request->boolean('from_admin');

        $isAutonomoEmpresarial = ($request->role_id == 4 || $request->role_id == 6);
        $isAutonomoPersonal    = ($request->role_id == 5);
        $isAutonomo = $isAutonomoEmpresarial || $isAutonomoPersonal;

        // Precios de suscripción por tipo (Contratista usa el mismo que Empresarial)
        $subscriptionPrices = [4 => 999.00, 5 => 499.00, 6 => 999.00];

        $approvalStatus = ($isTechnician && !$isRootOrAdmin) ? 'pending' : 'approved';
        if ($request->role_id == 7) {
            $approvalStatus = $tenantId ? 'pending' : 'pending_link';
        }

        // Autónomos creados por Root/Admin quedan activos inmediatamente; públicos quedan inactivos hasta pagar
        $isActive = ($isTechnician && !$isRootOrAdmin) ? 0 : ($isAutonomo && !$isRootOrAdmin ? 0 : 1);
        if ($request->role_id == 7) {
            $isActive = 0;
        }

        $roleToAssign = $request->role_id;

        // A PRUEBA DE BALAS: Asegurar que el rol exista en la tabla roles
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8] as $rId) {
            \DB::table('roles')->insertOrIgnore(['id' => $rId, 'created_at' => now(), 'updated_at' => now()]);
        }
        \DB::table('roles')->insertOrIgnore(['id' => $roleToAssign, 'created_at' => now(), 'updated_at' => now()]);

        $user = User::create([
            'first_name'      => $request->first_name,
            'last_name'       => $request->last_name,
            'email'           => $request->email,
            'password'        => Hash::make($request->password),
            'role_id'         => $roleToAssign,
            'tenant_id'       => $tenantId,
            'approval_status' => $approvalStatus,
            'phone_number'    => $request->phone_number ?? null,
            'is_active'       => $isActive
        ]);

        if ($isTechnician && $request->has('specialties')) {
            $specialtyIds = [];
            foreach ((array) $request->specialties as $specItem) {
                if (is_numeric($specItem)) {
                    $specialtyIds[] = (int) $specItem;
                } elseif (is_string($specItem) && trim($specItem) !== '') {
                    $specObj = Specialty::firstOrCreate(
                        ['name' => trim($specItem)],
                        ['icon' => '⚡', 'category' => 'General']
                    );
                    $specialtyIds[] = $specObj->id;
                }
            }
            if (!empty($specialtyIds)) {
                $user->specialties()->sync($specialtyIds);
            }
        }

        if ($isAutonomo) {
            $membershipType   = $isAutonomoPersonal ? 'autonomo_personal' : ($request->role_id == 6 ? 'contratista' : 'autonomo_empresarial');
            $subscriptionAmt  = $isAutonomoPersonal ? 299.00 : 935.00;
            $customCode       = !empty($request->company_code) ? trim($request->company_code) : ('AUT' . ($isAutonomoPersonal ? '_P' : '_E') . '_' . time() . '_' . $user->id);
            $companyName      = !empty($request->company_name) ? trim($request->company_name) : trim($user->first_name . ' ' . $user->last_name);

            // Siempre se dan 6 MESES GRATIS iniciales
            $subStatus  = 'active';
            $subStart   = now();
            $subExpires = now()->addMonths(6);

            $maxProperties = $isAutonomoPersonal ? 3 : 30;
            $maxClients    = $isAutonomoPersonal ? 0 : 30;

            $tenant = Tenant::create([
                'name'                    => $companyName,
                'code'                    => $customCode,
                'owner_user_id'           => $user->id,
                'phone'                   => $user->phone_number,
                'email'                   => $user->email,
                'status'                  => 'active',
                'membership_type'         => $membershipType,
                'max_properties'          => $maxProperties,
                'max_clients'             => $maxClients,
                'billing_cycle'           => 'trial',
                'subscription_status'     => $subStatus,
                'subscription_start'      => $subStart,
                'subscription_expires_at' => $subExpires,
                'subscription_amount'     => $subscriptionAmt,
            ]);

            $user->tenant_id       = $tenant->id;
            $user->is_active       = 1;
            $user->approval_status = 'approved';
            $user->save();
        }

        if ($roleToAssign == 0) {
            $user->role_id = 0;
            $user->is_active = 1;
            $user->approval_status = 'approved';
            $user->save();
        }

        if ($isTechnician) {
            $tenantObj = $tenantId ? Tenant::find($tenantId) : null;
            $isAgenteSolutionsTech = ($tenantId == 1 || ($tenantObj && ($tenantObj->code === 'AUT_01' || stripos($tenantObj->name, 'Agente Solutions') !== false)));

            if ($isAgenteSolutionsTech) {
                $user->subscription_status = 'exempt';
                $user->subscription_amount = 0.00;
            } else {
                // Técnico externo: 1 AÑO GRATIS, luego $99/mes
                $user->subscription_status     = 'active';
                $user->subscription_start      = now();
                $user->subscription_expires_at = now()->addYear();
                $user->subscription_amount     = 99.00;
            }
            $user->save();
            $user->load(['tenant', 'specialties']);

            // $user->sendEmailVerificationNotification();

            return response()->json([
                'success' => true,
                'status' => 'pending_approval',
                'message' => 'Tu perfil ha sido registrado con éxito. Te hemos otorgado 1 AÑO GRATIS de suscripción. Está en espera de revisión por el Administrador de tu empresa.',
                'user' => $user
            ], 201);
        }

        $token = $user->createToken('AgenteToken')->plainTextToken;
        $user->load(['tenant', 'specialties']);

        // $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado exitosamente con prueba gratuita de 6 meses activa.',
            'user' => $user,
            'token' => $token
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::withoutGlobalScopes()
                    ->where('email', $request->email)
                    ->orWhere('phone_number', $request->email)
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Credenciales incorrectas. Verifica tus datos y contraseña.'
            ], 401);
        }

        /*
        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'error' => 'Tu correo electrónico no ha sido verificado. Por favor, revisa tu bandeja de entrada o solicita un nuevo enlace de verificación.',
                'requires_verification' => true
            ], 403);
        }
        */

        if ($user->approval_status === 'pending') {
            return response()->json([
                'status' => 'pending',
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => 'Tu perfil está en espera de ser revisado y autorizado por el Administrador de tu empresa.'
            ], 403);
        }

        if ($user->approval_status === 'pending_link') {
            return response()->json([
                'status' => 'pending_link',
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => 'No tienes un Autónomo o Empresa vinculada. Por favor ingresa el código.'
            ], 403);
        }

        if ($user->approval_status === 'deleted_by_user') {
            return response()->json([
                'error' => 'Tu cuenta fue eliminada y desactivada a solicitud del titular. Si deseas restaurar tu acceso o solicitar el respaldo y recuperación de tus datos y propiedades, por favor comunícate con Soporte Técnico.'
            ], 403);
        }

        if ($user->is_active == 0) {
            return response()->json([
                'error' => 'No puedes acceder a tu cuenta, por favor contactate con el servicio de soporte.'
            ], 403);
        }

        // Verificar suscripción para Autónomos (role 4 o 5)
        if (in_array($user->role_id, [4, 5])) {
            $user->load('tenant');
            $tenant = $user->tenant;
            if ($tenant) {
                // Autodetectar vencimiento del trial o suscripción en tiempo real
                if ($tenant->subscription_expires_at && now()->isAfter(\Carbon\Carbon::parse($tenant->subscription_expires_at)) && $tenant->subscription_status === 'active') {
                    $tenant->subscription_status = 'expired';
                    $tenant->save();
                }

                if ($tenant->subscription_status === 'pending_payment') {
                    return response()->json([
                        'blocked'   => true,
                        'reason'    => 'pending_payment',
                        'tenant_id' => $tenant->id,
                        'amount'    => $tenant->subscription_amount,
                        'error'     => 'Para acceder debes completar el pago de tu suscripción.'
                    ], 403);
                }
                if ($tenant->subscription_status === 'expired') {
                    return response()->json([
                        'blocked'   => true,
                        'reason'    => 'expired',
                        'tenant_id' => $tenant->id,
                        'amount'    => $tenant->subscription_amount,
                        'error'     => 'Tu suscripción o prueba gratuita ha vencido. Renueva para recuperar el acceso.'
                    ], 403);
                }
            }
        }

        // Verificar suscripción para Técnicos Externos (role 2)
        if ($user->role_id == 2 && $user->subscription_status !== 'exempt') {
            if ($user->subscription_expires_at && now()->isAfter(\Carbon\Carbon::parse($user->subscription_expires_at)) && $user->subscription_status === 'active') {
                $user->subscription_status = 'expired';
                $user->save();
            }

            if ($user->subscription_status === 'expired' || $user->subscription_status === 'pending_payment') {
                return response()->json([
                    'blocked'   => true,
                    'reason'    => 'expired_technician',
                    'user_id'   => $user->id,
                    'amount'    => $user->subscription_amount ?? 99.00,
                    'error'     => 'Tu año de prueba o suscripción de técnico ha vencido. Paga tu mensualidad de $99.00 para continuar.'
                ], 403);
            }
        }

        $token = $user->createToken('AgenteToken')->plainTextToken;
        $user->load(['tenant', 'specialties']);

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión exitoso',
            'user' => $user,
            'token' => $token
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada correctamente'], 200);
    }

    public function verifyEmail(Request $request, $id, $hash)
    {
        $user = User::withoutGlobalScopes()->findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Enlace de verificación inválido o expirado.'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'El correo ya ha sido verificado anteriormente.'], 200);
        }

        if ($user->markEmailAsVerified()) {
            event(new \Illuminate\Auth\Events\Verified($user));
        }

        return response()->json(['message' => 'Correo verificado exitosamente.'], 200);
    }

    public function resendVerificationEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        
        $user = User::withoutGlobalScopes()->where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'El correo ya ha sido verificado.'], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Enlace de verificación reenviado.'], 200);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        try {
            $status = \Illuminate\Support\Facades\Password::broker()->sendResetLink(
                $request->only('email')
            );

            return $status === \Illuminate\Support\Facades\Password::RESET_LINK_SENT
                ? response()->json(['message' => 'Te hemos enviado un enlace de recuperación a tu correo.'])
                : response()->json(['message' => 'No pudimos procesar tu solicitud. Verifica tu correo.'], 400);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Modo de pruebas activo: El enlace no se envió por restricciones de correo, pero la petición fue exitosa.'
            ]);
        }
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        $status = \Illuminate\Support\Facades\Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = Hash::make($password);
                $user->save();
            }
        );

        return $status === \Illuminate\Support\Facades\Password::PASSWORD_RESET
                    ? response()->json(['success' => true, 'message' => 'Contraseña restablecida correctamente.'])
                    : response()->json(['success' => false, 'message' => 'El token es inválido o ha expirado.'], 400);
    }

    public function linkCompany(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'company_code' => 'required|string',
        ]);

        $user = User::findOrFail($request->user_id);
        
        $tenant = Tenant::where('code', $request->company_code)
                        ->orWhere('phone', $request->company_code)
                        ->first();
                        
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'El código de empresa ingresado no existe.'
            ], 404);
        }

        $user->tenant_id = $tenant->id;
        $user->approval_status = 'pending';
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Vinculación exitosa. Tu cuenta ahora está en espera de aprobación por el administrador de ' . $tenant->name,
            'status' => 'pending'
        ]);
    }
}