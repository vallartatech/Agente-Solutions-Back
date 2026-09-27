<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Property;
use App\Models\Quote;
use App\Models\Service;
use App\Models\WorkOrder;
use App\Models\Client;
use App\Models\Technician;
use Illuminate\Support\Facades\DB;

class TenantController extends Controller
{
    /**
     * Listar todas las empresas activas para el portal público de Registro/Login.
     */
    public function listTenants()
    {
        $tenants = Tenant::where('status', 'active')
            ->select('id', 'name', 'code', 'logo_url', 'phone', 'email', 'owner_user_id', 'membership_type', 'max_properties', 'extra_properties_count', 'max_clients', 'subscription_status', 'subscription_expires_at', 'subscription_amount', 'billing_cycle')
            ->get();

        return response()->json([
            'success' => true,
            'tenants' => $tenants
        ]);
    }

    public function myMembershipStatus()
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'No autorizado'], 401);
        }

        $myTenant = Tenant::where('owner_user_id', $user->id)
            ->orWhere('id', $user->tenant_id)
            ->orderBy('id', 'desc')
            ->first();
        
        return response()->json([
            'success' => true,
            'has_pending' => ($myTenant && $myTenant->status === 'pending_approval') ? true : false,
            'tenant' => $myTenant,
            'user' => $user
        ]);
    }

    /**
     * Obtener el estado actual y días restantes de la suscripción de 6 meses.
     */
    public function getSubscriptionStatus()
    {
        $user = auth('sanctum')->user();
        if (!$user) {
            return response()->json(['error' => 'No autorizado'], 401);
        }

        // Si es Técnico Externo (rol 2)
        if ($user->role_id == 2) {
            $daysRemaining = 0;
            if ($user->subscription_expires_at) {
                $daysRemaining = (int) now()->diffInDays(\Carbon\Carbon::parse($user->subscription_expires_at), false);
            }
            return response()->json([
                'success'                 => true,
                'is_technician'           => true,
                'subscription_status'     => $user->subscription_status ?? 'active',
                'subscription_start'      => $user->subscription_start,
                'subscription_expires_at' => $user->subscription_expires_at,
                'subscription_amount'     => $user->subscription_amount ?? 99.00,
                'days_remaining'          => $daysRemaining,
                'user'                    => $user
            ]);
        }

        $tenant = Tenant::where('owner_user_id', $user->id)
            ->orWhere('id', $user->tenant_id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$tenant) {
            return response()->json(['success' => false, 'message' => 'No se encontró empresa asignada.'], 404);
        }

        $daysRemaining = 0;
        if ($tenant->subscription_expires_at) {
            $daysRemaining = (int) now()->diffInDays(\Carbon\Carbon::parse($tenant->subscription_expires_at), false);
        }

        $propertiesCount = $tenant->properties()->count();
        $clientsCount    = \App\Models\Client::where('tenant_id', $tenant->id)->count();

        return response()->json([
            'success'                 => true,
            'is_technician'           => false,
            'subscription_status'     => $tenant->subscription_status ?? 'active',
            'subscription_start'      => $tenant->subscription_start,
            'subscription_expires_at' => $tenant->subscription_expires_at,
            'subscription_amount'     => $tenant->subscription_amount,
            'days_remaining'          => $daysRemaining,
            'properties_count'        => $propertiesCount,
            'max_properties'          => $tenant->max_properties ?? 3,
            'extra_properties_count'  => $tenant->extra_properties_count ?? 0,
            'clients_count'           => $clientsCount,
            'max_clients'             => $tenant->max_clients ?? 30,
            'billing_cycle'           => $tenant->billing_cycle ?? 'trial',
            'tenant'                  => $tenant
        ]);
    }

    /**
     * Solicitar membresía para convertirse en Autónomo (Empresa).
     */
    public function requestMembership(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:191',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:191',
        ]);

        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'No autorizado'], 401);
        }

        // Si ya tiene una en espera de aprobación, la actualizamos para no duplicar
        $existing = Tenant::where('owner_user_id', $user->id)->where('status', 'pending_approval')->first();
        if ($existing) {
            $existing->update([
                'name' => $request->company_name,
                'phone' => $request->phone ?? $user->phone_number,
                'email' => $request->email ?? $user->email,
                'membership_type' => $request->membership_type ?? 'autonomo'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Solicitud de membresía actualizada. En espera de autorización del Root.',
                'tenant' => $existing
            ], 200);
        }

        // Crear registro de tenant en estado pendiente
        // Generar un código temporal que se confirmará al autorizar
        $tempCode = 'PENDING_' . time() . '_' . $user->id;

        $tenant = Tenant::create([
            'name' => $request->company_name,
            'code' => $tempCode,
            'owner_user_id' => $user->id,
            'phone' => $request->phone ?? $user->phone_number,
            'email' => $request->email ?? $user->email,
            'status' => 'pending_approval',
            'membership_type' => $request->membership_type ?? 'autonomo'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud de membresía enviada correctamente. En espera de autorización del Root.',
            'tenant' => $tenant
        ], 201);
    }

    /**
     * Listar solicitudes de membresía pendientes (Solo Root: role_id = 0).
     */
    public function pendingMemberships()
    {
        if (auth()->user()->role_id !== 0) {
            return response()->json(['error' => 'No tienes permisos de Root'], 403);
        }

        $pending = Tenant::with('owner')->where('status', 'pending_approval')->get();

        return response()->json([
            'success' => true,
            'pending_tenants' => $pending
        ]);
    }

    /**
     * Aprobar una empresa/Autónomo por parte del Root.
     * Le otorga el role_id = 4 al dueño y genera su código AUT_01, AUT_02, etc.
     */
    public function approveTenant(Request $request, $id)
    {
        if (auth()->user()->role_id !== 0) {
            return response()->json(['error' => 'No tienes permisos de Root'], 403);
        }

        $tenant = Tenant::findOrFail($id);

        // Generar código AUT_XX
        $code = 'AUT_' . str_pad($tenant->id, 2, '0', STR_PAD_LEFT);
        
        $tenant->update([
            'status' => 'active',
            'code' => $code
        ]);

        // Actualizar al dueño con el rol 4 (Autónomo) y asignarle su tenant_id
        if ($tenant->owner_user_id) {
            DB::table('roles')->insertOrIgnore([
                'id' => 4,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            User::withoutGlobalScopes()->where('id', $tenant->owner_user_id)->update([
                'role_id' => 4, // 4 = Autónomo
                'tenant_id' => $tenant->id
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Autónomo autorizado exitosamente con el código {$code}",
            'tenant' => $tenant
        ]);
    }

    /**
     * Actualizar el tipo de membresía y límites de una empresa / autónomo (Solo Root).
     */
    public function updateSubscriptionPlan(Request $request, $id)
    {
        if (auth()->user()->role_id !== 0) {
            return response()->json(['error' => 'No tienes permisos de Root'], 403);
        }

        $tenant = Tenant::findOrFail($id);

        $membershipType = $request->membership_type ?? $tenant->membership_type ?? 'autonomo_empresarial';
        $subAmount = $request->subscription_amount ?? $tenant->subscription_amount ?? 935.00;
        $maxProperties = $request->max_properties ?? $tenant->max_properties ?? 30;
        $extraProperties = $request->extra_properties_count ?? $tenant->extra_properties_count ?? 0;
        $maxClients = $request->max_clients ?? $tenant->max_clients ?? 30;
        $expiresAt = $request->subscription_expires_at ?? $tenant->subscription_expires_at;

        if ($membershipType === 'autonomo_fundador') {
            $subAmount = 659.00;
            $maxProperties = 9999;
            $maxClients = 9999;
        } elseif ($membershipType === 'autonomo_personal') {
            $subAmount = 299.00;
        } elseif ($membershipType === 'autonomo_empresarial') {
            $subAmount = 935.00;
        }

        $tenant->update([
            'membership_type' => $membershipType,
            'subscription_amount' => $subAmount,
            'max_properties' => $maxProperties,
            'extra_properties_count' => $extraProperties,
            'max_clients' => $maxClients,
            'subscription_expires_at' => $expiresAt
        ]);

        if ($tenant->owner_user_id) {
            $roleId = ($membershipType === 'autonomo_personal') ? 5 : 4;
            User::withoutGlobalScopes()->where('id', $tenant->owner_user_id)->update(['role_id' => $roleId]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Plan y límites del autónomo actualizados correctamente.',
            'tenant' => $tenant
        ]);
    }

    /**
     * Traspasar cartera completa de un Autónomo a otro (o a Root).
     */
    public function transferPortfolio(Request $request)
    {
        if (auth()->user()->role_id !== 0) {
            return response()->json(['error' => 'No tienes permisos de Root'], 403);
        }

        $request->validate([
            'from_tenant_id' => 'required|integer',
            'to_tenant_id' => 'nullable|integer', // Si es null o 0, pasa a Root
        ]);

        $from = $request->from_tenant_id;
        $to = $request->to_tenant_id ?: null;

        DB::beginTransaction();
        try {
            // Actualizar masivamente en todas las tablas sin aplicar Global Scopes
            User::withoutGlobalScopes()->where('tenant_id', $from)->update(['tenant_id' => $to]);
            Property::withoutGlobalScopes()->where('tenant_id', $from)->update(['tenant_id' => $to]);
            Quote::withoutGlobalScopes()->where('tenant_id', $from)->update(['tenant_id' => $to]);
            Service::withoutGlobalScopes()->where('tenant_id', $from)->update(['tenant_id' => $to]);
            WorkOrder::withoutGlobalScopes()->where('tenant_id', $from)->update(['tenant_id' => $to]);
            Client::withoutGlobalScopes()->where('tenant_id', $from)->update(['tenant_id' => $to]);
            Technician::withoutGlobalScopes()->where('tenant_id', $from)->update(['tenant_id' => $to]);

            // Desactivar o suspender al tenant viejo si se desea
            if ($request->suspend_old_tenant) {
                Tenant::where('id', $from)->update(['status' => 'suspended']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cartera de clientes, técnicos, propiedades y cotizaciones transferida con éxito.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al transferir la cartera: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Listar técnicos en espera de autorización en la empresa del usuario logueado.
     */
    public function pendingTechnicians()
    {
        $user = auth()->user();
        
        $query = User::withoutGlobalScopes()
            ->whereIn('role_id', [2, 6, 7, 8])
            ->where('approval_status', 'pending');

        if ($user->role_id !== 0) {
            $query->where('tenant_id', $user->tenant_id);
        }

        $technicians = $query->with(['tenant:id,name,code', 'specialties'])
            ->select('id', 'first_name', 'last_name', 'email', 'phone_number', 'created_at', 'tenant_id', 'role_id', 'approval_status', 'is_active')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'pending_technicians' => $technicians
        ]);
    }

    /**
     * Aprobar a un técnico que solicitó unirse al equipo (Sala de espera).
     */
    public function approveTechnician(Request $request, $userId)
    {
        $currentUser = auth()->user();
        
        $technician = User::withoutGlobalScopes()->findOrFail($userId);

        // Verificar que el técnico pertenezca al mismo tenant del autónomo o que sea Root
        if ($currentUser->role_id !== 0 && $technician->tenant_id !== $currentUser->tenant_id) {
            return response()->json(['error' => 'No tienes permisos sobre este técnico'], 403);
        }

        $technician->update([
            'approval_status' => 'approved',
            'is_active' => 1
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Usuario autorizado y dado de alta exitosamente.',
            'technician' => $technician
        ]);
    }
}
