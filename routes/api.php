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
use App\Http\Controllers\JobRequestController;
use App\Http\Controllers\JobQuoteController;
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
        if ($user->role_id == 3)
            $senderRole = 'Cliente';
        elseif ($user->role_id == 4)
            $senderRole = 'Autónomo';
        elseif (in_array($user->role_id, [2, 8]))
            $senderRole = 'Técnico de la Red';
        elseif (in_array($user->role_id, [0, 1]))
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
        if (in_array($user->role_id, [2, 8])) {
            // El técnico envió el mensaje -> notificar al Cliente / Autónomo dueño del reporte
            $clientUserId = $quote->workOrder?->tenant_id ?: ($quote->workOrder?->property?->client?->user_id ?? null);
            if ($clientUserId) {
                $clientUser = \App\Models\User::find($clientUserId);
                if ($clientUser) {
                    \Illuminate\Support\Facades\Notification::send($clientUser, new \App\Notifications\NewNetworkQuoteChatMessageNotification($quote, $senderNameStr, 'Técnico'));
                }
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

    // Nuevo Endpoint para el Mercado de Trabajos (Trabajos Públicos en la Red)
    Route::get('/mercado-trabajos', function (\Illuminate\Http\Request $request) {
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
            ->where('publish_network', 1)
            ->where('status', 'Por Hacer');

        // Si se pide filtrar solo los del usuario autónomo o si el usuario autenticado es un Autónomo/Cliente (role_id 3, 4, 5)
        if ($authUser && ($request->boolean('only_mine') || in_array((int)$authUser->role_id, [3, 4, 5]))) {
            if (!in_array((int)$authUser->role_id, [0, 1])) { // SuperAdmin puede ver todos
                $query->where(function ($q) use ($authUser) {
                    $q->where('user_id', $authUser->id)
                      ->orWhere('client_id', $authUser->id)
                      ->orWhereHas('property.client', function ($qc) use ($authUser) {
                          $qc->where('user_id', $authUser->id);
                      });
                    if (!empty($authUser->tenant_id)) {
                        $q->orWhere('tenant_id', $authUser->tenant_id)
                          ->orWhereHas('property', function ($qp) use ($authUser) {
                              $qp->where('tenant_id', $authUser->tenant_id);
                          });
                    }
                });
            }
        }

        $jobs = $query->orderBy('created_at', 'desc')->get();

        $jobs->transform(function ($job) use ($authUser) {
            $ownerName = '';
            $ownerUserId = null;
            $ownerTenantId = $job->tenant_id ?: null;

            if ($job->property && $job->property->client) {
                $ownerName = trim($job->property->client->first_name . ' ' . $job->property->client->last_name);
                $ownerUserId = $job->property->client->user_id;
            }
            if (empty($ownerName) && $job->tenant_id) {
                $owner = \App\Models\User::withoutGlobalScopes()
                    ->where('tenant_id', $job->tenant_id)
                    ->orWhere('id', $job->tenant_id)
                    ->first();
                if ($owner) {
                    $ownerName = trim("{$owner->first_name} {$owner->last_name}") ?: $owner->name;
                    $ownerUserId = $owner->id;
                }
            }
            if (empty($ownerName) && $job->property && $job->property->tenant_id) {
                $owner = \App\Models\User::withoutGlobalScopes()
                    ->where('tenant_id', $job->property->tenant_id)
                    ->orWhere('id', $job->property->tenant_id)
                    ->first();
                if ($owner) {
                    $ownerName = trim("{$owner->first_name} {$owner->last_name}") ?: $owner->name;
                    $ownerUserId = $owner->id;
                }
            }
            $job->owner_name = $ownerName ?: 'Cliente de la Red';
            $job->owner_user_id = $ownerUserId ?: ($job->user_id ?? null);
            $job->owner_tenant_id = $ownerTenantId ?: ($job->property?->tenant_id ?? null);

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

            // Extracción de Colonia / Fraccionamiento / Zona
            $fullAddress = $job->property ? ($job->property->address ?: '') : '';
            $zonaColonia = 'Mérida, Yucatán';

            if (!empty($job->zone) && !in_array(strtolower($job->zone), ['general', 'n/a', ''])) {
                $zonaColonia = $job->zone;
            } elseif (!empty($fullAddress)) {
                if (preg_match('/(?:Col\.|Colonia|Fracc\.|Fraccionamiento)\s*([^,]+)/i', $fullAddress, $matches)) {
                    $zonaColonia = trim($matches[0]);
                    if (preg_match('/(M[eé]rida|Um[aá]n|Kanas[ií]n|Progreso|Conkal)/i', $fullAddress, $cityMatches)) {
                        $zonaColonia .= ', ' . $cityMatches[1];
                    }
                } else {
                    $parts = array_filter(array_map('trim', explode(',', $fullAddress)));
                    if (count($parts) >= 2) {
                        $zonaColonia = implode(', ', array_slice($parts, -2));
                    } else {
                        $zonaColonia = $fullAddress;
                    }
                }
            } elseif ($job->property && !empty($job->property->property_name)) {
                $zonaColonia = $job->property->property_name;
            }

            $job->zona_colonia = $zonaColonia;
            $job->zona = $zonaColonia;
            $job->area_name = $zonaColonia;

            $isMine = false;
            if ($authUser) {
                if (in_array((int)$authUser->role_id, [0, 1])) {
                    $isMine = true;
                } elseif ($authUser->tenant_id && ($job->tenant_id == $authUser->tenant_id || $job->property?->tenant_id == $authUser->tenant_id)) {
                    $isMine = true;
                } elseif ($job->property?->client?->user_id == $authUser->id || $job->client_id == $authUser->id || $job->user_id == $authUser->id) {
                    $isMine = true;
                }
            }
            $job->is_mine = $isMine;

            return $job;
        });

        return response()->json([
            'success' => true,
            'data' => $jobs
        ]);
    });

    // Enviar una cotización a un trabajo de la red
    Route::post('/mercado-trabajos/{id}/cotizar', function (Request $request, $id) {
        $request->validate([
            'price' => 'required|numeric|min:0',
            'message' => 'nullable|string'
        ]);

        $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->findOrFail($id);
        $user = auth('sanctum')->user();

        $quote = \App\Models\NetworkQuote::create([
            'work_order_id' => $workOrder->id,
            'technician_id' => $user->id,
            'price' => $request->price,
            'message' => $request->message,
            'status' => 'pending'
        ]);

        // Notificar al dueño de la orden de trabajo (el Autónomo o Cliente)
        try {
            $owner = \App\Models\User::withoutGlobalScopes()->where('tenant_id', $workOrder->tenant_id)->first();
            if ($owner) {
                $techName = $user->first_name . ' ' . $user->last_name;
                $propName = $workOrder->property ? ($workOrder->property->property_name ?: $workOrder->property->address) : 'Propiedad';
                \Illuminate\Support\Facades\Notification::send($owner, new \App\Notifications\NetworkQuoteReceived($quote, $techName, $propName));
            }
        } catch (\Exception $e) {
            \Log::error("Error enviando notificación de cotización: " . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Cotización enviada con éxito'
        ]);
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

    // --- NUEVOS ROLES: CONTRATISTA (Marketplace interno) ---
    Route::get('/job-requests', [JobRequestController::class, 'availableForTechnician']);
    Route::post('/job-requests', [JobRequestController::class, 'store']);
    Route::get('/job-requests/my-requests', [JobRequestController::class, 'myRequests']);
    Route::get('/job-requests/{id}', [JobRequestController::class, 'show']);
    Route::post('/job-requests/{id}/select-quote', [JobRequestController::class, 'selectQuote']);
    Route::post('/job-requests/{id}/quotes', [JobQuoteController::class, 'store']);
    Route::get('/job-quotes/my-quotes', [JobQuoteController::class, 'myQuotes']);
});

