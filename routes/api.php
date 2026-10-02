<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cloudinary\Cloudinary;
use App\Http\Controllers\ApplianceController;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\Api\AppSettingController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\PropertyComponentController;
use App\Http\Controllers\PropertyAreaController;
use App\Http\Controllers\PropertyCategoryController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Notification;
use App\Notifications\NewWorkOrderNotification;
use App\Models\WorkOrder;
use App\Http\Controllers\MercadoPagoController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\SpecialtyController;
use App\Http\Controllers\PropertyManagerController;
// ========================================================
// 🟢 ZONA PÚBLICA (Sin Token - Cualquiera puede entrar)
// ========================================================

Route::post('/login', [AuthController::class, 'login']);

Route::get('/test-email', function () {
    try {
        \Illuminate\Support\Facades\Mail::raw('Este es un correo de prueba de Resend', function ($msg) {
            $msg->to('ppechkoh@gmail.com')->subject('Prueba Resend');
        });
        return response()->json(['success' => true, 'message' => 'Correo enviado exitosamente con la configuración actual.']);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'error' => $e->getMessage()]);
    }
});

// Verificación de Correo (rutas públicas)
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');
Route::post('/email/resend', [AuthController::class, 'resendVerificationEmail']);

// Recuperación de contraseña (rutas públicas)
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/vincular-empresa', [AuthController::class, 'linkCompany']);

// Webhook de MercadoPago (Público para que MP pueda avisarnos del pago)
Route::post('/mercadopago/webhook', [MercadoPagoController::class, 'webhook']);
Route::post('/mercadopago/verify', [MercadoPagoController::class, 'verifyPayment']);
// Suscripción Autónomo (público: el usuario aún no está activo al pagar)
Route::post('/mercadopago/subscription/{tenantId}', [MercadoPagoController::class, 'createSubscriptionPreference']);

// Lista pública de empresas autónomas para registro y login
Route::get('/tenants/public-list', [TenantController::class, 'listTenants']);
Route::get('/specialties', [SpecialtyController::class, 'index']);

