<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;

class UserController extends Controller
{

    public function updateProfile(Request $request)
    {
        try {
            // ✅ CORRECCIÓN: Detectamos si llega el ID limpio ('user_id') o el viejo ('id' con prefijo)
            $idWithPrefix = $request->input('id');
            $cleanUserId = $request->input('user_id');

            $profilePicturePath = null;

            if ($request->hasFile('profile_picture')) {
                $profilePicturePath = $request->file('profile_picture')->store('profile_pictures', 'public');
            }

            // ---------------------------------------------------------
            // 1. MANEJO PARA CLIENTES (Tabla 'clients')
            // ---------------------------------------------------------
            if ($idWithPrefix && str_starts_with($idWithPrefix, 'c_')) {
                $realId = str_replace('c_', '', $idWithPrefix);
                $cliente = DB::table('clients')->where('id', $realId)->first();

                if (!$cliente) {
                    return response()->json(['success' => false, 'message' => 'Cliente no encontrado'], 404);
                }

                $emailExists = DB::table('users')->where('email', $request->input('email'))->where('id', '!=', $cliente->user_id)->exists();
                if ($emailExists) {
                    return response()->json(['success' => false, 'message' => 'Este correo electrónico ya está registrado en otra cuenta.'], 422);
                }
                
                $phone = $request->input('phone_number');
                if (!empty($phone)) {
                    $phoneExists = DB::table('users')->where('phone_number', $phone)->where('id', '!=', $cliente->user_id)->exists();
                    if ($phoneExists) {
                        return response()->json(['success' => false, 'message' => 'Este número de teléfono ya está registrado en otra cuenta.'], 422);
                    }
                }

                $fullName = trim($request->input('first_name', '') . ' ' . $request->input('last_name', ''));
                $isActiveClient = $request->has('is_active') && $request->input('is_active') !== null ? $request->input('is_active') : ($cliente->is_active ?? 1);

                $updateData = [
                    'name' => !empty($fullName) ? $fullName : $cliente->name,
                    'email' => $request->input('email', $cliente->email),
                    'phone' => $phone !== null ? $phone : $cliente->phone,
                    'is_active' => $isActiveClient,
                    'updated_at' => now(),
                ];

                if ($cliente->user_id) {
                    $userDb = DB::table('users')->where('id', $cliente->user_id)->first();
                    $isActiveUser = $request->has('is_active') && $request->input('is_active') !== null ? $request->input('is_active') : ($userDb->is_active ?? 1);
                    DB::table('users')->where('id', $cliente->user_id)->update([
                        'first_name' => $request->input('first_name', $userDb->first_name ?? ''),
                        'last_name' => $request->input('last_name', $userDb->last_name ?? ''),
                        'email' => $request->input('email', $userDb->email ?? ''),
                        'phone_number' => $phone !== null ? $phone : ($userDb->phone_number ?? ''),
                        'is_active' => $isActiveUser,
                        'updated_at' => now(),
                    ]);
                }

                if ($profilePicturePath) {
                    $updateData['profile_picture'] = $profilePicturePath;
                    if ($cliente->profile_picture && !str_starts_with($cliente->profile_picture, 'http')) {
                        Storage::disk('public')->delete($cliente->profile_picture);
                    }
                }

                DB::table('clients')->where('id', $realId)->update($updateData);

                $clienteActualizado = DB::table('clients')->where('id', $realId)->first();
                $fotoUrl = $clienteActualizado->profile_picture
                    ? (str_starts_with($clienteActualizado->profile_picture, 'http') ? $clienteActualizado->profile_picture : asset('storage/' . $clienteActualizado->profile_picture))
                    : null;

                return response()->json([
                    'success' => true,
                    'message' => 'Expediente del cliente actualizado.',
                    'new_picture_url' => $fotoUrl
                ], 200);
            }

            // ---------------------------------------------------------
            // 2. MANEJO PARA USUARIOS (Tabla 'users')
            // ---------------------------------------------------------

            // 1ro. ASIGNACIÓN DINÁMICA DEL ID
            $realId = $cleanUserId ? $cleanUserId : str_replace('u_', '', $idWithPrefix);

            $user = User::find($realId);

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no encontrado en la base de datos'], 404);
            }

            $emailExists = User::where('email', $request->input('email'))->where('id', '!=', $realId)->exists();
            if ($emailExists) {
                return response()->json(['success' => false, 'message' => 'Este correo electrónico ya está en uso por otro usuario.'], 422);
            }

            $phone = $request->input('phone_number');
            if (!empty($phone)) {
                $phoneExists = User::where('phone_number', $phone)->where('id', '!=', $realId)->exists();
                if ($phoneExists) {
                    return response()->json(['success' => false, 'message' => 'Este número de teléfono ya está registrado por otro usuario.'], 422);
                }
            }

            if ($request->has('first_name') && $request->input('first_name') !== null) {
                $user->first_name = $request->input('first_name');
            }
            if ($request->has('last_name') && $request->input('last_name') !== null) {
                $user->last_name = $request->input('last_name');
            }
            if ($request->has('email') && $request->input('email') !== null) {
                $user->email = $request->input('email');
            }
            if ($phone !== null) {
                $user->phone_number = $phone;
            }
            if ($request->has('is_active') && $request->input('is_active') !== null) {
                $user->is_active = $request->input('is_active');
            }

            if ($profilePicturePath) {
                if ($user->profile_picture && !str_starts_with($user->profile_picture, 'http')) {
                    Storage::disk('public')->delete($user->profile_picture);
                }
                $user->profile_picture = $profilePicturePath;
            }

            $user->save();
            $user->load(['tenant', 'specialties']);

            $fotoUrl = $user->profile_picture
                ? (str_starts_with($user->profile_picture, 'http') ? $user->profile_picture : asset('storage/' . $user->profile_picture))
                : null;

            return response()->json([
                'success' => true,
                'message' => 'Perfil de usuario actualizado con éxito.',
                'new_picture_url' => $fotoUrl,
                'user' => $user
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage()
            ], 500);
        }
    }


    // =========================================================================
    // OTRAS FUNCIONES (Rol, Listar, Eliminar, Bloquear)
    // =========================================================================
    public function updateRole(Request $request, $id)
    {
        $realId = str_replace('u_', '', $id);

        // Permitir todos los roles asignables del sistema (1=Admin, 2=Tecnico, 3=Cliente, 4=Aut.Empresarial, 5=Aut.Personal, 6=Contratista, 7=Admin Propiedades, 8=Tecnico Red)
        $request->validate([
            'role_id' => 'required|numeric|in:0,1,2,3,4,5,6,7,8'
        ]);

        // SEGURIDAD: Nadie puede ser ascendido a ROOT desde la interfaz
        if ((int)$request->role_id === 0) {
            return response()->json(['success' => false, 'message' => 'Por seguridad, el rol ROOT MASTER no puede ser asignado desde aquí.'], 403);
        }

        $user = null;
        if (str_starts_with($id, 'c_')) {
            $realClientId = str_replace('c_', '', $id);
            $clientObj = DB::table('clients')->where('id', $realClientId)->first();
            if ($clientObj && $clientObj->user_id) {
                $user = User::withoutGlobalScopes()->find($clientObj->user_id);
            } elseif ($clientObj && $clientObj->email) {
                $user = User::withoutGlobalScopes()->where('email', $clientObj->email)->first();
            }

            // Si el cliente aún no tiene fila en la tabla users, crearla automáticamente
            if (!$user && $clientObj) {
                $nameParts = explode(' ', trim($clientObj->name ?? 'Cliente'));
                $firstName = $nameParts[0] ?? 'Cliente';
                $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';
                $email = $clientObj->email ?: ('cliente_' . $realClientId . '@agentesolutions.com');

                $user = User::create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone_number' => $clientObj->phone ?? null,
                    'password' => bcrypt('password123'),
                    'role_id' => (int)$request->role_id,
                    'is_active' => $clientObj->is_active ?? 1,
                    'tenant_id' => $clientObj->tenant_id ?? null,
                    'profile_picture' => $clientObj->profile_picture ?? null,
                ]);

                DB::table('clients')->where('id', $realClientId)->update(['user_id' => $user->id]);
            }
        } else {
            $user = User::withoutGlobalScopes()->find($realId);
        }

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        // SEGURIDAD: Nunca quitarle el rol de ROOT a un usuario Root
        if ((int)$user->role_id === 0) {
            return response()->json(['success' => false, 'message' => 'Por seguridad, la cuenta ROOT MASTER está protegida y su rol no puede ser modificado.'], 403);
        }

        // A PRUEBA DE BALAS: Asegurar que el rol exista en la tabla roles
        DB::table('roles')->insertOrIgnore(['id' => (int)$request->role_id, 'created_at' => now(), 'updated_at' => now()]);

        $user->role_id = (int)$request->role_id;

        // Si se cambia a Autónomo Empresarial (4) o Personal (5), crear/activar Tenant
        if (in_array((int)$request->role_id, [4, 5])) {
            $isPersonal     = ((int)$request->role_id === 5);
            $membershipType = $request->membership_type ?? ($isPersonal ? 'autonomo_personal' : 'autonomo_empresarial');
            
            if ($membershipType === 'autonomo_fundador') {
                $subAmount     = 659.00;
                $maxProperties = 9999;
                $maxClients    = 9999;
                $codePrefix    = 'AUT_F_';
            } elseif ($isPersonal) {
                $subAmount     = 299.00;
                $maxProperties = 3;
                $maxClients    = 0;
                $codePrefix    = 'AUT_P_';
            } else {
                $subAmount     = 935.00;
                $maxProperties = 30;
                $maxClients    = 30;
                $codePrefix    = 'AUT_E_';
            }

            $tenant = Tenant::where('owner_user_id', $user->id)->orWhere('email', $user->email)->first();
            if (!$tenant) {
                $nextId = (Tenant::max('id') ?? 0) + 1;
                $code   = $codePrefix . str_pad($nextId, 3, '0', STR_PAD_LEFT);
                $tenant = Tenant::create([
                    'name'                    => trim($user->first_name . ' ' . $user->last_name),
                    'code'                    => $code,
                    'owner_user_id'           => $user->id,
                    'phone'                   => $user->phone_number,
                    'email'                   => $user->email,
                    'status'                  => 'active',
                    'membership_type'         => $membershipType,
                    'max_properties'          => $maxProperties,
                    'max_clients'             => $maxClients,
                    'billing_cycle'           => 'trial',
                    'subscription_status'     => 'active',
                    'subscription_start'      => now(),
                    'subscription_expires_at' => now()->addMonths(6),
                    'subscription_amount'     => $subAmount,
                ]);
            } else {
                $tenant->update([
                    'status'                  => 'active',
                    'membership_type'         => $membershipType,
                    'max_properties'          => $maxProperties,
                    'max_clients'             => $maxClients,
                    'billing_cycle'           => 'trial',
                    'subscription_status'     => 'active',
                    'subscription_start'      => now(),
                    'subscription_expires_at' => now()->addMonths(6),
                    'subscription_amount'     => $subAmount,
                ]);
            }
            $user->tenant_id  = $tenant->id;
            $user->is_active  = 1;

            // Vincular automáticamente el expediente de cliente y propiedades previas al nuevo tenant
            DB::table('clients')
                ->where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->update(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

            $clientIds = DB::table('clients')
                ->where('user_id', $user->id)
                ->orWhere('email', $user->email)
                ->orWhere('tenant_id', $tenant->id)
                ->pluck('id');

            if ($clientIds->isNotEmpty()) {
                DB::table('properties')
                    ->whereIn('client_id', $clientIds)
                    ->whereNull('tenant_id')
                    ->update(['tenant_id' => $tenant->id]);
            }
        } elseif ((int)$request->role_id === 2 || (int)$request->role_id === 8) {
            // Técnico: si es externo de Agente Solutions, tiene 1 año gratis, luego $99/mes
            $tenantObj = $user->tenant_id ? Tenant::find($user->tenant_id) : null;
            $isAgenteSolutionsTech = ($user->tenant_id == 1 || ($tenantObj && ($tenantObj->code === 'AUT_01' || stripos($tenantObj->name, 'Agente Solutions') !== false)));

            if ($isAgenteSolutionsTech) {
                $user->subscription_status = 'exempt';
                $user->subscription_amount = 0.00;
            } else {
                $user->subscription_status     = 'active';
                $user->subscription_start      = now();
                $user->subscription_expires_at = now()->addYear();
                $user->subscription_amount     = 99.00;
            }
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Rol actualizado correctamente.',
            'user'    => $user
        ], 200);
    }

    public function getUsuarios()
    {
        try {
            $currentUser = auth('sanctum')->user();

            $usersQuery = \App\Models\User::withoutGlobalScopes()
                ->with('specialties')
                ->select(
                    'id', 'first_name', 'last_name', 'email', 'role_id', 'is_active', 
                    'approval_status', 'profile_picture', 'phone_number', 'tenant_id',
                    'rating_stars_avg', 'rating_time_avg', 'total_reviews_count'
                );
            
            if ($currentUser && $currentUser->role_id === 0) {
                // ROOT: Visión global completa de todos los usuarios
            } elseif ($currentUser && $currentUser->role_id === 3) {
                // CLIENTE (de la Red / Particular): Solo ve técnicos propios, técnicos de la red y su propia cuenta
                $usersQuery->where(function($q) use ($currentUser) {
                    $q->where('id', $currentUser->id)
                      ->orWhere(function($sub) use ($currentUser) {
                          if (!empty($currentUser->tenant_id)) {
                              $sub->where('tenant_id', $currentUser->tenant_id)->where('role_id', 2);
                          } else {
                              $sub->where('role_id', 2);
                          }
                      })
                      ->orWhere('role_id', 8) // Técnicos de la red
                      ->orWhere('role_id', 2); // Técnicos Agente
                })->where('role_id', '!=', 0);
            } elseif ($currentUser && !empty($currentUser->tenant_id)) {
                // Usuario con Tenant (Autónomo, Gestor, etc.):
                $usersQuery->where(function($q) use ($currentUser) {
                    $q->where('tenant_id', $currentUser->tenant_id)
                      ->orWhere('id', $currentUser->id)
                      ->orWhere('role_id', 8);
                })->where('role_id', '!=', 0);
            } elseif ($currentUser) {
                // Usuario sin tenant: se ve a sí mismo y a técnicos disponibles
                $usersQuery->where(function($q) use ($currentUser) {
                    $q->where('id', $currentUser->id)
                      ->orWhereIn('role_id', [2, 8]);
                })->where('role_id', '!=', 0);
            }

            $usuariosQuery = $usersQuery->get();

            $usuarios = $usuariosQuery->map(function ($u) use ($currentUser) {
                $fotoUrl = $u->profile_picture ? (str_starts_with($u->profile_picture, 'http') ? $u->profile_picture : asset('storage/' . $u->profile_picture)) : null;

                // Obtener reseña específica dada por el cliente actual a este técnico (si existe)
                $myReview = null;
                $jobsCount = 0;
                if ($currentUser && in_array((int)$u->role_id, [2, 6, 8])) {
                    $reviewDb = \App\Models\TechnicianReview::where('technician_id', $u->id)
                        ->where('client_id', $currentUser->id)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    if ($reviewDb) {
                        $myReview = [
                            'rating_stars' => (float)$reviewDb->rating_stars,
                            'rating_time'  => (float)$reviewDb->rating_time,
                            'comment'      => $reviewDb->comment,
                            'created_at'   => $reviewDb->created_at ? $reviewDb->created_at->format('d/m/Y H:i') : null,
                        ];
                    }

                    $jobsCount = \App\Models\WorkOrder::withoutGlobalScopes()
                        ->where('tecnico_id', $u->id)
                        ->where(function($q) use ($currentUser) {
                            $q->whereHas('property', function($pq) use ($currentUser) {
                                $pq->withoutGlobalScopes()->where('user_id', $currentUser->id);
                            });
                            if (!empty($currentUser->tenant_id)) {
                                $q->orWhere('tenant_id', $currentUser->tenant_id);
                            }
                        })
                        ->count();
                }

                return [
                    'id' => 'u_' . $u->id,
                    'first_name' => $u->first_name,
                    'last_name' => $u->last_name,
                    'email' => $u->email,
                    'role_id' => $u->role_id,
                    'is_active' => $u->is_active,
                    'approval_status' => $u->approval_status,
                    'profile_picture_url' => $fotoUrl,
                    'phone_number' => $u->phone_number,
                    'address' => 'No aplica',
                    'specialties' => $u->specialties ?? [],
                    'rating_stars_avg' => round((float)($u->rating_stars_avg ?? 5.0), 1),
                    'rating_time_avg' => round((float)($u->rating_time_avg ?? 5.0), 1),
                    'total_reviews_count' => (int)($u->total_reviews_count ?? 0),
                    'my_review' => $myReview,
                    'jobs_count' => $jobsCount,
                ];
            });

            // Si es un cliente, no incluimos la tabla clients externa ya que solo le interesan sus técnicos
            if ($currentUser && $currentUser->role_id === 3) {
                return response()->json($usuarios->values(), 200);
            }

            // Evitar duplicados con la tabla clients para admins/root
            $userEmails = $usuariosQuery->pluck('email')->filter()->map(fn($e) => strtolower(trim($e)))->toArray();
            $userIds = $usuariosQuery->pluck('id')->toArray();

            $clientesDbQuery = DB::table('clients')
                ->select('id', 'user_id', 'name', 'email', 'phone', 'profile_picture', 'is_active', 'tenant_id');

            if ($currentUser && $currentUser->role_id === 0) {
                // ROOT ve todos los clientes
            } elseif ($currentUser && !empty($currentUser->tenant_id)) {
                $clientesDbQuery->where('tenant_id', $currentUser->tenant_id);
            } else {
                $clientesDbQuery->where('id', 0);
            }

            $clientesQuery = $clientesDbQuery->get()->filter(function ($c) use ($userEmails, $userIds) {
                if ($c->user_id && in_array($c->user_id, $userIds)) return false;
                if ($c->email && in_array(strtolower(trim($c->email)), $userEmails)) return false;
                return true;
            });

            $clientes = $clientesQuery->map(function ($c) {
                $fotoUrl = $c->profile_picture ? (str_starts_with($c->profile_picture, 'http') ? $c->profile_picture : asset('storage/' . $c->profile_picture)) : null;
                return [
                    'id' => 'c_' . $c->id,
                    'first_name' => $c->name,
                    'last_name' => '',
                    'email' => $c->email,
                    'role_id' => 3,
                    'is_active' => $c->is_active,
                    'profile_picture_url' => $fotoUrl,
                    'phone_number' => $c->phone,
                    'address' => 'No registrada',
                    'rating_stars_avg' => 5.0,
                    'rating_time_avg' => 5.0,
                    'total_reviews_count' => 0,
                    'my_review' => null,
                    'jobs_count' => 0,
                ];
            });

            $todosLosRegistros = $usuarios->concat($clientes)->values();
            return response()->json($todosLosRegistros, 200);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function eliminarUsuario($id)
    {
        try {
            if (str_starts_with($id, 'c_')) {
                $realId = str_replace('c_', '', $id);
                DB::table('clients')->where('id', $realId)->delete();
                return response()->json(['message' => 'Cliente eliminado correctamente'], 200);
            }

            $realId = str_replace('u_', '', $id);
            $usuario = \App\Models\User::find($realId);

            if (!$usuario) {
                return response()->json(['error' => 'Usuario no encontrado'], 404);
            }

            if ($usuario->role_id === 0) {
                return response()->json(['error' => 'Acceso Denegado: No se puede eliminar al ROOT'], 403);
            }

            $currentUser = auth('sanctum')->user();
            if ($usuario->role_id === 4 && (!$currentUser || $currentUser->role_id !== 0)) {
                return response()->json(['error' => 'Acceso Denegado: Solo el ROOT puede eliminar usuarios Autónomos.'], 403);
            }

            if ($usuario->role_id === 4 && $usuario->tenant_id) {
                \App\Models\Tenant::where('owner_user_id', $usuario->id)->delete();
            }

            $usuario->delete();
            return response()->json(['message' => 'Usuario eliminado correctamente'], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al eliminar: ' . $e->getMessage()], 500);
        }
    }

    public function deleteMyAccount(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json(['error' => 'Usuario no autenticado'], 401);
            }

            if ((int)$user->role_id === 0) {
                return response()->json(['error' => 'Acceso Denegado: El administrador ROOT no puede auto-eliminar su cuenta.'], 403);
            }

            $user->is_active = 0;
            $user->approval_status = 'deleted_by_user';
            $user->save();

            if ((int)$user->role_id === 3) {
                DB::table('clients')->where('user_id', $user->id)->update(['is_active' => 0]);
            }

            // Revocar todos los tokens de Sanctum para cerrar sesión al instante
            $user->tokens()->delete();

            // Enviar notificación a los usuarios ROOT (role_id === 0)
            $admins = User::withoutGlobalScopes()->where('role_id', 0)->get();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new \App\Notifications\UserAccountDeletedNotification($user));
            }

            return response()->json(['message' => 'Tu perfil y cuenta han sido eliminados y cerrados exitosamente.'], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al procesar la eliminación: ' . $e->getMessage()], 500);
        }
    }

    public function toggleBloqueo($id)
    {
        try {
            if (str_starts_with($id, 'c_')) {
                $realId = str_replace('c_', '', $id);
                $cliente = DB::table('clients')->where('id', $realId)->first();

                if (!$cliente) {
                    return response()->json(['error' => 'Cliente no encontrado'], 404);
                }

                $nuevoEstado = $cliente->is_active ? 0 : 1;
                DB::table('clients')->where('id', $realId)->update(['is_active' => $nuevoEstado]);

                $mensaje = $nuevoEstado ? 'Cliente desbloqueado con éxito' : 'Cliente bloqueado con éxito';
                return response()->json(['message' => $mensaje, 'is_active' => $nuevoEstado], 200);
            }

            $realId = str_replace('u_', '', $id);
            $usuario = \App\Models\User::find($realId);

            if (!$usuario) {
                return response()->json(['error' => 'Usuario no encontrado'], 404);
            }

            if ($usuario->role_id === 0) {
                return response()->json(['error' => 'Acceso Denegado.'], 403);
            }

            $usuario->is_active = !$usuario->is_active;
            $usuario->save();

            $mensaje = $usuario->is_active ? 'Usuario desbloqueado con éxito' : 'Usuario bloqueado con éxito';
            return response()->json(['message' => $mensaje, 'is_active' => $usuario->is_active], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al cambiar estado: ' . $e->getMessage()], 500);
        }
    }

    public function getTecnicos()
    {
        $currentUser = auth('sanctum')->user();
        $query = User::withoutGlobalScopes()->with('specialties')->whereIn('role_id', [2, 8]);
        
        if ($currentUser && $currentUser->role_id === 0) {
            // ROOT ve todos los técnicos
        } elseif ($currentUser && !empty($currentUser->tenant_id)) {
            $query->where('tenant_id', $currentUser->tenant_id);
        }

        $tecnicos = $query->get(['id', 'first_name', 'last_name', 'profile_picture', 'tenant_id', 'is_active', 'approval_status']);
        
        $formatted = $tecnicos->map(function ($u) {
            $fotoUrl = $u->profile_picture ? (str_starts_with($u->profile_picture, 'http') ? $u->profile_picture : asset('storage/' . $u->profile_picture)) : null;
            return [
                'id' => $u->id,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'profile_picture_url' => $fotoUrl,
                'specialties' => $u->specialties ?? [],
                'tenant_id' => $u->tenant_id,
                'is_active' => $u->is_active,
                'approval_status' => $u->approval_status
            ];
        });

        return response()->json($formatted);
    }
}