// 🌐 Mercado de Trabajos Abierto (Visible para técnicos y público, con personalización para usuarios logueados)
Route::get('/mercado-trabajos', function (\Illuminate\Http\Request $request) {
    try {
        $authUser = auth('sanctum')->user() ?: auth()->user();

        $query = \App\Models\WorkOrder::withoutGlobalScopes()
            ->with([
                'property' => function ($q) {
                    $q->withoutGlobalScopes();
                },
                'property.client' => function ($q) {
                    $q->withoutGlobalScopes();
                },
                'networkQuotes' => function ($q) {
                    $q->withoutGlobalScopes();
                },
                'networkQuotes.technician' => function ($q) {
                    $q->withoutGlobalScopes();
                },
                'networkQuotes.technician.specialties' => function ($q) {
                    $q->withoutGlobalScopes();
                }
            ])
            ->withCount('networkQuotes')
            ->where('publish_network', 1);

        // Si se pide filtrar solo los del usuario autónomo o si el usuario autenticado es un Autónomo/Cliente (role_id 3, 4, 5, 7)
        if ($request->boolean('only_mine') || ($authUser && in_array((int)$authUser->role_id, [3, 4, 5, 7]))) {
            $query->whereIn('status', ['Por Hacer', 'Asignado', 'En Progreso']);
            if (!in_array((int)$authUser->role_id, [0, 1])) { // SuperAdmin / Root puede ver todos
                $query->where(function ($q) use ($authUser) {
                    $q->whereHas('property.client', function ($qc) use ($authUser) {
                        $qc->withoutGlobalScopes()
                           ->where('user_id', $authUser->id)
                           ->orWhere('email', $authUser->email)
                           ->orWhere('name', 'like', "%{$authUser->first_name}%");
                    });

                    if (!empty($authUser->tenant_id)) {
                        $q->orWhere('tenant_id', $authUser->tenant_id)
                          ->orWhereHas('property', function ($qp) use ($authUser) {
                              $qp->withoutGlobalScopes()->where('tenant_id', $authUser->tenant_id);
                          });
                    }
                });
            }
        }

        $jobs = $query->orderBy('created_at', 'desc')->get();

        $jobs->transform(function ($job) use ($authUser) {
            $ownerName = '';
            $ownerUserId = null;
            $ownerTenantId = $job->tenant_id ?: ($job->property?->tenant_id ?? null);

            // 1. Intentar por usuario asociado al cliente de la propiedad
            if ($job->property && $job->property->client && $job->property->client->user_id) {
                $user = \App\Models\User::withoutGlobalScopes()->find($job->property->client->user_id);
                if ($user) {
                    $ownerName = trim("{$user->first_name} {$user->last_name}") ?: $user->name;
                    $ownerUserId = $user->id;
                }
            }

            // 2. Si no o si es genérico, intentar por el Tenant del trabajo
            if ((empty($ownerName) || strtolower($ownerName) === 'cliente de prueba' || strtolower($ownerName) === 'cliente desconocido') && $ownerTenantId) {
                $tenant = \App\Models\Tenant::withoutGlobalScopes()->find($ownerTenantId);
                if ($tenant && $tenant->owner_user_id) {
                    $user = \App\Models\User::withoutGlobalScopes()->find($tenant->owner_user_id);
                    if ($user) {
                        $ownerName = trim("{$user->first_name} {$user->last_name}") ?: $user->name;
                        $ownerUserId = $user->id;
                    }
                }
                if (empty($ownerName) || strtolower($ownerName) === 'cliente de prueba') {
                    $user = \App\Models\User::withoutGlobalScopes()
                        ->where('tenant_id', $ownerTenantId)
                        ->whereIn('role_id', [3, 4, 5])
                        ->first();
                    if ($user) {
                        $ownerName = trim("{$user->first_name} {$user->last_name}") ?: $user->name;
                        $ownerUserId = $user->id;
                    }
                }
                if (empty($ownerName) && $tenant && !empty($tenant->name)) {
                    $ownerName = $tenant->name;
                }
            }

            // 3. Si aún está vacío o es genérico, intentar por el nombre del Cliente de la propiedad si no es de prueba
            if (empty($ownerName) || strtolower($ownerName) === 'cliente de prueba' || strtolower($ownerName) === 'cliente desconocido') {
                if ($job->property && $job->property->client) {
                    $client = $job->property->client;
                    $cName = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''));
                    if (empty($cName)) $cName = $client->name ?? '';
                    if (!empty($cName) && strtolower($cName) !== 'cliente de prueba' && strtolower($cName) !== 'cliente desconocido') {
                        $ownerName = $cName;
                    }
                }
            }

            // 4. Si el usuario autenticado es quien consulta y es el dueño de la orden, usar su nombre
            if ($authUser && (empty($ownerName) || strtolower($ownerName) === 'cliente de prueba' || strtolower($ownerName) === 'cliente desconocido')) {
                if ($authUser->tenant_id && $authUser->tenant_id == $ownerTenantId) {
                    $ownerName = trim("{$authUser->first_name} {$authUser->last_name}") ?: $authUser->name;
                    $ownerUserId = $authUser->id;
                }
            }

            $job->owner_name = $ownerName ?: 'Cliente de la Red';
            $job->owner_user_id = $ownerUserId;
            $job->owner_tenant_id = $ownerTenantId;

            // Coordenadas fijas y estables (nunca saltan en recargas)
            $rawLat = 21.0181;
            $rawLng = -89.6242;
            $hasRealCoords = false;

            if ($job->property && !empty($job->property->coordinates)) {
                $coordsParts = explode(',', $job->property->coordinates);
                if (count($coordsParts) >= 2) {
                    $parsedLat = (float) trim($coordsParts[0]);
                    $parsedLng = (float) trim($coordsParts[1]);
                    if ($parsedLat != 0 && $parsedLng != 0) {
                        $rawLat = $parsedLat;
                        $rawLng = $parsedLng;
                        $hasRealCoords = true;
                    }
                }
            }

            if (!$hasRealCoords) {
                $seed = (($job->property_id ?: $job->id) * 17) % 360;
                $rawLat = 21.0181 + (sin(deg2rad($seed)) * 0.025);
                $rawLng = -89.6242 + (cos(deg2rad($seed)) * 0.025);
            }

            // Área / Zona de cobertura aproximada (Protección de privacidad de la casa exacta)
            $areaLat = round($rawLat, 3);
            $areaLng = round($rawLng, 3);
            $job->area_lat = $areaLat;
            $job->area_lng = $areaLng;
            $job->lat = $areaLat;
            $job->lng = $areaLng;

            // Extracción limpia de Colonia / Fraccionamiento y Ciudad cercana (Privacidad de dirección)
            $fullAddress = $job->property ? ($job->property->address ?: '') : '';
            $zonaColonia = 'Mérida, Yucatán';

            if (!empty($fullAddress)) {
                $city = '';
                $colonia = '';
                if (preg_match('/(?:Col\.|Colonia|Fracc\.|Fraccionamiento)\s*([^,]+)/iu', $fullAddress, $matches)) {
                    $colonia = trim($matches[0]);
                }
                if (preg_match('/\b(M[eé]rida|Um[aá]n|Kanas[ií]n|Progreso|Conkal|Valladolid|Tizim[ií]n|Motul|Hunucm[aá]|Tekax|Ticul|Chelem|Chicxulub)\b/iu', $fullAddress, $cityMatches)) {
                    $city = trim($cityMatches[1]);
                }
                if ($city && $colonia) {
                    $zonaColonia = "{$city}, {$colonia}";
                } elseif ($colonia) {
                    $zonaColonia = $colonia;
                } elseif ($city) {
                    $zonaColonia = "{$city}, Yucatán";
                } else {
                    $parts = array_filter(array_map('trim', explode(',', $fullAddress)));
                    if (count($parts) >= 2) {
                        $zonaColonia = implode(', ', array_slice($parts, -2));
                    }
                }
            } elseif ($job->property && !empty($job->property->property_name)) {
                $zonaColonia = $job->property->property_name;
            }

            $job->zona_colonia = $zonaColonia;
            $job->zona = $zonaColonia;
            $job->area_name = $zonaColonia;
            $job->colonia_cercana = $zonaColonia;
            $job->priority = $job->priority ?: 'Normal';
            $job->is_urgent = in_array(strtolower($job->priority), ['urgente', 'sos', 'urgent']);
            $job->scheduled_at = $job->scheduled_at ? (\Carbon\Carbon::parse($job->scheduled_at)->format('Y-m-d H:i')) : null;

            $isMine = false;
            if ($authUser) {
                if (in_array((int)$authUser->role_id, [0, 1])) {
                    $isMine = true;
                } elseif (!empty($authUser->tenant_id) && ($job->tenant_id == $authUser->tenant_id || $job->property?->tenant_id == $authUser->tenant_id)) {
                    $isMine = true;
                } elseif ($job->property?->client?->user_id == $authUser->id || $job->property?->client?->email == $authUser->email) {
                    $isMine = true;
                } elseif (!empty($ownerName) && !empty($authUser->first_name) && stripos($ownerName, $authUser->first_name) !== false) {
                    $isMine = true;
                }
            }
            $job->is_mine = $isMine;

            return $job;
        });

        // 🟢 Trabajos aceptados / asignados para este técnico
        $acceptedJobs = [];
        if ($authUser) {
            $acceptedQuery = \App\Models\WorkOrder::withoutGlobalScopes()
                ->with([
                    'property' => fn($q) => $q->withoutGlobalScopes(),
                    'property.client' => fn($q) => $q->withoutGlobalScopes(),
                    'networkQuotes' => fn($q) => $q->withoutGlobalScopes()->where('technician_id', $authUser->id),
                    'networkQuotes.technician' => fn($q) => $q->withoutGlobalScopes(),
                ])
                ->where(function ($q) use ($authUser) {
                    $q->where('tecnico_id', $authUser->id)
                      ->orWhereHas('networkQuotes', function ($nq) use ($authUser) {
                          $nq->where('technician_id', $authUser->id)
                             ->where('status', 'accepted');
                      });
                })
                ->whereIn('status', ['Asignado', 'En Progreso', 'Terminado'])
                ->orderBy('updated_at', 'desc');

            $rawAccepted = $acceptedQuery->get();
            $acceptedJobs = $rawAccepted->map(function ($order) use ($authUser) {
                $rawLat = 21.0181;
                $rawLng = -89.6242;
                if ($order->property && !empty($order->property->coordinates)) {
                    $parts = explode(',', $order->property->coordinates);
                    if (count($parts) >= 2) {
                        $rawLat = (float) trim($parts[0]);
                        $rawLng = (float) trim($parts[1]);
                    }
                }
                $acceptedQuote = $order->networkQuotes->firstWhere('status', 'accepted') ?: $order->networkQuotes->first();

                $clientName = 'Cliente';
                $clientPhone = '';
                $clientEmail = '';
                if ($order->property && $order->property->client) {
                    $client = $order->property->client;
                    $clientName = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) ?: ($client->name ?? 'Cliente');
                    $clientPhone = $client->phone ?: ($client->phone_number ?? '');
                    $clientEmail = $client->email ?? '';
                }

                $fotos = array_values(array_filter([
                    $order->evidence_path,
                    $order->evidence_path_2,
                    $order->property?->facade_photo_path
                ]));

                return [
                    'id' => $order->id,
                    'type' => $order->type,
                    'titulo' => $order->type . ($order->equipment ? ' - ' . $order->equipment : ''),
                    'equipment' => $order->equipment,
                    'zone' => $order->zone,
                    'description' => $order->description,
                    'status' => $order->status,
                    'priority' => $order->priority ?: 'Normal',
                    'is_urgent' => in_array(strtolower($order->priority ?? ''), ['urgente', 'sos', 'urgent']),
                    'scheduled_at' => $order->scheduled_at ? (\Carbon\Carbon::parse($order->scheduled_at)->format('Y-m-d H:i')) : null,
                    'evidence_path' => $order->evidence_path,
                    'evidence_path_2' => $order->evidence_path_2,
                    'foto' => $fotos[0] ?? null,
                    'fotos' => $fotos,
                    'lat' => $rawLat,
                    'lng' => $rawLng,
                    'full_address' => $order->property ? $order->property->address : 'Dirección confirmada',
                    'property_name' => $order->property ? ($order->property->nombre_propiedad ?: $order->property->address) : '',
                    'client_name' => $clientName,
                    'client_phone' => $clientPhone,
                    'client_email' => $clientEmail,
                    'agreed_price' => $acceptedQuote ? (float)$acceptedQuote->price : 0,
                    'myQuote' => $acceptedQuote,
                    'created_at' => $order->created_at->toIso8601String(),
                    'updated_at' => $order->updated_at->toIso8601String(),
                ];
            });
        }

        return response()->json([
            'success' => true,
            'data' => $jobs,
            'accepted_jobs' => $acceptedJobs
        ]);
    } catch (\Throwable $e) {
        \Log::error("Error in /mercado-trabajos: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => basename($e->getFile()),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});


// 🧹 RUTA DE EMERGENCIA (Temporalmente Pública para facilitar el Reset)
Route::get('/db-reset-pedro', function () {
    try {
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Tablas de Reportes y Cotizaciones
        \Illuminate\Support\Facades\DB::table('final_work_reports')->truncate();
        \Illuminate\Support\Facades\DB::table('work_reports')->truncate();
        \Illuminate\Support\Facades\DB::table('quotes')->truncate();

        // Tablas de Trabajo
        \Illuminate\Support\Facades\DB::table('work_order_technician')->truncate();
        \Illuminate\Support\Facades\DB::table('service_technician')->truncate();
        \Illuminate\Support\Facades\DB::table('work_orders')->truncate();
        \Illuminate\Support\Facades\DB::table('services')->truncate();

        // Tablas de Inventario y Propiedades
        \Illuminate\Support\Facades\DB::table('property_components')->truncate();
        \Illuminate\Support\Facades\DB::table('property_categories')->truncate();
        \Illuminate\Support\Facades\DB::table('property_areas')->truncate();
        \Illuminate\Support\Facades\DB::table('properties')->truncate();

        // Notificaciones
        \Illuminate\Support\Facades\DB::table('notifications')->truncate();

        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        return response()->json([
            'status' => 'success',
            'message' => '¡Base de datos limpiada con éxito! (Modo Público)',
            'timestamp' => now()
        ]);
    } catch (\Exception $e) {
        return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
});


// Registro Privado (Exclusivo para el Admin)
Route::post('/registro-usuario', [AuthController::class, 'registro']);

// Registro Público (Exclusivo para Clientes)
Route::post('/registro-cliente', function (\Illuminate\Http\Request $request) {
    try {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|unique:users,phone_number',
        ], [
            'email.unique' => 'Este correo electrónico ya está registrado en otra cuenta.',
            'phone.unique' => 'Este número de teléfono ya está registrado en otra cuenta.'
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            // 1. Creamos el usuario para el Login
            $userId = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
                'role_id' => 3, // Rol Cliente
                'first_name' => trim($request->first_name),
                'last_name' => trim($request->last_name),
                'email' => $request->email,
                'phone_number' => $request->phone,
                'password' => Hash::make($request->password),
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Insertamos en clientes juntando el nombre para esa tabla
            \Illuminate\Support\Facades\DB::table('clients')->insert([
                'user_id' => $userId,
                'name' => trim($request->first_name . ' ' . $request->last_name),
                'email' => $request->email,
                'phone' => $request->phone,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['message' => '¡Registro exitoso, Pedro!'], 201);
        });
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

// Rutas para Personalizar Login
Route::prefix('ui/settings')->group(function () {
    Route::post('/login-background/image', [AppSettingController::class, 'updateLoginBackground']);
    Route::post('/login-background/color', [AppSettingController::class, 'updateLoginColor']);
    Route::delete('/login-background/image', [AppSettingController::class, 'deleteLoginBackground']);
    Route::get('/login-settings', [AppSettingController::class, 'getLoginSettings']);

    // --- LOGO Y FAVICON ---
    Route::post('/app-logo', [AppSettingController::class, 'updateAppLogo']);
    Route::delete('/app-logo', [AppSettingController::class, 'deleteAppLogo']);

    // --- SIDEBAR CLIENTE ---
    Route::post('/sidebar-links', [AppSettingController::class, 'updateSidebarLinks']);
    Route::get('/sidebar-links', [AppSettingController::class, 'getSidebarLinks']);
});

// Limpiar caché de Railway
Route::get('/limpiar-cache', function () {
    Artisan::call('config:clear');
    Artisan::call('cache:clear');
    Artisan::call('route:clear');
    Artisan::call('view:clear');
    return response()->json(['message' => '¡Memoria de Railway reseteada con éxito!']);
});

Route::get('/run-migrations-pago', function () {
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    return "Migraciones ejecutadas exitosamente!";
});


Route::get('/debug-quote-21', function () {
    return \App\Models\Quote::find(21);
});

// ========================================================
// 🔴 ZONA SEGURA (Solo entras si traes el Token de Sanctum)
// ========================================================

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    // --- GESTIÓN DE AUTÓNOMOS Y MULTI-TENANT ---
    Route::post('/tenants/request-membership', [TenantController::class, 'requestMembership']);
    Route::get('/tenants/my-membership-status', [TenantController::class, 'myMembershipStatus']);
    Route::get('/tenant/subscription-status', [TenantController::class, 'getSubscriptionStatus']);
    Route::get('/tenants/pending-memberships', [TenantController::class, 'pendingMemberships']);
    Route::post('/tenants/{id}/approve', [TenantController::class, 'approveTenant']);
    Route::post('/tenants/{id}/update-subscription-plan', [TenantController::class, 'updateSubscriptionPlan']);
    Route::post('/tenants/transfer-portfolio', [TenantController::class, 'transferPortfolio']);
    Route::get('/tenants/pending-technicians', [TenantController::class, 'pendingTechnicians']);
    Route::post('/tenants/approve-technician/{userId}', [TenantController::class, 'approveTechnician']);

    // --- USUARIOS Y PERFILES ---
    Route::post('/specialties', [SpecialtyController::class, 'store']);
    Route::get('/users/{id}/specialties', [SpecialtyController::class, 'getUserSpecialties']);
    Route::post('/users/{id}/specialties', [SpecialtyController::class, 'syncUserSpecialties']);
    Route::get('/usuarios', [UserController::class, 'getUsuarios']);
    Route::get('/users', [UserController::class, 'getUsuarios']);
    Route::delete('/usuarios/delete-my-account', [UserController::class, 'deleteMyAccount']);
    Route::delete('/users/delete-my-account', [UserController::class, 'deleteMyAccount']);
    Route::delete('/usuarios/{id}', [UserController::class, 'eliminarUsuario']);
    Route::delete('/users/{id}', [UserController::class, 'eliminarUsuario']);
    Route::put('/usuarios/{id}/toggle-bloqueo', [UserController::class, 'toggleBloqueo']);
    Route::put('/users/{id}/toggle-bloqueo', [UserController::class, 'toggleBloqueo']);
    Route::post('/usuarios/update-profile', [UserController::class, 'updateProfile']);
    Route::post('/users/update-profile', [UserController::class, 'updateProfile']);
    Route::put('/usuarios/{id}/rol', [UserController::class, 'updateRole']);
    Route::put('/users/{id}/rol', [UserController::class, 'updateRole']);
    Route::get('/usuarios/tecnicos', [UserController::class, 'getTecnicos']);
    Route::get('/users/tecnicos', [UserController::class, 'getTecnicos']);

    Route::post('/upload-profile-picture', [ImageController::class, 'uploadProfilePicture']);
    Route::post('/update-photos', function (\Illuminate\Http\Request $request) {
        $user = User::find($request->user_id);
        if (!$user)
            return response()->json(['error' => 'User not found'], 404);

        if ($request->hasFile('profile_picture')) {
            $profilePath = $request->file('profile_picture')->store('profiles', 'public');
            $user->profile_picture = asset('storage/' . $profilePath);
        }
        if ($request->hasFile('cover_picture')) {
            $coverPath = $request->file('cover_picture')->store('covers', 'public');
            $user->cover_picture = asset('storage/' . $coverPath);
        }
        $user->save();

        return response()->json([
            'message' => 'Photos updated successfully',
            'profile_picture' => $user->profile_picture,
            'cover_picture' => $user->cover_picture
        ]);
    });

    // --- PROPIEDADES Y MAPAS ---
    Route::get('/propiedades', [PropertyController::class, 'index']);
    Route::post('/registro-propiedad', [PropertyController::class, 'store']);
    Route::delete('/propiedades/{id}', [PropertyController::class, 'destroy']);
    Route::get('/propiedades/{id}/survey', [PropertyController::class, 'getPropertySurvey']);
    Route::get('/propiedades/{id}/dashboard', [PropertyController::class, 'getDashboardData']);

    Route::post('/propiedades/{id}/update', [PropertyController::class, 'updateProperty']);
    Route::get('/properties/by-curp/{curp}', [PropertyController::class, 'getByCurp']);
    Route::get('/properties/{id}/inventory-report', [PropertyController::class, 'getPropertyReport']);
    Route::post('/properties/{id}/finalize-survey', [PropertyController::class, 'finalizeSurvey']);

    // --- COMPARTIR PROPIEDAD (HERENCIA) ---
    Route::post('/propiedades/{id}/share', [PropertyController::class, 'shareProperty']);
    Route::delete('/propiedades/{id}/share/{clientId}', [PropertyController::class, 'revokeShare']);
    Route::get('/propiedades/{id}/shared-users', [PropertyController::class, 'getSharedUsers']);

    Route::get('/map', function () {
        $user = auth('sanctum')->user();

        $query = \Illuminate\Support\Facades\DB::table('properties')
            ->leftJoin('clients', 'properties.client_id', '=', 'clients.id')
            ->leftJoin('users', 'clients.user_id', '=', 'users.id')
            ->leftJoin('tenants as prop_tenant', 'properties.tenant_id', '=', 'prop_tenant.id')
            ->leftJoin('tenants as client_tenant', 'clients.tenant_id', '=', 'client_tenant.id')
            ->whereNotNull('properties.coordinates')
            ->where('properties.coordinates', '!=', '');

        if ($user) {
            if ($user->role_id == 4) {
                $query->where(function ($q) use ($user) {
                    $q->where('properties.tenant_id', $user->tenant_id)
                        ->orWhere('clients.tenant_id', $user->tenant_id);
                });
            } elseif ($user->role_id !== 0 && $user->tenant_id) {
                $query->where(function ($q) use ($user) {
                    $q->where('properties.tenant_id', $user->tenant_id)
                        ->orWhere('clients.tenant_id', $user->tenant_id);
                });
            } elseif ($user->role_id == 3) {
                $cliente = \Illuminate\Support\Facades\DB::table('clients')->where('user_id', $user->id)->first();
                if ($cliente) {
                    $sharedPropertyIds = \Illuminate\Support\Facades\DB::table('property_shares')->where('client_id', $cliente->id)->pluck('property_id');
                    $query->where(function ($q) use ($cliente, $sharedPropertyIds) {
                        $q->where('properties.client_id', $cliente->id)
                            ->orWhereIn('properties.id', $sharedPropertyIds);
                    });
                } else {
                    return response()->json([]);
                }
            }
        }

        $propiedades = $query->select(
            'properties.id as prop_id',
            'properties.address',
            'properties.coordinates',
            'clients.name',
            'clients.phone',
            'clients.id as client_id',
            'clients.email',
            \Illuminate\Support\Facades\DB::raw('COALESCE(users.profile_picture, clients.profile_picture) as profile_picture'),
            \Illuminate\Support\Facades\DB::raw('COALESCE(prop_tenant.logo_url, client_tenant.logo_url) as tenant_logo_url'),
            \Illuminate\Support\Facades\DB::raw('COALESCE(prop_tenant.name, client_tenant.name) as tenant_name')
        )->get();

        $marcadoresBrutos = $propiedades->map(function ($prop) {
            $partes = explode(',', $prop->coordinates);
            $fotoUrl = $prop->profile_picture ? (str_starts_with($prop->profile_picture, 'http') ? $prop->profile_picture : asset('storage/' . $prop->profile_picture)) : null;
            $tenantLogoUrl = $prop->tenant_logo_url ? (str_starts_with($prop->tenant_logo_url, 'http') ? $prop->tenant_logo_url : asset('storage/' . $prop->tenant_logo_url)) : null;

            return [
                'id' => $prop->prop_id,
                'address' => $prop->address,
                'lat' => isset($partes[0]) ? (float) trim($partes[0]) : null,
                'lng' => isset($partes[1]) ? (float) trim($partes[1]) : null,
                'owner_name' => $prop->name,
                'phone' => $prop->phone,
                'picture' => $fotoUrl,
                'client_id' => $prop->client_id,
                'email' => $prop->email,
                'tenant_logo_url' => $tenantLogoUrl,
                'tenant_name' => $prop->tenant_name
            ];
        });

        // ✅ REPARADO: Filtramos sin usar la notación de flecha que confundía a Laravel
        $marcadoresLimpios = [];
        foreach ($marcadoresBrutos as $m) {
            if ($m['lat'] !== null && $m['lng'] !== null) {
                $marcadoresLimpios[] = $m;
            }
        }

        return response()->json($marcadoresLimpios);
    });

    // --- SERVICIOS Y LEVANTAMIENTOS ---
    Route::get('/servicios', [ServiceController::class, 'index']);
    Route::post('/servicios', [ServiceController::class, 'store']);
    Route::get('/servicios/{id}', [ServiceController::class, 'show']);
    Route::put('/servicios/{id}', [ServiceController::class, 'update']);
    Route::put('/servicios/{id}/asignar', [ServiceController::class, 'assignTechnician']);
    Route::put('/servicios/{id}/asignar-trabajo', [ServiceController::class, 'assignWorkOrder']); // NUEVO

    // Rutas para Reportes de Trabajo
    Route::get('/servicios/{id}/reportes', [ServiceController::class, 'getReports']);
    Route::post('/servicios/{id}/reportes', [ServiceController::class, 'storeReport']);
    Route::put('/reportes/{id}', [ServiceController::class, 'updateReport']);
    Route::delete('/reportes/{id}', [ServiceController::class, 'deleteReport']);
    Route::post('/servicios/{id}/final-report', [ServiceController::class, 'storeFinalReport']);
    Route::get('/servicios/{id}/final-report', [ServiceController::class, 'getFinalReport']);
    Route::post('/services/assign', [ServiceController::class, 'store']);

    // --- CHECKLIST TEMPLATES ---
    Route::get('/checklist-templates', [\App\Http\Controllers\ChecklistTemplateController::class, 'index']);
    Route::post('/checklist-templates', [\App\Http\Controllers\ChecklistTemplateController::class, 'store']);
    Route::put('/servicios/{id}/confirmar-cliente', [ServiceController::class, 'confirmarCitaCliente']);
    Route::put('/servicios/{id}/solicitar-reprogramacion', [ServiceController::class, 'solicitarReprogramacion']);
    Route::post('/servicios/{id}/solicitar-segunda-visita', [ServiceController::class, 'solicitarSegundaVisita']);
    Route::post('/servicios/{id}/responder-segunda-visita', [ServiceController::class, 'responderSegundaVisita']);
    Route::post('/servicios/{id}/admin-programar-segunda-visita', [ServiceController::class, 'adminProgramarSegundaVisita']);

    Route::get('/tecnico/{id}/servicios', [ServiceController::class, 'getTecnicoServicios']);
    Route::get('/tecnico/{idTecnico}/propiedad/{idPropiedad}/servicios', [ServiceController::class, 'getServicesByProperty']);

    // --- RASTREO GPS Y CONFIRMACIÓN DE LLEGADA ---
    Route::post('/technician/update-location', [ServiceController::class, 'updateTechnicianLocation']);
    Route::post('/work-orders/{id}/confirm-arrival', [ServiceController::class, 'confirmArrival']);
    Route::get('/root/technicians-live-map', [ServiceController::class, 'getRootTechniciansLiveMap']);

    Route::post('/propiedades/servicios', [PropertyController::class, 'storeWorkOrder']);
    Route::get('/propiedades/{id}/work-orders', [PropertyController::class, 'getWorkOrders']);
    Route::put('/work-orders/{id}/status', [PropertyController::class, 'updateWorkOrderStatus']);
    Route::put('/work-orders/{id}/assign', [PropertyController::class, 'assignWorkOrder']);
    Route::put('/work-orders/batch/{batchId}/assign', [PropertyController::class, 'assignBatchWorkOrders']);
    Route::get('/work-orders/global-stats', [PropertyController::class, 'getGlobalServiceStats']);

    // --- GESTIÓN DE ZONAS (NIVEL 3 y 4) ---
    Route::post('/property-areas', [PropertyAreaController::class, 'store']);
    Route::get('/property-areas', [PropertyAreaController::class, 'index']);
    Route::get('/properties/{id}/areas', [PropertyAreaController::class, 'getByProperty']);
    Route::put('/property-areas/{id}', [PropertyAreaController::class, 'update']);
    Route::post('/property-areas/{id}/update-photo', [PropertyAreaController::class, 'updatePhoto']);
    Route::delete('/property-areas/{id}', [PropertyAreaController::class, 'destroy']);
    Route::get('/areas/{id}/subareas', [PropertyAreaController::class, 'getSubAreas']);

    // --- CATEGORÍAS Y COMPONENTES (NIVEL 5) ---
    Route::get('/areas/{id}/categories', [PropertyCategoryController::class, 'getByArea']);
    Route::post('/property-categories', [PropertyCategoryController::class, 'store']);
    Route::put('/property-categories/{id}', [PropertyCategoryController::class, 'update']);
    Route::delete('/property-categories/{id}', [PropertyCategoryController::class, 'destroy']);

    // --- CATEGORÍAS Y COMPONENTES (NIVEL 5) ---
    Route::get('/areas/{id}/components', [PropertyComponentController::class, 'getByArea']);
    Route::post('/property-components', [PropertyComponentController::class, 'store']);
    Route::delete('/property-components/{id}', [PropertyComponentController::class, 'destroy']);
    Route::put('/property-components/{id}', [PropertyComponentController::class, 'update']);
    Route::get('/properties/{propertyId}/components', [PropertyComponentController::class, 'getComponentsByProperty']);

    // --- INVENTARIOS GLOBALES ---
    Route::get('/appliances', [ApplianceController::class, 'index']);
    Route::post('/appliances', [ApplianceController::class, 'store']);
    Route::get('/catalog/summary', [PropertyComponentController::class, 'getCatalogSummary']);
    Route::get('/catalog/details', [PropertyComponentController::class, 'getCatalogDetails']);

    // --- COTIZACIONES ---
    Route::get('/cotizaciones', [QuoteController::class, 'index']);
    Route::post('/cotizaciones', [QuoteController::class, 'store']);
    Route::put('/cotizaciones/{id}/status', [QuoteController::class, 'updateStatus']);
    Route::post('/cotizaciones/{id}/update', [QuoteController::class, 'update']);
    Route::post('/cotizaciones/{id}/recotizar', [QuoteController::class, 'solicitarRecotizacion']);
    Route::post('/cotizaciones/{id}/chat', [QuoteController::class, 'addMessage']);
    Route::post('/servicios/{id}/confirmar-materiales', [QuoteController::class, 'confirmMaterials']);

    // --- NOTIFICACIONES ---
    Route::get('/notifications/unread', [NotificationController::class, 'getUnread']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::get('/notifications/all', [NotificationController::class, 'getAll']);

    ///Cotizaciones
    Route::put('/cotizaciones/{id}/observaciones', [QuoteController::class, 'updateObservations']);
    //Finalizar Cotización
    Route::post('/cotizaciones/{id}/finalizar', [QuoteController::class, 'finalizarCotizacion']);

    // Pagos de Cotizaciones
    Route::post('/cotizaciones/{id}/pago', [QuoteController::class, 'uploadPaymentReceipt']);
    Route::post('/cotizaciones/{id}/validar-pago', [QuoteController::class, 'validatePayment']);
    Route::post('/cotizaciones/{id}/mercadopago/preference', [MercadoPagoController::class, 'createPreference']);
    Route::post('/cotizaciones/batch/solicitar-efectivo', [QuoteController::class, 'solicitarEfectivoBatch']);
    Route::post('/cotizaciones/{id}/solicitar-efectivo', [QuoteController::class, 'solicitarEfectivo']);
    Route::post('/cotizaciones/{id}/confirmar-efectivo', [QuoteController::class, 'confirmarEfectivo']);
    Route::post('/cotizaciones/{id}/confirmar-efectivo-restante', [QuoteController::class, 'confirmarEfectivoRestante']);



    //Solicitar servicios
    Route::post('/work-orders/cliente', function (Request $request) {
        // 1. Validar ambos archivos (ahora los llamaremos evidence_1 y evidence_2)
        $request->validate([
            'property_id' => 'required|integer',
            'type' => 'required|string',
            'zone' => 'required|string',
            'equipment' => 'nullable|string',
            'description' => 'required|string',
            'batch_id' => 'nullable|string',
            'publish_network' => 'nullable|boolean',
            'evidence_1' => 'nullable|file|image|max:5120',
            'evidence_2' => 'nullable|file|image|max:5120'
        ]);

        // 2. Procesar las imágenes con Cloudinary
        $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));

        $path1 = null;
        if ($request->hasFile('evidence_1')) {
            $resp1 = $cloudinary->uploadApi()->upload($request->file('evidence_1')->getRealPath(), ['folder' => 'work_orders_evidences']);
            $path1 = $resp1['secure_url'];
        }

        $path2 = null;
        if ($request->hasFile('evidence_2')) {
            $resp2 = $cloudinary->uploadApi()->upload($request->file('evidence_2')->getRealPath(), ['folder' => 'work_orders_evidences']);
            $path2 = $resp2['secure_url'];
        }

        // 3. Insertar en la BD usando el Modelo Eloquent
        $workOrder = WorkOrder::create([
            'property_id' => $request->property_id,
            'type' => $request->type,
            'zone' => $request->zone,
            'equipment' => $request->equipment,
            'description' => $request->description,
            'batch_id' => $request->batch_id,
            'evidence_path' => $path1,
            'evidence_path_2' => $path2,
            'status' => 'Por Hacer',
            'priority' => $request->priority ?: ($request->type === 'SOS' ? 'Urgente' : 'Normal'),
            'scheduled_at' => $request->scheduled_at ? date('Y-m-d H:i:s', strtotime($request->scheduled_at)) : null,
            'publish_network' => filter_var($request->publish_network, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ]);

        // 4. Notificaciones (App y Correo)
        try {
            $user = $request->user();
            $userName = $user ? ($user->first_name . ' ' . $user->last_name) : 'Un cliente';

            // Usamos relaciones de Eloquent para obtener la propiedad
            $propertyName = $workOrder->property ? ($workOrder->property->nombre_propiedad ?: $workOrder->property->address) : 'Propiedad desconocida';

            // Obtenemos administradores (rol 1 y 0)
            $admins = User::whereIn('role_id', [0, 1])->get();
            \Log::info("Enviando notificación de WorkOrder. Admins encontrados: " . $admins->count());

            // Notificamos a los admins y al usuario actual para confirmar
            $notifiables = $admins->merge([$user]);

            Notification::send($notifiables, new NewWorkOrderNotification($workOrder, $userName, $propertyName));
            \Log::info("Notificación enviada correctamente vía Eloquent.");
        } catch (\Exception $e) {
            \Log::error("Error enviando notificación de WorkOrder: " . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Servicio solicitado con éxito'
        ], 201);
    });

    // Aceptar Cotización de la Red
    Route::post('/network-quotes/{id}/accept', function ($id) {
        try {
            $quote = \App\Models\NetworkQuote::withoutGlobalScopes()->with(['technician', 'workOrder'])->find($id);
            if (!$quote) {
                return response()->json(['success' => false, 'message' => 'Cotización no encontrada'], 404);
            }

            $quote->status = 'accepted';
            $quote->save();

            $workOrder = $quote->workOrder ?: \App\Models\WorkOrder::withoutGlobalScopes()->find($quote->work_order_id);

            if ($workOrder) {
                $workOrder->tecnico_id = $quote->technician_id;
                $workOrder->status = 'Asignado';
                $workOrder->save();

                try {
                    \Illuminate\Support\Facades\DB::table('work_order_technician')->insertOrIgnore([
                        'work_order_id' => $workOrder->id,
                        'technician_id' => $quote->technician_id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning("No se pudo insertar en work_order_technician: " . $e->getMessage());
                }

                $title = $workOrder->type . ($workOrder->equipment ? ' - ' . $workOrder->equipment : '');
                $techName = $quote->technician ? ($quote->technician->first_name . ' ' . $quote->technician->last_name) : 'Técnico';

                // 1. Notificar al Técnico
                try {
                    if ($quote->technician) {
                        $quote->technician->notify(new \App\Notifications\NetworkQuoteAccepted($quote, $title));
                    }
                } catch (\Throwable $e) {
                    \Log::error("Error notificando al técnico: " . $e->getMessage());
                }

                // 2. Notificar al Cliente/Autónomo
                try {
                    $user = auth('sanctum')->user();
                    if (!$user && $workOrder->tenant_id) {
                        $user = \App\Models\User::withoutGlobalScopes()->where('tenant_id', $workOrder->tenant_id)->first();
                    }
                    if ($user) {
                        $user->notify(new \App\Notifications\NetworkQuoteAcceptedClient($quote, $techName, $title));
                    }
                } catch (\Throwable $e) {
                    \Log::error("Error notificando al cliente: " . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => '¡Cotización aceptada con éxito! El trabajo ha sido asignado al técnico.'
            ]);
        } catch (\Throwable $e) {
            \Log::error("Error aceptando cotización: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error interno al aceptar cotización: ' . $e->getMessage()
            ], 500);
        }
    });

    // Rechazar Cotización de la Red
    Route::post('/network-quotes/{id}/reject', function ($id) {
        $quote = \App\Models\NetworkQuote::withoutGlobalScopes()->find($id);
        if (!$quote) {
            return response()->json(['message' => 'Cotización no encontrada'], 404);
        }

        $quote->status = 'rejected';
        $quote->save();

        if ($quote->technician) {
            $quote->technician->notify(new \App\Notifications\NetworkQuoteRejected($quote));
        }

        return response()->json(['success' => true, 'message' => 'Cotización rechazada exitosamente']);
    });

    // Obtener historial de chat de una Cotización de la Red
    Route::get('/network-quotes/{id}/chat', function ($id) {
        $quote = \App\Models\NetworkQuote::withoutGlobalScopes()->with(['technician', 'workOrder.property.client'])->find($id);
        if (!$quote) {
            return response()->json(['message' => 'Cotización no encontrada'], 404);
        }
        return response()->json([
            'success' => true,
            'chat_history' => $quote->chat_history ?? [],
            'quote' => $quote
        ]);
    });

    // Enviar mensaje en el chat de una Cotización de la Red
    Route::post('/network-quotes/{id}/chat', function (\Illuminate\Http\Request $request, $id) {
        $request->validate([
            'message' => 'required|string'
        ]);

        $quote = \App\Models\NetworkQuote::withoutGlobalScopes()->with(['workOrder.property.client', 'technician'])->find($id);
        if (!$quote) {
            return response()->json(['message' => 'Cotización no encontrada'], 404);
        }

        $user = auth('sanctum')->user() ?: auth()->user();
        if (!$user) {
            return response()->json(['message' => 'No autorizado'], 401);
        }

        $senderRole = 'Usuario';
        if (in_array((int)$user->role_id, [3, 4, 5, 7]))
            $senderRole = 'Cliente';
        elseif (in_array((int)$user->role_id, [2, 6, 8]))
            $senderRole = 'Técnico de la Red';
        elseif (in_array((int)$user->role_id, [0, 1]))
            $senderRole = 'Admin';

        $newMessage = [
            'sender_id' => $user->id,
            'sender_name' => $user->name ?: ($user->first_name . ' ' . $user->last_name),
            'sender_role' => $senderRole,
            'message' => $request->message,
            'created_at' => now()->toIso8601String(),
        ];

        $history = $quote->chat_history ?? [];
        $history[] = $newMessage;
        $quote->chat_history = $history;
        $quote->save();

        $senderNameStr = $user->name ?: ($user->first_name . ' ' . $user->last_name);

        // Disparar Notificaciones
        if (in_array((int)$user->role_id, [2, 6, 8])) {
            // El técnico envió el mensaje -> notificar al Cliente / Autónomo dueño del reporte
            $clientUser = null;
            if ($quote->workOrder?->property?->client?->user_id) {
                $clientUser = \App\Models\User::withoutGlobalScopes()->find($quote->workOrder->property->client->user_id);
            }
            if (!$clientUser && $quote->workOrder?->tenant_id) {
                $clientUser = \App\Models\User::withoutGlobalScopes()->where('tenant_id', $quote->workOrder->tenant_id)->first();
            }
            if ($clientUser) {
                \Illuminate\Support\Facades\Notification::send($clientUser, new \App\Notifications\NewNetworkQuoteChatMessageNotification($quote, $senderNameStr, 'Técnico'));
            }
        } else {
            // El Cliente / Autónomo envió el mensaje -> notificar al Técnico
            if ($quote->technician) {
                \Illuminate\Support\Facades\Notification::send($quote->technician, new \App\Notifications\NewNetworkQuoteChatMessageNotification($quote, $senderNameStr, $senderRole));
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Mensaje enviado',
            'chat_history' => $history
        ]);
    });

    // Enviar una cotización a un trabajo de la red
    Route::post('/mercado-trabajos/{id}/cotizar', function (Request $request, $id) {
        $request->validate([
            'price' => 'required|numeric|min:0',
            'message' => 'nullable|string'
        ]);

        $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->with('property.client')->findOrFail($id);
        $user = auth('sanctum')->user();

        $quote = \App\Models\NetworkQuote::updateOrCreate(
            [
                'work_order_id' => $workOrder->id,
                'technician_id' => $user->id,
            ],
            [
                'price' => $request->price,
                'message' => $request->message,
                'status' => 'pending'
            ]
        );

        // Notificar al dueño de la orden de trabajo (el Autónomo o Cliente)
        try {
            $owner = null;
            if ($workOrder->property && $workOrder->property->client && $workOrder->property->client->user_id) {
                $owner = \App\Models\User::withoutGlobalScopes()->find($workOrder->property->client->user_id);
            }
            if (!$owner && $workOrder->tenant_id) {
                $owner = \App\Models\User::withoutGlobalScopes()->where('tenant_id', $workOrder->tenant_id)->first();
            }
            if ($owner) {
                $techName = trim("{$user->first_name} {$user->last_name}") ?: $user->name;
                $propName = $workOrder->property ? ($workOrder->property->property_name ?: $workOrder->property->address) : 'Propiedad';
                \Illuminate\Support\Facades\Notification::send($owner, new \App\Notifications\NetworkQuoteReceived($quote, $techName, $propName));
            }
        } catch (\Throwable $e) {
            \Log::error("Error enviando notificación de cotización: " . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Cotización enviada con éxito',
            'quote' => $quote
        ]);
    })->middleware('auth:sanctum');

    // Iniciar o recuperar chat directo entre Técnico y Cliente para un trabajo
    Route::post('/mercado-trabajos/{id}/iniciar-chat', function ($id) {
        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
            }

            $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->with(['property.client'])->findOrFail($id);

            // Buscar si ya existe una cotización / chat de este técnico para este trabajo
            $quote = \App\Models\NetworkQuote::withoutGlobalScopes()
                ->where('work_order_id', $workOrder->id)
                ->where('technician_id', $user->id)
                ->first();

            if (!$quote) {
                $quote = \App\Models\NetworkQuote::create([
                    'work_order_id' => $workOrder->id,
                    'technician_id' => $user->id,
                    'price' => 0,
                    'message' => 'Chat iniciado por el técnico',
                    'status' => 'pending',
                    'chat_history' => []
                ]);
            }

            return response()->json([
                'success' => true,
                'quote' => $quote
            ]);
        } catch (\Throwable $e) {
            \Log::error("Error en iniciar-chat: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    })->middleware('auth:sanctum');

    // Programar hora estimada de visita / llegada del Técnico
    Route::post('/mercado-trabajos/{id}/programar-visita', function (Request $request, $id) {
        $request->validate([
            'scheduled_at' => 'required|string',
            'notes' => 'nullable|string'
        ]);

        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
            }

            $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->with(['property.client', 'networkQuotes'])->findOrFail($id);
            $scheduledDateTime = date('Y-m-d H:i:s', strtotime($request->scheduled_at));
            $workOrder->scheduled_at = $scheduledDateTime;
            $workOrder->save();

            // Buscar la cotización aceptada o del técnico para registrar en el chat
            $quote = \App\Models\NetworkQuote::withoutGlobalScopes()
                ->where('work_order_id', $workOrder->id)
                ->where('technician_id', $user->id)
                ->first();

            $formattedTime = date('d/m/Y \a \l\a\s h:i A', strtotime($scheduledDateTime));
            $techName = $user->first_name ? "{$user->first_name} {$user->last_name}" : ($user->name ?: 'El Técnico');
            $msgText = "📅 {$techName} ha programado la visita para el {$formattedTime}." . ($request->notes ? " Nota: \"{$request->notes}\"" : "");

            if ($quote) {
                $history = $quote->chat_history ?? [];
                $history[] = [
                    'sender_id' => $user->id,
                    'sender_name' => $techName,
                    'sender_role' => 'Técnico de la Red',
                    'message' => $msgText,
                    'is_schedule' => true,
                    'scheduled_at' => $scheduledDateTime,
                    'schedule_status' => 'pending_confirmation',
                    'created_at' => now()->toIso8601String(),
                ];
                $quote->chat_history = $history;
                $quote->save();
            }

            // Notificar al Cliente / Autónomo dueño de la publicación
            try {
                $clientUsers = collect();
                // 1. Por el cliente asociado a la propiedad
                if ($workOrder->property?->client?->user_id) {
                    $u = \App\Models\User::withoutGlobalScopes()->find($workOrder->property->client->user_id);
                    if ($u) $clientUsers->push($u);
                }
                // 2. Por el email del cliente
                if ($workOrder->property?->client?->email) {
                    $u = \App\Models\User::withoutGlobalScopes()->where('email', $workOrder->property->client->email)->first();
                    if ($u) $clientUsers->push($u);
                }
                // 3. Por el tenant de la orden o propiedad
                $tenantId = $workOrder->tenant_id ?: ($workOrder->property?->tenant_id ?? null);
                if ($tenantId) {
                    $tenantUsers = \App\Models\User::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->whereIn('role_id', [0, 1, 3, 4, 5, 7])
                        ->get();
                    foreach ($tenantUsers as $tu) {
                        $clientUsers->push($tu);
                    }
                }

                $clientUsers = $clientUsers->unique('id');

                $title = $workOrder->type . ($workOrder->equipment ? ' - ' . $workOrder->equipment : '');
                $propName = $workOrder->property ? ($workOrder->property->nombre_propiedad ?: $workOrder->property->address) : 'Propiedad';

                foreach ($clientUsers as $targetClient) {
                    if ($quote) {
                        $targetClient->notify(new \App\Notifications\WorkOrderScheduledNotification($workOrder, $techName, $propName));
                        $targetClient->notify(new \App\Notifications\NewNetworkQuoteChatMessageNotification($quote, $techName, 'Técnico'));
                    }
                }
            } catch (\Throwable $e) {
                \Log::error("Error notificando fecha programada: " . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => "Visita programada para el {$formattedTime} correctamente.",
                'scheduled_at' => $scheduledDateTime,
                'chat_history' => $quote?->chat_history ?? []
            ]);
        } catch (\Throwable $e) {
            \Log::error("Error programando visita: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    })->middleware('auth:sanctum');

    // Responder (Aceptar o Re-coordinar) horario propuesto por el técnico
    Route::post('/mercado-trabajos/{id}/responder-visita-cliente', function (Request $request, $id) {
        $request->validate([
            'action' => 'required|in:confirm,reschedule',
            'message' => 'nullable|string'
        ]);

        try {
            $user = auth('sanctum')->user();
            $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->with(['property.client', 'networkQuotes.technician'])->findOrFail($id);

            $quote = \App\Models\NetworkQuote::withoutGlobalScopes()
                ->where('work_order_id', $workOrder->id)
                ->where('status', 'accepted')
                ->first();

            if (!$quote) {
                $quote = \App\Models\NetworkQuote::withoutGlobalScopes()
                    ->where('work_order_id', $workOrder->id)
                    ->orderBy('id', 'desc')
                    ->first();
            }

            $isConfirmed = $request->action === 'confirm';
            $clientName = $user ? (trim("{$user->first_name} {$user->last_name}") ?: $user->name) : 'El Cliente';

            $msgText = $isConfirmed
                ? "✅ {$clientName} CONFIRMÓ el horario de visita propuesto."
                : "⚠️ {$clientName} solicita re-coordinar el horario de visita: " . ($request->message ? "\"{$request->message}\"" : "Por favor acuerden un nuevo horario por chat.");

            if ($quote) {
                $history = $quote->chat_history ?? [];
                $history[] = [
                    'sender_id' => $user ? $user->id : 0,
                    'sender_name' => $clientName,
                    'sender_role' => 'Cliente',
                    'message' => $msgText,
                    'is_schedule_response' => true,
                    'schedule_confirmed' => $isConfirmed,
                    'created_at' => now()->toIso8601String(),
                ];
                $quote->chat_history = $history;
                $quote->save();

                // Notificar al Técnico
                if ($quote->technician) {
                    \Illuminate\Support\Facades\Notification::send($quote->technician, new \App\Notifications\NewNetworkQuoteChatMessageNotification($quote, $clientName, 'Cliente'));
                }
            }

            return response()->json([
                'success' => true,
                'message' => $isConfirmed ? 'Horario confirmado correctamente.' : 'Solicitud de reprogramación enviada.',
                'chat_history' => $quote?->chat_history ?? []
            ]);
        } catch (\Throwable $e) {
            \Log::error("Error respondiendo horario: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    })->middleware('auth:sanctum');

    // Eliminar / Cancelar publicación de servicio en la Red (Solo el Autor o Admin)
    Route::delete('/mercado-trabajos/{id}', function ($id) {
        try {
            $user = auth('sanctum')->user();
            $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->with('property.client')->find($id);

            if (!$workOrder) {
                // Verificar si existe en la tabla services
                $service = \App\Models\Service::withoutGlobalScopes()->with('property.client')->find($id);
                if ($service) {
                    // Validar permisos: solo autor o admin
                    $isOwner = false;
                    if ($user) {
                        if (in_array($user->role_id, [0, 1])) $isOwner = true;
                        elseif ($user->tenant_id && ($service->tenant_id == $user->tenant_id || $service->property?->tenant_id == $user->tenant_id)) $isOwner = true;
                        elseif ($service->property?->client?->user_id == $user->id || $service->client_id == $user->id) $isOwner = true;
                    }
                    if (!$isOwner && $user) {
                        return response()->json(['success' => false, 'message' => 'No puedes eliminar esta publicación porque fue creada por otro usuario.'], 403);
                    }

                    \App\Models\NetworkQuote::withoutGlobalScopes()->where('work_order_id', $service->id)->delete();
                    $service->delete();
                    return response()->json([
                        'success' => true,
                        'message' => 'Publicación eliminada correctamente de la Red.'
                    ]);
                }
                return response()->json(['success' => false, 'message' => 'Publicación no encontrada.'], 404);
            }

            // Validar permisos: solo autor o admin
            $isOwner = false;
            if ($user) {
                if (in_array($user->role_id, [0, 1])) $isOwner = true;
                elseif ($user->tenant_id && ($workOrder->tenant_id == $user->tenant_id || $workOrder->property?->tenant_id == $user->tenant_id)) $isOwner = true;
                elseif ($workOrder->property?->client?->user_id == $user->id || $workOrder->client_id == $user->id) $isOwner = true;
            }
            if (!$isOwner && $user) {
                return response()->json(['success' => false, 'message' => 'No puedes eliminar esta publicación porque fue creada por otro usuario.'], 403);
            }

            // Eliminar cotizaciones de la red asociadas y relaciones
            \App\Models\NetworkQuote::withoutGlobalScopes()->where('work_order_id', $workOrder->id)->delete();
            \Illuminate\Support\Facades\DB::table('work_order_technician')->where('work_order_id', $workOrder->id)->delete();
            $workOrder->delete();

            return response()->json([
                'success' => true,
                'message' => 'Publicación eliminada y cancelada con éxito.'
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    });

    Route::delete('/work-orders/reset-all-services', function () {
        $user = auth('sanctum')->user();

        try {
            if ($user && $user->tenant_id) {
                \App\Models\WorkReport::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->delete();
                \App\Models\NetworkQuote::withoutGlobalScopes()->whereHas('workOrder', function ($q) use ($user) {
                    $q->where('tenant_id', $user->tenant_id);
                })->delete();
                \App\Models\WorkOrder::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->delete();
                \App\Models\Service::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->delete();
            } else {
                \App\Models\WorkReport::withoutGlobalScopes()->delete();
                \App\Models\NetworkQuote::withoutGlobalScopes()->delete();
                \App\Models\WorkOrder::withoutGlobalScopes()->delete();
                \App\Models\Service::withoutGlobalScopes()->delete();
            }
            \Illuminate\Support\Facades\DB::table('work_order_technician')->delete();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Todos los servicios han sido borrados de la base de datos con éxito.'
        ]);
    });

    Route::get('/work-orders/all', function () {
        $user = auth('sanctum')->user();
        $query = \App\Models\WorkOrder::withoutGlobalScopes()->with([
            'property.client',
            'tecnico' => fn($q) => $q->withoutGlobalScopes(),
            'technicians' => fn($q) => $q->withoutGlobalScopes(),
            'networkQuotes' => fn($q) => $q->withoutGlobalScopes(),
            'networkQuotes.technician' => fn($q) => $q->withoutGlobalScopes()
        ]);

        if ($user && $user->role_id == 4) {
            $query->where(function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id)
                    ->orWhereHas('property', function ($qp) use ($user) {
                        $qp->where('tenant_id', $user->tenant_id);
                    })
                    ->orWhereHas('tecnico', function ($qt) use ($user) {
                        $qt->where('tenant_id', $user->tenant_id);
                    });
            });
        } elseif ($user && $user->role_id !== 0 && $user->tenant_id) {
            $query->where(function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id)
                    ->orWhereHas('property', function ($qp) use ($user) {
                        $qp->where('tenant_id', $user->tenant_id);
                    });
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    });

    // Nuevo: Obtener todos los reportes globales (Galería Global de Administradores)
    Route::get('/reportes-globales', function () {
        $user = auth('sanctum')->user();
        $query = \App\Models\WorkReport::with([
            'technician:id,first_name,last_name,profile_picture,tenant_id',
            'service.property.client',
            'workOrder.property.client'
        ]);

        if ($user && $user->role_id == 4) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('technician', function ($qt) use ($user) {
                    $qt->where('tenant_id', $user->tenant_id)->orWhere('id', $user->id);
                })->orWhereHas('service', function ($qs) use ($user) {
                    $qs->where('tenant_id', $user->tenant_id);
                })->orWhereHas('workOrder', function ($qw) use ($user) {
                    $qw->where('tenant_id', $user->tenant_id);
                });
            });
        } elseif ($user && $user->role_id !== 0 && $user->tenant_id) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('technician', function ($qt) use ($user) {
                    $qt->where('tenant_id', $user->tenant_id);
                })->orWhereHas('service', function ($qs) use ($user) {
                    $qs->where('tenant_id', $user->tenant_id);
                });
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    });

    // --- NUEVOS ROLES: ADMINISTRADOR DE PROPIEDADES ---
    Route::post('/property-managers/link-by-code', [PropertyManagerController::class, 'linkByCode']);
    Route::post('/property-managers/unlink', [PropertyManagerController::class, 'unlink']);
    Route::get('/property-managers/my-manager', [PropertyManagerController::class, 'getMyManager']);
    Route::get('/property-managers/my-status', [PropertyManagerController::class, 'getMyStatus']);
    Route::post('/property-managers/assign-properties', [PropertyManagerController::class, 'assignProperties']);
});

