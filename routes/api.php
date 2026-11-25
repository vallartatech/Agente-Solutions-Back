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
use App\Http\Controllers\TechnicianReviewController;
use App\Http\Controllers\AccesoQrController;

// ========================================================
// 🟢 ZONA PÚBLICA (Sin Token - Cualquiera puede entrar)
// ========================================================

Route::post('/login', [AuthController::class, 'login']);
Route::post('/login/google', [AuthController::class, 'loginGoogle'])->middleware('throttle:20,1');
Route::post('/login/apple', [AuthController::class, 'loginApple'])->middleware('throttle:20,1');
Route::post('/registro/identidad-social', [AuthController::class, 'identidadRegistroSocial'])->middleware('throttle:20,1');

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
// Registro del técnico de cuadrilla: verificar el código del contratista (escrito o leído del QR)
Route::get('/cuadrilla/verificar-codigo', [\App\Http\Controllers\CuadrillaController::class, 'verificarCodigo'])->middleware('throttle:30,1');

// Calificaciones de Técnicos (Dual-Factor: Relojes ⏱️ y Estrellas ⭐)
Route::post('/technician-reviews', function (\Illuminate\Http\Request $request) {
    $validated = $request->validate([
        'technician_id' => 'required|exists:users,id',
        'rating_stars'  => 'nullable|numeric|min:1|max:5',
        'rating_time'   => 'required|numeric|min:1|max:5',
        'comment'       => 'nullable|string|max:1000',
        'work_order_id' => 'nullable|integer',
        'service_id'    => 'nullable|integer',
    ]);

    $authUser = auth('sanctum')->user() ?: $request->user();
    $clientId = $authUser ? $authUser->id : $request->input('client_id');
    $technicianId = (int) $validated['technician_id'];
    $ratingStars = !empty($validated['rating_stars']) ? (float)$validated['rating_stars'] : 5.0;

    $scheduledAt = null;
    $arrivedAt = null;
    $delayMinutes = null;

    if (!empty($validated['work_order_id'])) {
        $wo = \App\Models\WorkOrder::withoutGlobalScopes()->with('property.client')->find($validated['work_order_id']);
        if ($wo) {
            $scheduledAt = $wo->scheduled_at;
            $arrivedAt = $wo->arrived_at;
            if (!$clientId && $wo->property?->client?->user_id) {
                $clientId = $wo->property->client->user_id;
            }
            if (!$clientId && $wo->tenant_id) {
                $firstUser = \App\Models\User::withoutGlobalScopes()->where('tenant_id', $wo->tenant_id)->value('id');
                if ($firstUser) $clientId = $firstUser;
            }
        }
    } elseif (!empty($validated['service_id'])) {
        $serv = \App\Models\Service::withoutGlobalScopes()->with('property.client')->find($validated['service_id']);
        if ($serv) {
            $scheduledAt = $serv->scheduled_at ?? $serv->created_at;
            $arrivedAt = $serv->arrived_at;
            if (!$clientId && $serv->property?->client?->user_id) {
                $clientId = $serv->property->client->user_id;
            }
        }
    }

    // Asegurar que $clientId exista en la tabla users para evitar violación de Foreign Key
    if (!$clientId || !\App\Models\User::withoutGlobalScopes()->where('id', $clientId)->exists()) {
        $clientId = \App\Models\User::withoutGlobalScopes()->value('id');
    }

    if ($scheduledAt && $arrivedAt) {
        try {
            $schedTime = \Carbon\Carbon::parse($scheduledAt);
            $arrTime = \Carbon\Carbon::parse($arrivedAt);
            $delayMinutes = (int) $schedTime->diffInMinutes($arrTime, false);
        } catch (\Exception $e) {
            $delayMinutes = null;
        }
    }

    $reviewQuery = \App\Models\TechnicianReview::where('client_id', $clientId)
        ->where('technician_id', $technicianId);

    if (!empty($validated['work_order_id'])) {
        $reviewQuery->where('work_order_id', $validated['work_order_id']);
    } elseif (!empty($validated['service_id'])) {
        $reviewQuery->where('service_id', $validated['service_id']);
    }

    $review = $reviewQuery->first();

    if ($review) {
        $review->update([
            'rating_stars'  => $ratingStars,
            'rating_time'   => $validated['rating_time'],
            'comment'       => $validated['comment'] ?? $review->comment,
            'scheduled_at'  => $scheduledAt ?? $review->scheduled_at,
            'arrived_at'    => $arrivedAt ?? $review->arrived_at,
            'delay_minutes' => $delayMinutes ?? $review->delay_minutes,
        ]);
    } else {
        $review = \App\Models\TechnicianReview::create([
            'technician_id' => $technicianId,
            'client_id'     => $clientId,
            'work_order_id' => $validated['work_order_id'] ?? null,
            'service_id'    => $validated['service_id'] ?? null,
            'rating_stars'  => $ratingStars,
            'rating_time'   => $validated['rating_time'],
            'comment'       => $validated['comment'] ?? null,
            'scheduled_at'  => $scheduledAt,
            'arrived_at'    => $arrivedAt,
            'delay_minutes' => $delayMinutes,
        ]);
    }

    // Marcar como calificado para que nunca vuelva a aparecer la encuesta
    if (!empty($validated['work_order_id'])) {
        \Illuminate\Support\Facades\DB::table('technician_cancellations')
            ->where('work_order_id', $validated['work_order_id'])
            ->update(['rated' => true, 'updated_at' => now()]);
    }

    $technician = \App\Models\User::withoutGlobalScopes()->find($technicianId);
    if ($technician) {
        $technician->recalculateRatings();
    }

    return response()->json([
        'status' => 'success',
        'message' => '¡Calificación registrada exitosamente!',
        'review' => $review,
        'technician_stats' => [
            'rating_stars_avg'    => $technician ? $technician->rating_stars_avg : 5.00,
            'rating_time_avg'     => $technician ? $technician->rating_time_avg : 5.00,
            'total_reviews_count' => $technician ? $technician->total_reviews_count : 1,
        ]
    ], 201);
});
Route::get('/technicians/{id}/reviews', [TechnicianReviewController::class, 'getTechnicianReviews']);
Route::get('/technician-reviews/status', [TechnicianReviewController::class, 'checkReviewStatus']);

// QR del técnico de la red: perfil público sin sesión (el QR impreso se escanea con cualquier cámara)
Route::get('/red/qr/{codigo}', [\App\Http\Controllers\TecnicoQrController::class, 'porCodigo'])->where('codigo', '[A-Za-z0-9]{6,20}');

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
            // Habilidad que necesita el trabajo: { id, name, icon } (null = "No estoy seguro")
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('work_orders', 'specialty_id'), fn ($q) => $q->with('specialty:id,name,icon'))
            ->withCount('networkQuotes')
            ->where('publish_network', 1);

        // Si se pide filtrar solo los del usuario autónomo o cliente (role_id 3, 4, 5, 7) con only_mine
        if ($request->boolean('only_mine')) {
            // con_terminados=1 (Mercado del cliente): también los terminados, para ver el reporte, confirmarlo y la cotización final
            $query->whereIn('status', array_merge(
                ['Por Hacer', 'Asignado', 'En Progreso', 'Cancelado_Tecnico', 'Cancelado_Cliente'],
                $request->boolean('con_terminados') ? ['Listo', 'Por Aprobar', 'Finalizado', 'Terminado', 'Completado'] : []
            ));
            if ($authUser && !in_array((int)$authUser->role_id, [0, 1])) { // SuperAdmin / Root puede ver todos
                $query->where(function ($q) use ($authUser) {
                    // Dueño por user_id o correo. Antes también por "nombre parecido" al primer nombre del usuario,
                    // y un "Jorge" veía los trabajos de cualquier otro cliente llamado Jorge.
                    $q->whereHas('property.client', function ($qc) use ($authUser) {
                        $qc->withoutGlobalScopes()
                           ->where(function ($c) use ($authUser) {
                               $c->where('user_id', $authUser->id);
                               if (!empty($authUser->email)) {
                                   $c->orWhere('email', $authUser->email);
                               }
                           });
                    });

                    if (!empty($authUser->tenant_id)) {
                        $q->orWhere('tenant_id', $authUser->tenant_id)
                          ->orWhereHas('property', function ($qp) use ($authUser) {
                              $qp->withoutGlobalScopes()->where('tenant_id', $authUser->tenant_id);
                          });
                    }

                    // Propiedades que le compartieron: las usa como dueño
                    $compartidas = \App\Services\AccesoPropiedad::compartidasCon($authUser);
                    if (!empty($compartidas)) {
                        $q->orWhereIn('property_id', $compartidas);
                    }
                });
            }
        } else {
            // 🌐 SOLICITUDES DISPONIBLES EN LA RED (Solo órdenes ABIERTAS, sin asignar y sin cotización aceptada)
            // 🚫 FILTRO DE RESTRICCIÓN 24 HORAS POR CANCELACIÓN (DESACTIVADO TEMPORALMENTE A PETICIÓN)
            /*
            if ($authUser) {
                $activeBlocks = \Illuminate\Support\Facades\DB::table('technician_cancellations')
                    ->where('technician_id', $authUser->id)
                    ->where('expires_at', '>', now())
                    ->get();

                $blockedClientUserIds = $activeBlocks->pluck('client_user_id')->filter()->unique()->toArray();
                $blockedTenantIds = $activeBlocks->pluck('tenant_id')->filter()->unique()->toArray();
                $blockedWorkOrderIds = $activeBlocks->pluck('work_order_id')->filter()->unique()->toArray();

                if (!empty($blockedWorkOrderIds)) {
                    $query->whereNotIn('id', $blockedWorkOrderIds);
                }
                if (!empty($blockedClientUserIds)) {
                    $query->whereDoesntHave('property.client', function ($qc) use ($blockedClientUserIds) {
                        $qc->whereIn('user_id', $blockedClientUserIds);
                    });
                }
                if (!empty($blockedTenantIds)) {
                    $query->whereNotIn('tenant_id', $blockedTenantIds);
                }
            }
            */

            $query->whereNotIn('status', ['Asignado', 'En Progreso', 'Terminado', 'Completado', 'Cancelado', 'Listo'])
                  ->where(function ($q) {
                      $q->whereNull('tecnico_id')
                        ->orWhere('tecnico_id', 0);
                  })
                  ->whereDoesntHave('networkQuotes', function ($nq) {
                      $nq->where('status', 'accepted');
                  });

            // Mis técnicos: el cliente que bloqueó al técnico ya no le aparece en la red
            \App\Http\Controllers\MisTecnicosController::excluirBloqueados($query, $authUser?->id);
            // Trabajos que le quitaron por no llegar: ya no los ve
            \App\Services\HorarioMercado::excluirNoLlegados($query, $authUser?->id);

            // Solicitud dirigida a un técnico favorito: solo la ve ese técnico (y Root/Admin)
            if (!$authUser || !in_array((int) $authUser->role_id, [0, 1], true)) {
                $query->where(function ($q) use ($authUser) {
                    $q->whereNull('tecnico_dirigido_id');
                    if ($authUser) $q->orWhere('tecnico_dirigido_id', $authUser->id);
                });
            }

            // Técnico de cuadrilla (8): no ve la red, solo recibe lo que le asigna su contratista
            if ($authUser && \App\Services\RolesRed::esTecnico($authUser->role_id) && !\App\Services\RolesRed::cotiza($authUser->role_id)) {
                $query->whereRaw('1 = 0');
            }
            // Técnico 9: no compite con su contratista; no ve los trabajos que su contratista ya cotizó
            if ($authUser && (int) $authUser->role_id === \App\Services\Cuadrilla::ROL_CUADRILLA_CON_RED
                && ($jefe = \App\Services\Cuadrilla::contratistaDe($authUser))) {
                $query->whereDoesntHave('networkQuotes', fn ($nq) => $nq->where('technician_id', $jefe->id));
            }
        }

        $jobs = $query->orderBy('created_at', 'desc')->get();

        // Contratista: los trabajos que ya cotizó alguien de su cuadrilla por su cuenta (técnicos 9)
        $cotizadosPorMiCuadrilla = [];
        if ($authUser && \App\Services\Cuadrilla::esContratista($authUser) && !$request->boolean('only_mine') && $jobs->isNotEmpty()) {
            $cotizadosPorMiCuadrilla = \Illuminate\Support\Facades\DB::table('network_quotes')
                ->join('users', 'users.id', '=', 'network_quotes.technician_id')
                ->whereIn('network_quotes.work_order_id', $jobs->pluck('id'))
                ->where('users.tenant_id', $authUser->tenant_id)
                ->where('users.id', '!=', $authUser->id)
                ->whereIn('users.role_id', \App\Services\Cuadrilla::ROLES_MIEMBRO)
                ->get(['network_quotes.work_order_id', 'users.first_name', 'users.last_name'])
                ->groupBy('work_order_id')
                ->map(fn ($filas) => $filas->map(fn ($f) => trim("{$f->first_name} {$f->last_name}"))->unique()->values()->all())
                ->all();
        }

        // El técnico abrió el mercado: los trabajos que llevaba sin avisar ya los vio
        if (!$request->boolean('only_mine')) {
            \App\Services\AvisoTrabajosRed::visto($authUser);
        }

        // Nombres de los técnicos a los que se dirigieron las solicitudes (para que el cliente vea a quién)
        $nombresDirigidos = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $jobs->pluck('tecnico_dirigido_id')->filter()->unique())
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [$u->id => trim("{$u->first_name} {$u->last_name}") ?: $u->name]);

        $jobs->transform(function ($job) use ($authUser, $nombresDirigidos, $request, $cotizadosPorMiCuadrilla) {
            $job->dirigida_a_mi = $authUser && $job->tecnico_dirigido_id && (int) $job->tecnico_dirigido_id === (int) $authUser->id;
            // Para el contratista: quién de su cuadrilla ya cotizó este trabajo
            $job->cotizado_por_cuadrilla = $cotizadosPorMiCuadrilla[$job->id] ?? [];
            $job->tecnico_dirigido_nombre = $job->tecnico_dirigido_id ? ($nombresDirigidos[$job->tecnico_dirigido_id] ?? 'Tu técnico') : null;

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
            // ★ trato / ⏱ pago del cliente: exclusivo de los técnicos (el cliente no ve su calificación)
            $job->owner_rating = ($authUser && in_array((int) $authUser->role_id, array_merge([0, 1], \App\Services\RolesRed::TODOS_LOS_TECNICOS), true) && !$request->boolean('only_mine'))
                ? \App\Services\CalificacionCliente::resumen($ownerUserId)
                : null;

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
            $job->cancelled_by_tech = (bool) $job->cancelled_by_tech;
            $job->cancelled_technician_id = $job->cancelled_technician_id;
            $job->cancellation_reason = $job->cancellation_reason;
            $job->cancelled_at = $job->cancelled_at ? (\Carbon\Carbon::parse($job->cancelled_at)->format('Y-m-d H:i')) : null;
            if ($job->cancelled_technician_id) {
                $cTech = \App\Models\User::withoutGlobalScopes()->find($job->cancelled_technician_id);
                if ($cTech) {
                    $job->cancelled_technician = [
                        'id' => $cTech->id,
                        'name' => trim("{$cTech->first_name} {$cTech->last_name}") ?: $cTech->name,
                        'first_name' => $cTech->first_name,
                        'last_name' => $cTech->last_name,
                        'profile_picture' => $cTech->profile_picture,
                        'rating_stars_avg' => $cTech->rating_stars_avg,
                        'rating_time_avg' => $cTech->rating_time_avg,
                    ];
                }
            }

            $isMine = false;
            if ($authUser) {
                if (in_array((int)$authUser->role_id, [0, 1])) {
                    $isMine = true;
                } elseif (\App\Services\AccesoPropiedad::esInvitado($authUser, $job->property_id)) {
                    // Propiedad compartida: el invitado la ve como suya
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

            // 🔒 Privacidad: antes de que acepten su cotización, el técnico (o un visitante sin sesión) no recibe
            // la dirección exacta, las coordenadas ni los datos del cliente; solo la zona aproximada calculada arriba.
            // Se usa la regla estricta de dueño (no $isMine, que compara nombres) y aplica también con only_mine.
            // Se decide antes de ocultar nada: revisar al dueño vuelve a cargar la propiedad
            $esDuenoDelTrabajo = $authUser && \App\Http\Controllers\AjusteCotizacionController::puedeResponderPorDueno($authUser, $job);
            $veDetalle = $authUser && (
                $esDuenoDelTrabajo
                || \App\Http\Controllers\AccesoQrRedController::esTecnicoDelTrabajo($authUser, $job)
            );
            if (!$veDetalle) {
                $job->unsetRelation('property');
                // Solo sus propias cotizaciones: no ve los precios de los demás técnicos (el total va en network_quotes_count)
                $job->setRelation('networkQuotes', $job->networkQuotes
                    ->filter(fn ($q) => $authUser && (int) $q->technician_id === (int) $authUser->id)
                    ->values());
                $job->owner_name = 'Cliente de la Red';
                $job->owner_user_id = null;
                $job->owner_tenant_id = null;
                $job->is_mine = false;
            }

            // Cliente: quién va a ir a su casa (el contratista y/o los técnicos de su cuadrilla)
            if ($esDuenoDelTrabajo) {
                $job->equipo_en_sitio = \App\Services\Cuadrilla::equipoVisible($job);
            }

            // Horario en la cotización: vencida aunque el Cron todavía no haya pasado
            if (\App\Services\HorarioMercado::esFlujoNuevo($job)) {
                foreach ($job->networkQuotes as $q) {
                    $vence = \App\Services\HorarioMercado::venceEn($q);
                    $q->setAttribute('vencida', \App\Services\HorarioMercado::estaVencida($q));
                    $q->setAttribute('vence_en', $vence ? $vence->format('Y-m-d H:i:s') : null);
                }
            }

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
                // Ajustes de cotización (si ya corrió la migración; sin la tabla el mercado no debe caerse)
                ->when(\Illuminate\Support\Facades\Schema::hasTable('network_quote_ajustes'), fn ($q) => $q->with('networkQuotes.ajustes'))
                ->where(function ($q) use ($authUser) {
                    $q->where('tecnico_id', $authUser->id)
                      ->orWhereHas('networkQuotes', function ($nq) use ($authUser) {
                          $nq->where('technician_id', $authUser->id)
                             ->where('status', 'accepted');
                      });
                    // Trabajos de su contratista a los que lo asignaron (técnicos de cuadrilla)
                    if (\Illuminate\Support\Facades\Schema::hasTable('work_order_technician')) {
                        $q->orWhereIn('id', \Illuminate\Support\Facades\DB::table('work_order_technician')->where('technician_id', $authUser->id)->select('work_order_id'));
                    }
                })
                ->whereIn('status', ['Asignado', 'En Progreso', 'Terminado', 'Finalizado', 'Listo', 'Completado', 'Aprobado', 'Entregado'])
                ->orderBy('updated_at', 'desc');

            $rawAccepted = $acceptedQuery->get();
            $acceptedJobs = $rawAccepted->map(function ($order) use ($authUser) {
                // Si va por su contratista (cuadrilla): no ve el precio ni los datos de contacto del cliente;
                // el contratista es quien cobra y quien habla con el cliente
                $vistaEquipo = \App\Services\Cuadrilla::vistaDelTecnico($order, $authUser);
                $esCuadrilla = $vistaEquipo['es_cuadrilla'];
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
                $ajustesDeLaCotizacion = ($acceptedQuote && $acceptedQuote->relationLoaded('ajustes')) ? $acceptedQuote->ajustes : collect();

                $clientName = 'Cliente';
                $clientPhone = '';
                $clientEmail = '';
                if ($order->property && $order->property->client) {
                    $client = $order->property->client;
                    $clientName = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) ?: ($client->name ?? 'Cliente');
                    $clientPhone = $client->phone ?: ($client->phone_number ?? '');
                    $clientEmail = $client->email ?? '';
                }

                $facadePhoto = $order->property?->facade_photo_path ?: ($order->property?->facade_photo ?: ($order->property?->foto_fachada ?: null));
                $fotos = array_values(array_filter([
                    $order->evidence_path,
                    $order->evidence_path_2,
                    $facadePhoto
                ]));

                $jobReview = \Illuminate\Support\Facades\DB::table('technician_reviews')
                    ->where('work_order_id', $order->id)
                    ->where('technician_id', $authUser->id)
                    ->first();

                return [
                    'id' => $order->id,
                    'type' => $order->type,
                    'titulo' => $order->type . ($order->equipment ? ' - ' . $order->equipment : ''),
                    'equipment' => $order->equipment,
                    'specialty' => $order->specialty_id ? $order->specialty()->first(['id', 'name', 'icon']) : null,
                    'zone' => $order->zone,
                    'description' => $order->description,
                    'status' => $order->status,
                    'priority' => $order->priority ?: 'Normal',
                    'is_urgent' => in_array(strtolower($order->priority ?? ''), ['urgente', 'sos', 'urgent']),
                    'scheduled_at' => $order->scheduled_at ? (\Carbon\Carbon::parse($order->scheduled_at)->format('Y-m-d H:i')) : null,
                    // Llegada al sitio: así, al recargar, el técnico ya no ve "Llegué al lugar"
                    'arrived_at' => $order->arrived_at ? \Carbon\Carbon::parse($order->arrived_at)->format('Y-m-d H:i:s') : null,
                    'arrival_status' => $order->arrival_status,
                    // El dueño confirmó la llegada: el técnico ya puede comenzar
                    'arrival_confirmed_at' => $order->arrival_confirmed_at ? \Carbon\Carbon::parse($order->arrival_confirmed_at)->format('Y-m-d H:i:s') : null,
                    // Horario en la cotización (flujo nuevo): "Comenzar ruta" registrado y la re-programación única
                    'flujo_horario' => (bool) ($order->flujo_horario ?? false),
                    'ruta_iniciada_at' => $order->ruta_iniciada_at ?? null,
                    'reprogramacion_propuesta' => $order->reprogramacion_propuesta ?? null,
                    'reprogramacion_usada' => (bool) ($order->reprogramacion_usada ?? false),
                    'fecha_deseada' => $order->fecha_deseada ?? null,
                    'hora_deseada' => $order->hora_deseada ?? null,
                    'evidence_path' => $order->evidence_path,
                    'evidence_path_2' => $order->evidence_path_2,
                    'property' => $order->property,
                    'facade_photo' => $facadePhoto,
                    'foto_fachada' => $facadePhoto,
                    'foto' => $order->evidence_path ?: ($order->evidence_path_2 ?: ($facadePhoto ?: null)),
                    'fotos' => $fotos,
                    'lat' => $rawLat,
                    'lng' => $rawLng,
                    'full_address' => $order->property ? $order->property->address : 'Dirección confirmada',
                    'property_name' => $order->property ? ($order->property->nombre_propiedad ?: $order->property->address) : '',
                    'client_name' => $clientName,
                    // Usuario dueño del trabajo: agrupa "Mis clientes" y sirve para marcarlo favorito o bloquearlo
                    'client_user_id' => $esCuadrilla ? null : \App\Http\Controllers\MisClientesController::duenoDeOrden((int) $order->id),
                    'client_phone' => $esCuadrilla ? '' : $clientPhone,
                    'client_email' => $esCuadrilla ? '' : $clientEmail,
                    'agreed_price' => ($acceptedQuote && !$esCuadrilla) ? (float)$acceptedQuote->price : 0,
                    // Ajustes de cotización: precio con el que se aceptó al inicio y si hay uno en curso
                    'precio_original' => ($acceptedQuote && !$esCuadrilla)
                        ? (float) ($ajustesDeLaCotizacion->first()?->precio_anterior ?? $acceptedQuote->price)
                        : 0,
                    'ajuste_pendiente' => !$esCuadrilla && $ajustesDeLaCotizacion->contains('status', 'pending'),
                    'ajuste_por_decidir' => !$esCuadrilla && $ajustesDeLaCotizacion->contains(fn ($a) => $a->status === 'rejected' && !$a->decision_tecnico),
                    'myQuote' => $esCuadrilla ? null : $acceptedQuote,
                    'review' => $jobReview ? [
                        'rating_stars' => (float)$jobReview->rating_stars,
                        'rating_time' => (float)$jobReview->rating_time,
                        'comment' => $jobReview->comment,
                        'created_at' => \Carbon\Carbon::parse($jobReview->created_at)->format('d/m/Y H:i'),
                    ] : null,
                    'created_at' => $order->created_at->toIso8601String(),
                    'updated_at' => $order->updated_at->toIso8601String(),
                    // Cuadrilla: si va por su contratista, con quién va y si (el contratista) puede asignar a su equipo.
                    // No se llama "equipo": en el tablero ese campo es el equipo afectado (texto)
                    'es_cuadrilla' => $esCuadrilla,
                    'contratista' => $vistaEquipo['contratista'],
                    'equipo_cuadrilla' => $vistaEquipo['equipo'],
                    'puede_asignar_cuadrilla' => $vistaEquipo['puede_asignar_cuadrilla'],
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


// Registro Privado (Exclusivo para el Admin)
Route::post('/registro-usuario', [AuthController::class, 'registro']);

// Purga diaria de cuentas eliminadas. La dispara el servicio Cron de Railway con el header
// X-Cron-Token = CRON_TOKEN. Corre aquí (servicio web) porque los documentos KYC viven en su disco.
Route::post('/interno/cuentas/purgar', function (\Illuminate\Http\Request $request) {
    $esperado = (string) config('services.cron.token', '');
    if ($esperado === '' || !hash_equals($esperado, (string) $request->header('X-Cron-Token'))) {
        abort(404);
    }
    @set_time_limit(600);
    $codigo = \Illuminate\Support\Facades\Artisan::call('cuentas:purgar-eliminadas', $request->boolean('dry_run') ? ['--dry-run' => true] : []);
    return response(\Illuminate\Support\Facades\Artisan::output(), $codigo === 0 ? 200 : 500)
        ->header('Content-Type', 'text/plain; charset=utf-8');
})->middleware('throttle:5,1');

// Agente Market: tareas del horario en la cotización (vencimientos, recordatorios, tardanza, "nadie cotizó").
// Cada minuto (o cada 5) desde un Cron externo con X-Cron-Token = CRON_TOKEN. También corre con schedule:run.
Route::post('/interno/mercado/tareas', function (\Illuminate\Http\Request $request) {
    $esperado = (string) config('services.cron.token', '');
    if ($esperado === '' || !hash_equals($esperado, (string) $request->header('X-Cron-Token'))) {
        abort(404);
    }
    return response()->json(\App\Services\HorarioMercado::tareas());
})->middleware('throttle:20,1');

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
    // Lectura pública: la usan el login y los headers antes de iniciar sesión
    Route::get('/login-settings', [AppSettingController::class, 'getLoginSettings']);
    Route::get('/sidebar-links', [AppSettingController::class, 'getSidebarLinks']);

    // Escritura: solo usuarios autenticados con rol Root (0), Admin (1) o Gestor Inmobiliario (5)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/login-background/image', [AppSettingController::class, 'updateLoginBackground'])->middleware('role:0,1,5');
        Route::post('/login-background/color', [AppSettingController::class, 'updateLoginColor'])->middleware('role:0,1,5');
        Route::delete('/login-background/image', [AppSettingController::class, 'deleteLoginBackground'])->middleware('role:0,1,5');
        Route::post('/app-logo', [AppSettingController::class, 'updateAppLogo'])->middleware('role:0,1,5');
        Route::delete('/app-logo', [AppSettingController::class, 'deleteAppLogo'])->middleware('role:0,1,5');
        Route::post('/sidebar-links', [AppSettingController::class, 'updateSidebarLinks'])->middleware('role:0,1,5');

        // Datos bancarios para pagos por transferencia: los lee cualquier usuario con sesión, los edita Root/Admin
        Route::get('/bank-info', [AppSettingController::class, 'getBankInfo']);
        Route::post('/bank-info', [AppSettingController::class, 'updateBankInfo'])->middleware('role:0,1');
    });
});

// Mantenimiento del servidor: solo Root (0) autenticado
Route::middleware(['auth:sanctum', 'role:0'])->prefix('maintenance')->group(function () {
    Route::post('/clear-cache', function () {
        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        return response()->json(['message' => 'Caché limpiada correctamente.']);
    });

    Route::post('/migrate', function () {
        Artisan::call('migrate', ['--force' => true]);
        return response()->json(['message' => 'Migraciones ejecutadas.', 'output' => Artisan::output()]);
    });
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
    Route::post('/tenants/reject-technician/{userId}', [TenantController::class, 'rejectTechnician'])->whereNumber('userId');
    // "Mi cuadrilla" del contratista: equipo, cupo, código y pendientes
    Route::get('/cuadrilla', [\App\Http\Controllers\CuadrillaController::class, 'index']);
    // El contratista saca a un técnico de su cuadrilla (se desactiva; su historial se conserva)
    Route::post('/cuadrilla/{userId}/quitar', [\App\Http\Controllers\CuadrillaController::class, 'quitar']);
    // Quién va a cada trabajo: el contratista elige entre él y su cuadrilla
    Route::get('/mercado-trabajos/{id}/cuadrilla', [\App\Http\Controllers\CuadrillaController::class, 'equipoDeTrabajo'])->whereNumber('id');
    Route::post('/mercado-trabajos/{id}/cuadrilla', [\App\Http\Controllers\CuadrillaController::class, 'asignarEquipo'])->whereNumber('id');
    // Técnico con cuenta: unirse a una cuadrilla con código, su estado y lo que le pide a Root
    Route::post('/cuadrilla/unirme', [\App\Http\Controllers\CuadrillaController::class, 'unirme'])->middleware('throttle:10,1');
    Route::get('/cuadrilla/mi-estado', [\App\Http\Controllers\CuadrillaController::class, 'miEstado']);
    Route::post('/cuadrilla/solicitar-independiente', [\App\Http\Controllers\CuadrillaController::class, 'solicitarIndependiente'])->middleware('throttle:3,60');
    Route::post('/cuadrilla/solicitar-red', [\App\Http\Controllers\CuadrillaController::class, 'solicitarRed'])->middleware('throttle:3,60');

    // --- PERFIL PROFESIONAL DE TÉCNICOS DE LA RED (roles 6 y 8) ---
    // Técnico: completar su perfil (lo único que puede usar hasta que Admin/Root lo apruebe)
    Route::get('/tecnico/perfil-profesional', [\App\Http\Controllers\PerfilProfesionalController::class, 'mio']);
    Route::post('/tecnico/perfil-profesional', [\App\Http\Controllers\PerfilProfesionalController::class, 'guardar']);
    Route::post('/tecnico/perfil-profesional/consentimiento', [\App\Http\Controllers\PerfilProfesionalController::class, 'consentir']);
    Route::post('/tecnico/perfil-profesional/ine', [\App\Http\Controllers\PerfilProfesionalController::class, 'subirIne']);
    Route::post('/tecnico/perfil-profesional/foto', [\App\Http\Controllers\PerfilProfesionalController::class, 'subirFoto']);
    Route::post('/tecnico/perfil-profesional/documento/{tipo}', [\App\Http\Controllers\PerfilProfesionalController::class, 'subirDocumento'])
        ->whereIn('tipo', ['comprobante_domicilio', 'antecedentes_penales']);
    Route::post('/tecnico/perfil-profesional/cursos', [\App\Http\Controllers\PerfilProfesionalController::class, 'agregarCurso']);
    Route::delete('/tecnico/perfil-profesional/cursos/{id}', [\App\Http\Controllers\PerfilProfesionalController::class, 'borrarCurso']);
    Route::post('/tecnico/perfil-profesional/enviar', [\App\Http\Controllers\PerfilProfesionalController::class, 'enviar']);
    // INE y diplomas (privados): el propio técnico y Admin/Root
    Route::get('/perfil-profesional/{userId}/archivo/{tipo}/{cursoId?}', [\App\Http\Controllers\PerfilProfesionalController::class, 'archivo']);
    // Clientes: perfil por niveles (RFC y CLABE solo si ya lo contrató)
    Route::get('/tecnicos/resumenes', [\App\Http\Controllers\PerfilProfesionalController::class, 'resumenes']);
    Route::get('/tecnicos/{userId}/perfil-profesional', [\App\Http\Controllers\PerfilProfesionalController::class, 'publico']);
    // Calificación de clientes de la red (★ trato, ⏱ pago)
    // Agente Market: trabajos terminados que el cliente aún no califica (estrellas: calidad; relojes: puntualidad)
    // data: confirmados por calificar; por_confirmar: reportes enviados que el cliente aún no confirma (48 h y se confirman solos)
    Route::get('/red/calificaciones-tecnico/pendientes', function (\Illuminate\Http\Request $request) {
        $pendientes = \App\Services\TrabajoFinalizadoRed::pendientes($request->user());
        return response()->json([
            'success' => true,
            'data' => $pendientes['calificar'],
            'por_confirmar' => $pendientes['por_confirmar'],
            'horas_auto' => \App\Services\TrabajoFinalizadoRed::HORAS_AUTO,
        ]);
    });
    // Cláusula del Mercado (la app solo conecta, sin comisiones; pagos y problemas son entre cliente y técnico).
    // Técnicos y clientes de la red (roles 4-9) la aceptan una vez; los demás no la necesitan.
    Route::get('/mercado/terminos', function (\Illuminate\Http\Request $request) {
        $user = $request->user();
        $aplica = in_array((int) $user->role_id, [4, 5, 6, 7, 8, 9], true);
        $fecha = \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->value('terminos_mercado_at');
        return response()->json(['aplica' => $aplica, 'aceptado' => !$aplica || (bool) $fecha, 'aceptado_at' => $fecha]);
    });
    Route::post('/mercado/terminos', function (\Illuminate\Http\Request $request) {
        $request->validate(['acepto' => 'accepted'], ['acepto.accepted' => 'Debes marcar la casilla para continuar.']);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $request->user()->id)
            ->whereNull('terminos_mercado_at')->update(['terminos_mercado_at' => now()]);
        return response()->json(['success' => true, 'aceptado' => true]);
    });
    // El cliente confirma que el reporte está correcto; responde con el trabajo para calificar al técnico
    Route::post('/red/trabajos/{workOrderId}/confirmar-reporte', function (\Illuminate\Http\Request $request, $workOrderId) {
        $orden = \App\Models\WorkOrder::withoutGlobalScopes()->find((int) $workOrderId);
        if (!$orden || !$orden->publish_network || !\App\Services\TrabajoFinalizadoRed::esDelCliente($request->user(), $orden)) {
            return response()->json(['error' => 'No tienes acceso a este trabajo.'], 403);
        }
        if (!$orden->report_sent_at) {
            return response()->json(['error' => 'El técnico aún no envía el reporte.'], 422);
        }
        \App\Services\TrabajoFinalizadoRed::confirmar($orden, $request->user());
        return response()->json(['success' => true, 'data' => \App\Services\TrabajoFinalizadoRed::resumen($orden->fresh())]);
    });
    Route::get('/tecnico/calificaciones-cliente/pendientes', [\App\Http\Controllers\CalificacionClienteController::class, 'pendientes']);
    Route::post('/tecnico/calificaciones-cliente/{workOrderId}', [\App\Http\Controllers\CalificacionClienteController::class, 'guardar']);
    // Admin/Root: validar
    Route::middleware('role:0,1')->group(function () {
        Route::get('/admin/perfiles-profesionales', [\App\Http\Controllers\PerfilProfesionalController::class, 'porValidar']);
        Route::get('/admin/perfiles-profesionales/{userId}', [\App\Http\Controllers\PerfilProfesionalController::class, 'detalleAdmin']);
        Route::post('/admin/perfiles-profesionales/{userId}/aprobar', [\App\Http\Controllers\PerfilProfesionalController::class, 'aprobar']);
        Route::post('/admin/perfiles-profesionales/{userId}/rechazar', [\App\Http\Controllers\PerfilProfesionalController::class, 'rechazar']);
    });

    // --- USUARIOS Y PERFILES ---
    Route::post('/specialties', [SpecialtyController::class, 'store']);
    // Habilidades sugeridas: el técnico sugiere; Root/Admin aprueban, unen o rechazan
    Route::post('/especialidades/sugerencias', [\App\Http\Controllers\SugerenciaEspecialidadController::class, 'store']);
    Route::get('/especialidades/sugerencias/mias', [\App\Http\Controllers\SugerenciaEspecialidadController::class, 'mias']);
    Route::get('/admin/especialidades/sugerencias', [\App\Http\Controllers\SugerenciaEspecialidadController::class, 'index']);
    Route::post('/admin/especialidades/sugerencias/{id}/aprobar', [\App\Http\Controllers\SugerenciaEspecialidadController::class, 'aprobar'])->whereNumber('id');
    Route::post('/admin/especialidades/sugerencias/{id}/unir', [\App\Http\Controllers\SugerenciaEspecialidadController::class, 'unir'])->whereNumber('id');
    Route::post('/admin/especialidades/sugerencias/{id}/rechazar', [\App\Http\Controllers\SugerenciaEspecialidadController::class, 'rechazar'])->whereNumber('id');
    Route::get('/users/{id}/specialties', [SpecialtyController::class, 'getUserSpecialties']);
    Route::post('/users/{id}/specialties', [SpecialtyController::class, 'syncUserSpecialties']);
    Route::get('/usuarios', [UserController::class, 'getUsuarios']);
    Route::get('/users', [UserController::class, 'getUsuarios']);
    Route::delete('/usuarios/delete-my-account', [UserController::class, 'deleteMyAccount']);
    Route::delete('/users/delete-my-account', [UserController::class, 'deleteMyAccount']);
    Route::post('/usuarios/{id}/cancelar-eliminacion', [UserController::class, 'cancelarEliminacion']);
    // Alta de usuarios desde el panel (sin captcha; valida sesión y rol)
    Route::post('/admin/usuarios', [AuthController::class, 'registroPorAdmin']);
    // "Eliminar definitivamente": anonimiza (nunca borra la fila)
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

    Route::post('/services/{id}/confirm-arrival', [ServiceController::class, 'confirmArrival']);
    Route::post('/work-orders/{id}/confirm-arrival', [ServiceController::class, 'confirmArrival']);
    // Red: el dueño de la propiedad confirma la llegada del técnico (autoriza comenzar)
    Route::post('/work-orders/{id}/client-confirm-arrival', [ServiceController::class, 'clientConfirmArrival']);

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
    Route::get('/propiedades/{id}/expediente', [PropertyController::class, 'expediente']);

    Route::post('/propiedades/{id}/update', [PropertyController::class, 'updateProperty']);
    Route::get('/properties/by-curp/{curp}', [PropertyController::class, 'getByCurp']);
    Route::get('/properties/{id}/inventory-report', [PropertyController::class, 'getPropertyReport']);
    Route::post('/properties/{id}/finalize-survey', [PropertyController::class, 'finalizeSurvey']);

    // --- EQUIPO DE TRABAJO DEL LEVANTAMIENTO (solo admins administran y finalizan) ---
    Route::get('/propiedades/{id}/equipo-levantamiento', [\App\Http\Controllers\SurveyTeamController::class, 'show']);
    Route::post('/propiedades/{id}/equipo-levantamiento', [\App\Http\Controllers\SurveyTeamController::class, 'add']);
    Route::delete('/propiedades/{id}/equipo-levantamiento/{technicianId}', [\App\Http\Controllers\SurveyTeamController::class, 'remove']);
    Route::post('/propiedades/{id}/equipo-levantamiento/finalizar', [\App\Http\Controllers\SurveyTeamController::class, 'finalize']);

    // --- COMPARTIR PROPIEDAD (HERENCIA) ---
    Route::post('/propiedades/{id}/share', [PropertyController::class, 'shareProperty']);
    Route::delete('/propiedades/{id}/share/{clientId}', [PropertyController::class, 'revokeShare']);
    Route::get('/propiedades/{id}/shared-users', [PropertyController::class, 'getSharedUsers']);

    // --- CARPETAS DEL CATÁLOGO DE PROPIEDADES (solo admins) ---
    Route::get('/property-folders', [\App\Http\Controllers\PropertyFolderController::class, 'index']);
    Route::post('/property-folders', [\App\Http\Controllers\PropertyFolderController::class, 'store']);
    Route::put('/property-folders/{id}', [\App\Http\Controllers\PropertyFolderController::class, 'update']);
    Route::delete('/property-folders/{id}', [\App\Http\Controllers\PropertyFolderController::class, 'destroy']);
    Route::post('/property-folders/{id}/logo', [\App\Http\Controllers\PropertyFolderController::class, 'subirLogo']);
    Route::delete('/property-folders/{id}/logo', [\App\Http\Controllers\PropertyFolderController::class, 'quitarLogo']);
    Route::put('/propiedades/{id}/folder', [\App\Http\Controllers\PropertyFolderController::class, 'moverPropiedad']);

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
            // Rol 4 sin tenant cae abajo como cliente: tenant_id NULL devolvería todas las propiedades
            if ($user->role_id == 4 && $user->tenant_id) {
                // También las propiedades que otros le compartieron a su perfil de cliente
                $clienteIds = \Illuminate\Support\Facades\DB::table('clients')->where('user_id', $user->id)->pluck('id');
                $sharedPropertyIds = \Illuminate\Support\Facades\DB::table('property_shares')->whereIn('client_id', $clienteIds)->pluck('property_id');
                $query->where(function ($q) use ($user, $sharedPropertyIds) {
                    $q->where('properties.tenant_id', $user->tenant_id)
                        ->orWhere('clients.tenant_id', $user->tenant_id)
                        ->orWhereIn('properties.id', $sharedPropertyIds);
                });
            } elseif ($user->role_id !== 0 && $user->tenant_id) {
                $query->where(function ($q) use ($user) {
                    $q->where('properties.tenant_id', $user->tenant_id)
                        ->orWhere('clients.tenant_id', $user->tenant_id);
                });
            } elseif ($user->role_id == 3 || $user->role_id == 4) {
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
    Route::get('/servicios/{id}/final-report/versions', [ServiceController::class, 'getFinalReportVersions']);
    Route::get('/report-logos', [\App\Http\Controllers\ReportLogoController::class, 'index']);
    Route::post('/report-logos', [\App\Http\Controllers\ReportLogoController::class, 'store']);
    Route::delete('/report-logos/{id}', [\App\Http\Controllers\ReportLogoController::class, 'destroy']);
    Route::get('/report-footers', [\App\Http\Controllers\ReportFooterController::class, 'index']);
    Route::post('/report-footers', [\App\Http\Controllers\ReportFooterController::class, 'store']);
    Route::delete('/report-footers/{id}', [\App\Http\Controllers\ReportFooterController::class, 'destroy']);
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

    // --- EVENTOS DEL CALENDARIO Y CUMPLEAÑOS ---
    Route::get('/calendar/events', [\App\Http\Controllers\CalendarEventController::class, 'index']);
    Route::get('/calendar/birthdays', [\App\Http\Controllers\CalendarEventController::class, 'getBirthdays']);
    Route::get('/calendar/labels', [\App\Http\Controllers\CalendarEventController::class, 'labels']);
    Route::get('/calendar/team', [\App\Http\Controllers\CalendarEventController::class, 'team']);
    Route::post('/calendar/events', [\App\Http\Controllers\CalendarEventController::class, 'store']);
    Route::put('/calendar/events/{id}', [\App\Http\Controllers\CalendarEventController::class, 'update']);
    Route::delete('/calendar/events/{id}', [\App\Http\Controllers\CalendarEventController::class, 'destroy']);
    Route::post('/calendar/events/{id}/complete', [\App\Http\Controllers\CalendarEventController::class, 'complete']);
    Route::post('/calendar/events/{id}/remind', [\App\Http\Controllers\CalendarEventController::class, 'remind']);

    // Comprobantes de gastos (Agente Solutions / Mantenere)
    // Mis técnicos (cliente particular): historial, favoritos y bloqueos
    Route::get('/mis-tecnicos', [\App\Http\Controllers\MisTecnicosController::class, 'index']);
    Route::put('/mis-tecnicos/{tecnicoId}', [\App\Http\Controllers\MisTecnicosController::class, 'marcar'])->whereNumber('tecnicoId');
    Route::get('/mis-tecnicos/{tecnicoId}/detalle', [\App\Http\Controllers\TecnicoQrController::class, 'detalle'])->whereNumber('tecnicoId');
    Route::post('/red/qr/{codigo}/contratar', [\App\Http\Controllers\TecnicoQrController::class, 'contratar'])->where('codigo', '[A-Za-z0-9]{6,20}');
    // Red: ajuste de una cotización ya aceptada (el técnico propone, el dueño responde)
    Route::get('/mercado-trabajos/{id}/ajustes', [\App\Http\Controllers\AjusteCotizacionController::class, 'index'])->whereNumber('id');
    Route::post('/mercado-trabajos/{id}/ajustes', [\App\Http\Controllers\AjusteCotizacionController::class, 'proponer'])->whereNumber('id');
    Route::post('/ajustes-cotizacion/{id}/responder', [\App\Http\Controllers\AjusteCotizacionController::class, 'responder'])->whereNumber('id');
    Route::post('/ajustes-cotizacion/{id}/retirar', [\App\Http\Controllers\AjusteCotizacionController::class, 'retirar'])->whereNumber('id');
    Route::post('/ajustes-cotizacion/{id}/decidir', [\App\Http\Controllers\AjusteCotizacionController::class, 'decidir'])->whereNumber('id');
    // Red: el cliente cancela un trabajo asignado; el técnico retira su cotización o confirma que sigue disponible
    Route::post('/mercado-trabajos/{id}/cancelar-cliente', [\App\Http\Controllers\RedCancelacionController::class, 'cancelarCliente'])->whereNumber('id');
    Route::post('/network-quotes/{id}/retirar', [\App\Http\Controllers\RedCancelacionController::class, 'retirarCotizacion'])->whereNumber('id');
    Route::post('/network-quotes/{id}/confirmar', [\App\Http\Controllers\RedCancelacionController::class, 'confirmarDisponibilidad'])->whereNumber('id');
    Route::post('/network-quotes/{id}/cancelar-confirmacion', [\App\Http\Controllers\RedCancelacionController::class, 'cancelarSolicitudConfirmacion'])->whereNumber('id');
    // Red: horario en la cotización (en camino, el técnico no llegó, nadie cotizó a tiempo)
    Route::post('/mercado-trabajos/{id}/en-camino', [\App\Http\Controllers\HorarioMercadoController::class, 'enCamino'])->whereNumber('id');
    Route::post('/mercado-trabajos/{id}/seguir-esperando', [\App\Http\Controllers\HorarioMercadoController::class, 'seguirEsperando'])->whereNumber('id');
    Route::post('/mercado-trabajos/{id}/tecnico-no-llego', [\App\Http\Controllers\HorarioMercadoController::class, 'tecnicoNoLlego'])->whereNumber('id');
    Route::post('/mercado-trabajos/{id}/reprogramar-solicitud', [\App\Http\Controllers\HorarioMercadoController::class, 'reprogramarSolicitud'])->whereNumber('id');
    // Red: acceso a la propiedad (QR y/o código). Sube el dueño; el técnico lo ve al marcar "Llegué"
    Route::get('/red/trabajos/{id}/acceso', [\App\Http\Controllers\AccesoQrRedController::class, 'index'])->whereNumber('id');
    Route::get('/red/trabajos/{id}/acceso/{slot}/imagen', [\App\Http\Controllers\AccesoQrRedController::class, 'imagen'])->where(['id' => '[0-9]+', 'slot' => '[12]']);
    Route::post('/red/trabajos/{id}/acceso', [\App\Http\Controllers\AccesoQrRedController::class, 'store'])->whereNumber('id');
    Route::delete('/red/trabajos/{id}/acceso/{slot}', [\App\Http\Controllers\AccesoQrRedController::class, 'destroy'])->where(['id' => '[0-9]+', 'slot' => '[12]']);
    Route::get('/mi-qr', [\App\Http\Controllers\TecnicoQrController::class, 'miQr']);
    // Mis clientes (técnico de la red): favoritos y bloqueos
    Route::get('/mis-clientes', [\App\Http\Controllers\MisClientesController::class, 'index']);
    Route::put('/mis-clientes/{clienteId}', [\App\Http\Controllers\MisClientesController::class, 'marcar'])->whereNumber('clienteId');

    // Chat interno del equipo
    Route::get('/chats', [\App\Http\Controllers\ChatController::class, 'index']);
    Route::get('/chats/no-leidos', [\App\Http\Controllers\ChatController::class, 'noLeidos']);
    Route::get('/chats/contactos', [\App\Http\Controllers\ChatController::class, 'contactos']);
    Route::post('/chats', [\App\Http\Controllers\ChatController::class, 'store']);
    Route::get('/chats/stickers', [\App\Http\Controllers\ChatController::class, 'stickers']);
    Route::post('/chats/stickers', [\App\Http\Controllers\ChatController::class, 'crearSticker']);
    Route::post('/chats/stickers/guardar', [\App\Http\Controllers\ChatController::class, 'guardarSticker']);
    Route::delete('/chats/stickers/{stickerId}', [\App\Http\Controllers\ChatController::class, 'borrarSticker'])->whereNumber('stickerId');
    Route::get('/chats/preferencias', [\App\Http\Controllers\ChatController::class, 'preferencias']);
    Route::post('/chats/fondo', [\App\Http\Controllers\ChatController::class, 'cambiarFondo']);
    Route::get('/chats/{id}/mensajes', [\App\Http\Controllers\ChatController::class, 'mensajes'])->whereNumber('id');
    Route::post('/chats/{id}/mensajes', [\App\Http\Controllers\ChatController::class, 'enviar'])->whereNumber('id');
    Route::delete('/chats/{id}/mensajes/{mensajeId}', [\App\Http\Controllers\ChatController::class, 'eliminarMensaje'])->whereNumber(['id', 'mensajeId']);
    Route::post('/chats/{id}/participantes', [\App\Http\Controllers\ChatController::class, 'agregar'])->whereNumber('id');
    Route::delete('/chats/{id}/participantes/{userId}', [\App\Http\Controllers\ChatController::class, 'quitar'])->whereNumber(['id', 'userId']);
    Route::put('/chats/{id}', [\App\Http\Controllers\ChatController::class, 'renombrar'])->whereNumber('id');
    Route::delete('/chats/{id}', [\App\Http\Controllers\ChatController::class, 'eliminarChat'])->whereNumber('id');

    Route::get('/comprobantes/estado', [\App\Http\Controllers\ExpenseReceiptController::class, 'status']);
    Route::get('/comprobantes', [\App\Http\Controllers\ExpenseReceiptController::class, 'index']);
    Route::post('/comprobantes', [\App\Http\Controllers\ExpenseReceiptController::class, 'store']);
    Route::post('/comprobantes/{id}/confirmar', [\App\Http\Controllers\ExpenseReceiptController::class, 'confirm']);
    Route::delete('/comprobantes/{id}', [\App\Http\Controllers\ExpenseReceiptController::class, 'destroy']);

    // --- UBICACIÓN EN VIVO DEL EQUIPO (con consentimiento; mapa solo para can_view_live_map) ---
    Route::get('/ubicacion/estado', [\App\Http\Controllers\LiveLocationController::class, 'status']);
    Route::post('/ubicacion/consentimiento', [\App\Http\Controllers\LiveLocationController::class, 'consent']);
    Route::post('/ubicacion/pausa', [\App\Http\Controllers\LiveLocationController::class, 'pause']);
    Route::post('/ubicacion', [\App\Http\Controllers\LiveLocationController::class, 'store']);
    Route::get('/mapa-equipo', [\App\Http\Controllers\LiveLocationController::class, 'team']);
    Route::get('/mapa-equipo/{userId}/recorrido', [\App\Http\Controllers\LiveLocationController::class, 'route']);
    Route::put('/usuarios/{userId}/permiso-mapa', [\App\Http\Controllers\LiveLocationController::class, 'setViewer']);

    // --- RASTREO GPS Y CONFIRMACIÓN DE LLEGADA ---
    Route::post('/technician/update-location', [ServiceController::class, 'updateTechnicianLocation']);
    Route::post('/work-orders/{id}/confirm-arrival', [ServiceController::class, 'confirmArrival']);
    Route::get('/root/technicians-live-map', [ServiceController::class, 'getRootTechniciansLiveMap']);

    Route::post('/propiedades/servicios', [PropertyController::class, 'storeWorkOrder']);
    Route::get('/propiedades/{id}/work-orders', [PropertyController::class, 'getWorkOrders']);
    Route::put('/work-orders/{id}/status', [PropertyController::class, 'updateWorkOrderStatus']);
    // Trabajos con varios elementos y ciclos de mantenimiento (Agente Solutions)
    Route::get('/work-orders/{id}/elementos', [\App\Http\Controllers\MaintenanceController::class, 'elementosDeOrden']);
    // Carrito de cotizaciones por equipo: el admin elige qué equipos contrata el cliente (definitivo al finalizar)
    Route::get('/work-orders/{id}/carrito', [\App\Http\Controllers\CarritoCotizacionController::class, 'show'])->whereNumber('id');
    Route::put('/work-orders/{id}/carrito', [\App\Http\Controllers\CarritoCotizacionController::class, 'guardar'])->whereNumber('id');
    Route::post('/work-orders/{id}/carrito/finalizar', [\App\Http\Controllers\CarritoCotizacionController::class, 'finalizar'])->whereNumber('id');

    // --- QR DE ACCESO A LA PROPIEDAD (tipo: work_order | servicio) ---
    // Ver: Root/Admin siempre; Técnico asignado solo tras confirmar llegada. Subir/eliminar: Root/Admin.
    Route::get('/trabajos/{tipo}/{id}/acceso-qr', [AccesoQrController::class, 'index'])
        ->where(['tipo' => 'work_order|servicio', 'id' => '[0-9]+']);
    Route::get('/trabajos/{tipo}/{id}/acceso-qr/{slot}/imagen', [AccesoQrController::class, 'imagen'])
        ->where(['tipo' => 'work_order|servicio', 'id' => '[0-9]+', 'slot' => '[12]']);
    Route::post('/trabajos/{tipo}/{id}/acceso-qr', [AccesoQrController::class, 'store'])
        ->where(['tipo' => 'work_order|servicio', 'id' => '[0-9]+'])->middleware('role:0,1');
    Route::delete('/trabajos/{tipo}/{id}/acceso-qr/{slot}', [AccesoQrController::class, 'destroy'])
        ->where(['tipo' => 'work_order|servicio', 'id' => '[0-9]+', 'slot' => '[12]'])->middleware('role:0,1');
    Route::get('/mantenimiento/propiedades', [\App\Http\Controllers\MaintenanceController::class, 'propiedades']);
    Route::get('/mantenimiento/equipos', [\App\Http\Controllers\MaintenanceController::class, 'equipos']);
    Route::post('/mantenimiento/equipos', [\App\Http\Controllers\MaintenanceController::class, 'agregar']);
    Route::post('/mantenimiento/programar', [\App\Http\Controllers\MaintenanceController::class, 'programarVarios']);
    Route::post('/mantenimiento/realizado', [\App\Http\Controllers\MaintenanceController::class, 'realizado']);
    Route::put('/mantenimiento/equipos/{id}', [\App\Http\Controllers\MaintenanceController::class, 'actualizar']);
    Route::get('/properties/{id}/mantenimiento', [\App\Http\Controllers\MaintenanceController::class, 'dePropiedad']);
    Route::get('/properties/{id}/elementos', [\App\Http\Controllers\MaintenanceController::class, 'elementosDePropiedad']);
    Route::delete('/mantenimiento/equipos/{id}', [\App\Http\Controllers\MaintenanceController::class, 'baja']);
    Route::get('/mantenimiento/visitas', [\App\Http\Controllers\MaintenanceController::class, 'visitas']);
    Route::post('/mantenimiento/visitas/{id}/omitir', [\App\Http\Controllers\MaintenanceController::class, 'omitir']);
    Route::post('/mantenimiento/visitas/{id}/convertir', [\App\Http\Controllers\MaintenanceController::class, 'convertir']);
    // Validación de trabajos terminados (validador fijo + alerta del menú)
    Route::get('/validacion/pendientes', [\App\Http\Controllers\WorkValidationController::class, 'pendientes']);
    Route::put('/usuarios/{userId}/validador', [\App\Http\Controllers\WorkValidationController::class, 'setValidador']);
    Route::put('/mi-mascota-alerta', [\App\Http\Controllers\WorkValidationController::class, 'setMascota']);
    Route::put('/work-orders/{id}/assign', [PropertyController::class, 'assignWorkOrder']);
    Route::put('/work-orders/batch/{batchId}/assign', [PropertyController::class, 'assignBatchWorkOrders']);
    Route::get('/work-orders/global-stats', [PropertyController::class, 'getGlobalServiceStats']);

    // --- GESTIÓN DE ZONAS (NIVEL 3 y 4) ---
    // Las altas, cambios y bajas pasan por 'solo-lectura-red': el Root solo consulta el
    // levantamiento de una casa de la Red (lo captura el técnico interno asignado)
    Route::post('/property-areas', [PropertyAreaController::class, 'store'])->middleware('solo-lectura-red');
    Route::get('/property-areas', [PropertyAreaController::class, 'index']);
    Route::get('/properties/{id}/areas', [PropertyAreaController::class, 'getByProperty']);
    Route::put('/property-areas/{id}', [PropertyAreaController::class, 'update'])->middleware('solo-lectura-red');
    Route::post('/property-areas/{id}/update-photo', [PropertyAreaController::class, 'updatePhoto'])->middleware('solo-lectura-red');
    Route::delete('/property-areas/{id}', [PropertyAreaController::class, 'destroy'])->middleware('solo-lectura-red');
    Route::get('/areas/{id}/subareas', [PropertyAreaController::class, 'getSubAreas']);

    // --- CATEGORÍAS Y COMPONENTES (NIVEL 5) ---
    Route::get('/areas/{id}/categories', [PropertyCategoryController::class, 'getByArea']);
    Route::post('/property-categories', [PropertyCategoryController::class, 'store'])->middleware('solo-lectura-red');
    Route::put('/property-categories/{id}', [PropertyCategoryController::class, 'update'])->middleware('solo-lectura-red');
    Route::delete('/property-categories/{id}', [PropertyCategoryController::class, 'destroy'])->middleware('solo-lectura-red');

    // --- CATEGORÍAS Y COMPONENTES (NIVEL 5) ---
    Route::get('/areas/{id}/components', [PropertyComponentController::class, 'getByArea']);
    Route::post('/property-components', [PropertyComponentController::class, 'store'])->middleware('solo-lectura-red');
    Route::delete('/property-components/{id}', [PropertyComponentController::class, 'destroy'])->middleware('solo-lectura-red');
    Route::put('/property-components/{id}', [PropertyComponentController::class, 'update'])->middleware('solo-lectura-red');
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
    Route::get('/notifications/push-status', [NotificationController::class, 'pushStatus']);
    Route::post('/push/ios/registrar', [NotificationController::class, 'registrarPushIOS'])->middleware('throttle:20,1');
    Route::post('/push/ios/baja', [NotificationController::class, 'bajaPushIOS'])->middleware('throttle:20,1');

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
            'tecnico_id' => 'nullable|integer',
            'assigned_technician_id' => 'nullable|integer',
            'tecnico_dirigido_id' => 'nullable|integer',
            // Habilidad que necesita el trabajo (vacía = "No estoy seguro")
            'specialty_id' => 'nullable|integer|exists:specialties,id',
            // Mercado: día y hora que pide el cliente (sin hora = a cualquier hora; urgente = hoy)
            'fecha_deseada' => 'nullable|date_format:Y-m-d',
            'hora_deseada' => 'nullable|date_format:H:i',
            'evidence_1' => 'nullable|file|image|max:5120',
            'evidence_2' => 'nullable|file|image|max:5120'
        ], [
            'specialty_id.exists' => 'La habilidad elegida ya no existe. Elige otra.',
        ]);

        // Solo se piden trabajos para propiedades propias, compartidas o del tenant (antes no se validaba)
        if (!\App\Services\AccesoPropiedad::puedeOperarPropiedad($request->user(), $request->property_id)) {
            return response()->json(['success' => false, 'message' => 'No puedes solicitar trabajos para esta propiedad.'], 403);
        }

        // Elementos del trabajo (opcional): se validan y se suben sus fotos antes de crear la orden
        $elementos = null;
        if (\App\Services\ElementosTrabajo::vienenEnPeticion($request)) {
            $elementos = \App\Services\ElementosTrabajo::preparar($request);
            if (isset($elementos['error'])) {
                return response()->json(['success' => false, 'message' => $elementos['error']], $elementos['codigo']);
            }
        }

        // Solicitud dirigida (cliente de la red → uno de sus técnicos favoritos): se publica en la red pero
        // solo la ve ese técnico, que la cotiza y sigue el flujo normal. Se valida antes de subir fotos.
        $tecnicoDirigidoId = $request->tecnico_dirigido_id ? (int) $request->tecnico_dirigido_id : null;
        if ($tecnicoDirigidoId) {
            $solicitante = $request->user();
            if (!$solicitante || !\App\Http\Controllers\MisTecnicosController::esFavorito((int) $solicitante->id, $tecnicoDirigidoId)) {
                return response()->json(['success' => false, 'message' => 'Solo puedes enviar solicitudes directas a tus técnicos favoritos.'], 422);
            }
            // El técnico de cuadrilla (8) no recibe solicitudes directas: trabaja para su contratista
            $dirigido = \App\Models\User::withoutGlobalScopes()->find($tecnicoDirigidoId);
            if (!$dirigido || !\App\Services\RolesRed::cotiza($dirigido->role_id)) {
                return response()->json(['success' => false, 'message' => 'Este técnico trabaja en la cuadrilla de un contratista y no recibe solicitudes directas. Publica tu solicitud en la red.'], 422);
            }
        }

        // 2. Procesar las imágenes con Cloudinary (solo se conecta si el cliente adjuntó fotos)
        $cloudinary = ($request->hasFile('evidence_1') || $request->hasFile('evidence_2'))
            ? new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'))
            : null;

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

        $tecnicoId = $request->tecnico_id ?: ($request->assigned_technician_id ?: null);
        if ($tecnicoId) {
            $tecnicoId = intval($tecnicoId);
        }
        $publishNetwork = filter_var($request->publish_network, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if ($tecnicoDirigidoId) {
            $tecnicoId = null;
            $publishNetwork = 1;
        }
        $status = $tecnicoId ? 'Asignado' : 'Por Hacer';

        // 3. Insertar en la BD usando el Modelo Eloquent
        $workOrder = DB::transaction(function () use ($request, $path1, $path2, $tecnicoId, $status, $publishNetwork, $elementos, $tecnicoDirigidoId) {
            $orden = WorkOrder::create([
                'property_id' => $request->property_id,
                'type' => $request->type,
                'zone' => $request->zone,
                'equipment' => $request->equipment,
                'description' => $request->description,
                'batch_id' => $request->batch_id,
                'evidence_path' => $path1,
                'evidence_path_2' => $path2,
                'tecnico_id' => $tecnicoId,
                'status' => $status,
                // La columna es enum('Normal','Urgente'): cualquier otro valor tumbaría el INSERT
                'priority' => in_array($request->priority, ['Normal', 'Urgente'], true)
                    ? $request->priority
                    : ($request->type === 'SOS' ? 'Urgente' : 'Normal'),
                'scheduled_at' => $request->scheduled_at ? date('Y-m-d H:i:s', strtotime($request->scheduled_at)) : null,
                'publish_network' => $publishNetwork,
                'tecnico_dirigido_id' => $tecnicoDirigidoId,
            ]
            // La orden es de la propiedad: toma su tenant (si la crea un invitado de una propiedad compartida,
            // no debe quedar en el tenant del invitado). Sin tenant en la propiedad, se asigna el del usuario como antes.
            + (($tenantPropiedad = DB::table('properties')->where('id', $request->property_id)->value('tenant_id'))
                ? ['tenant_id' => (int) $tenantPropiedad]
                : [])
            + (\Illuminate\Support\Facades\Schema::hasColumn('work_orders', 'specialty_id')
                ? ['specialty_id' => $request->specialty_id ? (int) $request->specialty_id : null]
                : []));
            if ($elementos) {
                \App\Services\ElementosTrabajo::adjuntar($orden, $elementos, $request->user());
            }
            // Mercado: flujo nuevo, el horario va en la cotización (App\Services\HorarioMercado)
            if ($publishNetwork && \App\Services\HorarioMercado::listo()) {
                $urgente = $orden->priority === 'Urgente';
                $orden->forceFill([
                    'flujo_horario' => true,
                    'fecha_deseada' => $urgente || !$request->fecha_deseada
                        ? \App\Services\HorarioMercado::ahora()->format('Y-m-d')
                        : $request->fecha_deseada,
                    'hora_deseada' => $urgente ? null : ($request->hora_deseada ?: null),
                ])->save();
            }
            return $orden;
        });

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

            // Dueño e invitados de la propiedad (por ejemplo, si la solicitud la creó un invitado)
            $duenoPropiedadId = DB::table('properties')->join('clients', 'clients.id', '=', 'properties.client_id')
                ->where('properties.id', $workOrder->property_id)->value('clients.user_id');
            $notifiables = $notifiables->merge(\App\Services\AccesoPropiedad::conInvitados(
                $duenoPropiedadId ? User::withoutGlobalScopes()->find($duenoPropiedadId) : null,
                $workOrder->property_id
            ))->unique('id');

            $tecnicoAvisar = $tecnicoId ?: $tecnicoDirigidoId;
            if ($tecnicoAvisar) {
                $assignedTech = User::withoutGlobalScopes()->find($tecnicoAvisar);
                if ($assignedTech) {
                    $notifiables = $notifiables->merge([$assignedTech]);
                }
            }

            Notification::send($notifiables, new NewWorkOrderNotification($workOrder, $userName, $propertyName));
            \Log::info("Notificación enviada correctamente vía Eloquent.");
        } catch (\Exception $e) {
            \Log::error("Error enviando notificación de WorkOrder: " . $e->getMessage());
        }

        // Red: "Hay trabajos de tu especialidad" a los técnicos que la tienen (agrupado, ver AvisoTrabajosRed).
        // Corre después de responder para no hacer esperar al cliente.
        $publicadoPor = $request->user()?->id;
        app()->terminating(fn () => \App\Services\AvisoTrabajosRed::nuevoTrabajo($workOrder->fresh(), $publicadoPor));

        return response()->json([
            'success' => true,
            'id' => $workOrder->id,
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

            // Solo el dueño del trabajo (o Root/Admin) acepta cotizaciones
            $ordenDeLaCotizacion = \App\Models\WorkOrder::withoutGlobalScopes()->find($quote->work_order_id);
            if (!\App\Http\Controllers\AjusteCotizacionController::puedeResponderPorDueno(auth('sanctum')->user(), $ordenDeLaCotizacion)) {
                return response()->json(['success' => false, 'message' => 'Solo el dueño del trabajo puede aceptar esta cotización'], 403);
            }

            // El técnico la retiró: ya no se puede aceptar
            if (!empty($quote->retirada_at)) {
                return response()->json(['success' => false, 'message' => 'El técnico retiró esta cotización.'], 422);
            }
            // Flujo nuevo: su horario ya está muy cerca o pasó; el técnico tiene que enviar uno nuevo
            if (\App\Services\HorarioMercado::esFlujoNuevo($ordenDeLaCotizacion) && \App\Services\HorarioMercado::estaVencida($quote)) {
                return response()->json(['success' => false, 'message' => 'Esta cotización venció porque su horario ya está muy cerca. Le avisamos al técnico para que envíe uno nuevo.'], 422);
            }
            // Trabajo cancelado: primero se reenvía a la red
            if ($ordenDeLaCotizacion && str_starts_with((string) $ordenDeLaCotizacion->status, 'Cancelado')) {
                return response()->json(['success' => false, 'message' => 'Este servicio está cancelado. Reenvíalo a la red para elegir una cotización.'], 422);
            }

            // Cotización anterior a un reenvío a la red: primero el técnico confirma que sigue disponible
            if (\App\Http\Controllers\RedCancelacionController::requiereConfirmacion($quote, $ordenDeLaCotizacion)) {
                return \App\Http\Controllers\RedCancelacionController::pedirConfirmacion($quote, $ordenDeLaCotizacion, auth('sanctum')->user());
            }

            \App\Http\Controllers\RedCancelacionController::asignarCotizacion($quote, auth('sanctum')->user());

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
    Route::post('/network-quotes/{id}/reject', function (\Illuminate\Http\Request $request, $id) {
        // El cliente explica por qué la rechaza (el técnico lo ve para mejorar su oferta)
        $datos = $request->validate(['motivo' => 'required|string|min:3|max:500']);

        $quote = \App\Models\NetworkQuote::withoutGlobalScopes()->find($id);
        if (!$quote) {
            return response()->json(['message' => 'Cotización no encontrada'], 404);
        }

        // Solo el dueño del trabajo (o Root/Admin) rechaza cotizaciones
        $ordenDeLaCotizacion = \App\Models\WorkOrder::withoutGlobalScopes()->find($quote->work_order_id);
        if (!\App\Http\Controllers\AjusteCotizacionController::puedeResponderPorDueno(auth('sanctum')->user(), $ordenDeLaCotizacion)) {
            return response()->json(['success' => false, 'message' => 'Solo el dueño del trabajo puede rechazar esta cotización'], 403);
        }

        $quote->status = 'rejected';
        if (\Illuminate\Support\Facades\Schema::hasColumn('network_quotes', 'motivo_rechazo')) {
            $quote->motivo_rechazo = trim($datos['motivo']);
        }
        $quote->save();

        if ($quote->technician) {
            $quote->technician->notify(new \App\Notifications\NetworkQuoteRejected($quote));
        }

        return response()->json(['success' => true, 'message' => 'Cotización rechazada exitosamente']);
    });

    // Obtener historial de chat de una Cotización de la Red
    Route::get('/network-quotes/{id}/chat', function ($id) {
        $quote = \App\Models\NetworkQuote::withoutGlobalScopes()->with(['technician', 'workOrder.property.client'])->find($id);
        // Solo el técnico que cotizó y el lado del cliente (dueño, invitados, Root/Admin). Antes cualquiera lo leía.
        if (!$quote || !\App\Services\AccesoPropiedad::puedeUsarChatRed(auth('sanctum')->user(), $quote)) {
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
        // Solo el técnico que cotizó y el lado del cliente (dueño, invitados, Root/Admin)
        if (!\App\Services\AccesoPropiedad::puedeUsarChatRed($user, $quote)) {
            return response()->json(['message' => 'Cotización no encontrada'], 404);
        }

        // Mis técnicos / Mis clientes: si cliente y técnico están bloqueados (cualquiera de los dos bloqueó), nadie escribe
        if (\App\Http\Controllers\MisTecnicosController::estaBloqueado((int)$quote->work_order_id, (int)$quote->technician_id)) {
            $esTecnico = \App\Services\RolesRed::esCualquierTecnico($user->role_id);
            return response()->json([
                'message' => $esTecnico ? 'Este cliente ya no recibe mensajes tuyos.' : 'Este técnico ya no recibe mensajes tuyos.'
            ], 403);
        }

        $senderRole = 'Usuario';
        // El contratista (7) escribe del lado del técnico: es quien cotizó
        if (in_array((int)$user->role_id, [3, 4, 5]))
            $senderRole = 'Cliente';
        elseif (\App\Services\RolesRed::esCualquierTecnico($user->role_id))
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
        if (\App\Services\RolesRed::esCualquierTecnico($user->role_id)) {
            // El técnico envió el mensaje -> notificar al Cliente / Autónomo dueño del reporte
            // Solo el dueño de la orden (no el primer usuario del tenant, que puede ser un Admin)
            // ...y a los invitados de la propiedad compartida
            foreach (\App\Services\AccesoPropiedad::interesadosDeOrden($quote->work_order_id, (int) $user->id) as $clientUserId) {
                $clientUser = \App\Models\User::withoutGlobalScopes()->find($clientUserId);
                if ($clientUser) \Illuminate\Support\Facades\Notification::send($clientUser, new \App\Notifications\NewNetworkQuoteChatMessageNotification($quote, $senderNameStr, 'Técnico'));
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
        $user = auth('sanctum')->user();
        if (!$user) return response()->json(['success' => false, 'message' => 'No autorizado'], 401);

        $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->with('property.client')->findOrFail($id);

        if (\App\Http\Controllers\MisTecnicosController::estaBloqueado((int)$workOrder->id, (int)$user->id)
            || \App\Http\Controllers\MisTecnicosController::dirigidaAOtro((int)$workOrder->id, (int)$user->id)) {
            return response()->json(['success' => false, 'message' => 'Este trabajo ya no está disponible para ti.'], 403);
        }

        // Técnico de cuadrilla (8): no cotiza; su contratista le asigna los trabajos
        if (\App\Services\RolesRed::esTecnico($user->role_id) && !\App\Services\RolesRed::cotiza($user->role_id)) {
            return response()->json(['success' => false, 'message' => 'Los técnicos de cuadrilla reciben sus trabajos de su contratista. Para cotizar por tu cuenta necesitas acceso a la red.'], 403);
        }
        if ((int) $user->role_id === \App\Services\Cuadrilla::ROL_CUADRILLA_CON_RED) {
            // Técnico 9: no compite con su contratista y, para cobrar por su cuenta, necesita sus datos de cobro
            $jefe = \App\Services\Cuadrilla::contratistaDe($user);
            if ($jefe && \App\Models\NetworkQuote::withoutGlobalScopes()->where('work_order_id', $workOrder->id)->where('technician_id', $jefe->id)->exists()) {
                return response()->json(['success' => false, 'message' => 'Tu contratista ya cotizó este trabajo.'], 403);
            }
            $faltaCobro = \App\Services\PerfilProfesional::tablaLista()
                ? \App\Services\PerfilProfesional::faltantesCobro(\App\Services\PerfilProfesional::perfilDe($user))
                : [];
            if (!empty($faltaCobro)) {
                return response()->json([
                    'success' => false,
                    'reason' => 'perfil_cobro_pendiente',
                    'message' => 'Para cotizar por tu cuenta, primero completa tus datos de cobro en tu perfil profesional: ' . implode(', ', $faltaCobro) . '.',
                ], 403);
            }
        }

        // Calificar al cliente de los trabajos terminados es obligatorio antes de seguir cotizando
        if (\App\Services\CalificacionCliente::tienePendientes($user)) {
            return response()->json([
                'success' => false,
                'reason' => 'calificacion_cliente_pendiente',
                'message' => 'Antes de cotizar, califica a los clientes de tus trabajos terminados.',
            ], 409);
        }

        // Validar restricción activa de 24h (DESACTIVADO TEMPORALMENTE A PETICIÓN DEL USUARIO PARA PRUEBAS)
        $isBlocked = false;
        /*
        $isBlocked = \Illuminate\Support\Facades\DB::table('technician_cancellations')
            ->where('technician_id', $user->id)
            ->where('expires_at', '>', now())
            ->where(function ($q) use ($workOrder) {
                $q->where('work_order_id', $workOrder->id);
                if ($workOrder->property?->client?->user_id) {
                    $q->orWhere('client_user_id', $workOrder->property->client->user_id);
                }
                $tId = $workOrder->tenant_id ?: ($workOrder->property?->tenant_id ?? null);
                if ($tId) {
                    $q->orWhere('tenant_id', $tId);
                }
            })
            ->exists();

        if ($isBlocked) {
            return response()->json([
                'success' => false,
                'message' => 'Tienes una restricción de 24 horas para cotizar a este usuario por haber cancelado un servicio recientemente.'
            ], 403);
        }
        */

        $request->validate([
            'price' => 'required|numeric|min:0',
            'message' => 'nullable|string',
            'horario' => 'nullable|string|max:25',
        ]);

        $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->with('property.client')->findOrFail($id);
        $user = auth('sanctum')->user();

        // Le quitaron este trabajo por no llegar: ya no puede volver a cotizarlo
        if (\App\Services\HorarioMercado::tecnicoNoLlego((int) $workOrder->id, (int) $user->id)) {
            return response()->json(['success' => false, 'message' => 'Este trabajo ya no está disponible para ti.'], 403);
        }

        // Flujo nuevo: la cotización lleva la hora exacta de la visita (máximo 2 cambios respecto a la del cliente)
        $horarioCotizacion = null;
        if (\App\Services\HorarioMercado::esFlujoNuevo($workOrder)) {
            $anterior = \App\Models\NetworkQuote::withoutGlobalScopes()
                ->where('work_order_id', $workOrder->id)->where('technician_id', $user->id)->first();
            if ($anterior && $anterior->status === 'accepted') {
                return response()->json(['success' => false, 'message' => 'Tu cotización ya fue aceptada.'], 422);
            }
            $horarioCotizacion = \App\Services\HorarioMercado::validarCotizacion($workOrder, $anterior, $request->input('horario'));
            if (isset($horarioCotizacion['error'])) {
                return response()->json(['success' => false, 'reason' => 'horario', 'message' => $horarioCotizacion['error']], 422);
            }
        }

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
        // Cotizar (o recotizar) la deja vigente: ya no está retirada ni pide confirmación
        if (\App\Http\Controllers\RedCancelacionController::hayColumnasNuevas()) {
            $quote->forceFill(['cotizada_at' => now(), 'retirada_at' => null, 'confirmacion_pedida_at' => null])->save();
        }
        // La nueva cotización ya no arrastra el motivo del rechazo anterior
        if (!empty($quote->motivo_rechazo)) {
            $quote->forceFill(['motivo_rechazo' => null])->save();
        }
        if ($horarioCotizacion) {
            $quote->forceFill([
                'horario_propuesto' => \App\Services\HorarioMercado::guardar($horarioCotizacion['horario']),
                'cambios_horario' => $horarioCotizacion['cambios'],
                'vencida_at' => null,
            ])->save();
        }

        // Notificar al dueño de la orden de trabajo (el Autónomo o Cliente)
        try {
            // Solo el dueño de la orden (no el primer usuario del tenant, que puede ser un Admin)
            // ...y a los invitados de la propiedad compartida
            $techName = trim("{$user->first_name} {$user->last_name}") ?: $user->name;
            $propName = $workOrder->property ? ($workOrder->property->property_name ?: $workOrder->property->address) : 'Propiedad';
            foreach (\App\Services\AccesoPropiedad::interesadosDeOrden($workOrder->id, (int) $user->id) as $ownerId) {
                $owner = \App\Models\User::withoutGlobalScopes()->find($ownerId);
                if ($owner) \Illuminate\Support\Facades\Notification::send($owner, new \App\Notifications\NetworkQuoteReceived($quote, $techName, $propName));
            }
        } catch (\Throwable $e) {
            \Log::error("Error enviando notificación de cotización: " . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => $horarioCotizacion
                ? 'Cotización enviada con el horario del ' . \App\Services\HorarioMercado::texto($horarioCotizacion['horario']) . '.'
                    . (($restan = max(0, \App\Services\HorarioMercado::MAX_CAMBIOS - $horarioCotizacion['cambios'])) === 1
                        ? ' Te queda 1 cambio de horario.'
                        : " Te quedan {$restan} cambios de horario.")
                : 'Cotización enviada con éxito',
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

            // Técnico de cuadrilla (8): el cliente habla con su contratista, no con él
            if (\App\Services\RolesRed::esTecnico($user->role_id) && !\App\Services\RolesRed::cotiza($user->role_id)) {
                return response()->json(['success' => false, 'message' => 'Los técnicos de cuadrilla no chatean con los clientes: de eso se encarga tu contratista.'], 403);
            }
            if (\App\Http\Controllers\MisTecnicosController::estaBloqueado((int)$workOrder->id, (int)$user->id)) {
                return response()->json(['success' => false, 'message' => 'Este cliente ya no recibe mensajes tuyos.'], 403);
            }
            if (\App\Http\Controllers\MisTecnicosController::dirigidaAOtro((int)$workOrder->id, (int)$user->id)
                || \App\Services\HorarioMercado::tecnicoNoLlego((int)$workOrder->id, (int)$user->id)) {
                return response()->json(['success' => false, 'message' => 'Este trabajo ya no está disponible para ti.'], 403);
            }

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
                    // Sin texto: el mensaje es la propuesta del técnico y la escribe al cotizar
                    'message' => null,
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
            if (\App\Http\Controllers\MisTecnicosController::dirigidaAOtro((int)$workOrder->id, (int)$user->id)) {
                return response()->json(['success' => false, 'message' => 'Este trabajo ya no está disponible para ti.'], 403);
            }
            // Flujo nuevo: el horario se acordó al aceptar; solo queda 1 re-programación que el cliente aprueba
            if (\App\Services\HorarioMercado::esFlujoNuevo($workOrder)) {
                return \App\Http\Controllers\HorarioMercadoController::proponerReprogramacion($request, $workOrder, $user);
            }
            // Buscar la cotización aceptada o del técnico para registrar en el chat
            $quote = \App\Models\NetworkQuote::withoutGlobalScopes()
                ->where('work_order_id', $workOrder->id)
                ->where('technician_id', $user->id)
                ->first();

            // Máximo 2 oportunidades de agendar (agendar + 1 cambio) para evitar "pelotear" al cliente.
            // Si el cliente pidió otro horario, la nueva propuesta responde a su petición y no gasta oportunidad.
            $maxAgendados = 2;
            $usadas = 0;
            $clientePidioOtro = false;
            $huboPropuesta = false;
            foreach (($quote?->chat_history ?? []) as $m) {
                if (!empty($m['is_schedule'])) {
                    $huboPropuesta = true;
                    if (!$clientePidioOtro) $usadas++;
                    $clientePidioOtro = false;
                } elseif (!empty($m['is_schedule_response'])) {
                    $clientePidioOtro = ($m['schedule_confirmed'] ?? null) === false;
                }
            }
            if (!$clientePidioOtro && $usadas >= $maxAgendados) {
                return response()->json([
                    'success' => false,
                    'reason' => 'limite_agendados',
                    'message' => 'Ya usaste tus 2 oportunidades para agendar esta visita. Solo podrás proponer otro horario si el cliente lo solicita.',
                ], 422);
            }

            $horarioAnterior = $workOrder->scheduled_at;
            $esModificacion = $huboPropuesta || !empty($horarioAnterior);

            $scheduledDateTime = date('Y-m-d H:i:s', strtotime($request->scheduled_at));
            $workOrder->scheduled_at = $scheduledDateTime;
            if (empty($workOrder->tecnico_id)) {
                $workOrder->tecnico_id = $user->id;
            }
            $workOrder->save();

            $formattedTime = date('d/m/Y \a \l\a\s h:i A', strtotime($scheduledDateTime));
            $techName = $user->first_name ? "{$user->first_name} {$user->last_name}" : ($user->name ?: 'El Técnico');
            $nota = $request->notes ? " Nota: \"{$request->notes}\"" : "";
            if ($esModificacion && $horarioAnterior) {
                $antes = date('d/m/Y \a \l\a\s h:i A', strtotime($horarioAnterior));
                $msgText = "🗓️ {$techName} modificó el horario de la visita: antes {$antes}, ahora {$formattedTime}." . $nota;
            } else {
                $msgText = "📅 {$techName} ha programado la visita para el {$formattedTime}." . $nota;
            }
            $restantes = $clientePidioOtro ? max(0, $maxAgendados - $usadas) : max(0, $maxAgendados - $usadas - 1);

            if ($quote) {
                $history = $quote->chat_history ?? [];
                $history[] = [
                    'sender_id' => $user->id,
                    'sender_name' => $techName,
                    'sender_role' => 'Técnico de la Red',
                    'message' => $msgText,
                    'is_schedule' => true,
                    'is_modification' => $esModificacion,
                    'previous_scheduled_at' => $horarioAnterior,
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
                // 3. Si no hay cliente ligado, el dueño de la orden (nunca Root/Admin ni todo el tenant)
                if ($clientUsers->isEmpty()) {
                    $duenoId = \App\Http\Controllers\MisClientesController::duenoDeOrden((int) $workOrder->id);
                    $u = $duenoId ? \App\Models\User::withoutGlobalScopes()->find($duenoId) : null;
                    if ($u) $clientUsers->push($u);
                }
                // 4. Invitados de la propiedad compartida
                foreach (\App\Services\AccesoPropiedad::invitadosDeOrden($workOrder->id) as $invitadoId) {
                    $u = \App\Models\User::withoutGlobalScopes()->find($invitadoId);
                    if ($u) $clientUsers->push($u);
                }

                $clientUsers = $clientUsers->unique('id');

                $title = $workOrder->type . ($workOrder->equipment ? ' - ' . $workOrder->equipment : '');
                $propName = $workOrder->property ? ($workOrder->property->nombre_propiedad ?: $workOrder->property->address) : 'Propiedad';

                // Un solo aviso claro por acción: "Visita programada" o "Modificación del trabajo"
                // (antes también salía un "Nuevo mensaje en la Red" que confundía al cliente)
                foreach ($clientUsers as $targetClient) {
                    try {
                        $targetClient->notify($esModificacion
                            ? new \App\Notifications\HorarioModificadoRedNotification($workOrder, $techName, $horarioAnterior, $request->notes)
                            : new \App\Notifications\WorkOrderScheduledNotification($workOrder, $techName, $propName));
                    } catch (\Throwable $ne) {
                        \Log::warning("Notification error: " . $ne->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                \Log::error("Error notificando fecha programada: " . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => ($esModificacion ? "Horario modificado al {$formattedTime}. Le avisamos al cliente." : "Visita programada para el {$formattedTime} correctamente.")
                    . ($restantes === 0 ? " Ya no podrás cambiarlo de nuevo, salvo que el cliente pida otro horario." : ""),
                'scheduled_at' => $scheduledDateTime,
                'cambios_restantes' => $restantes,
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

            // Flujo nuevo: aprobar (confirm) o rechazar (reschedule) la re-programación del técnico
            if (\App\Services\HorarioMercado::esFlujoNuevo($workOrder)) {
                return \App\Http\Controllers\HorarioMercadoController::responderReprogramacion($request, $workOrder, $user);
            }

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
                    'note' => $isConfirmed ? null : ($request->message ?: null),
                    'created_at' => now()->toIso8601String(),
                ];
                $quote->chat_history = $history;
                $quote->save();

                // Notificar al Técnico con un aviso específico de horario (no como mensaje genérico)
                $tecnico = $quote->technician;
                if ($tecnico) {
                    try {
                        $tecnico->notify(new \App\Notifications\VisitaRespondidaClienteNotification(
                            $workOrder, $clientName, $isConfirmed, $request->message
                        ));
                    } catch (\Throwable $ne) {
                        \Log::warning("No se pudo notificar respuesta de horario: " . $ne->getMessage());
                    }
                }

                // Visita confirmada: los técnicos de cuadrilla que van también se enteran de la fecha
                if ($isConfirmed && $workOrder) {
                    $fecha = $workOrder->scheduled_at ? \Carbon\Carbon::parse($workOrder->scheduled_at)->format('d/m/Y H:i') : null;
                    \App\Services\Cuadrilla::avisarEquipo($workOrder, '📅 Visita confirmada',
                        'El cliente confirmó la visita de "' . \App\Services\Cuadrilla::nombreTrabajo($workOrder) . '"' . ($fecha ? " para el {$fecha}." : '.'));
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
    
    // Cancelar servicio por parte del Técnico con motivo y penalización de 24h
    Route::post('/mercado-trabajos/{id}/cancelar-tecnico', function (Request $request, $id) {
        $request->validate([
            'reason' => 'nullable|string|max:1000'
        ]);

        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
            }

            $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->with(['property.client', 'networkQuotes'])->findOrFail($id);

            // Solo el técnico titular (o Root/Admin) cancela; antes cualquiera con sesión podía cancelar cualquier trabajo
            if (!in_array((int) $user->role_id, [0, 1], true) && !\App\Services\Cuadrilla::esTitular($user, $workOrder)) {
                $mensaje = \App\Services\Cuadrilla::esDelEquipo($user, (int) $workOrder->id)
                    ? 'Este trabajo es de tu contratista: pídele que lo cancele o que mande a otro técnico.'
                    : 'Solo el técnico asignado puede cancelar este trabajo.';
                return response()->json(['success' => false, 'message' => $mensaje], 403);
            }
            // Su cuadrilla ya no va: se le avisa y se libera el trabajo
            \App\Services\Cuadrilla::avisarEquipo($workOrder, 'Trabajo cancelado', 'Tu contratista canceló "' . \App\Services\Cuadrilla::nombreTrabajo($workOrder) . '". Ya no tienes que ir.', [(int) $user->id]);
            \Illuminate\Support\Facades\DB::table('work_order_technician')->where('work_order_id', $workOrder->id)->delete();

            $clientUserId = $workOrder->property?->client?->user_id;
            if (!$clientUserId && $workOrder->property?->client?->email) {
                $u = \App\Models\User::withoutGlobalScopes()->where('email', $workOrder->property->client->email)->first();
                if ($u) $clientUserId = $u->id;
            }
            $tenantId = $workOrder->tenant_id ?: ($workOrder->property?->tenant_id ?? null);
            $reason = $request->input('reason') ?: 'Cancelado por el técnico por causas de fuerza mayor o imprevisto.';

            // 1. Registrar bloqueo de 24 horas para el técnico hacia este cliente/inmueble
            \Illuminate\Support\Facades\DB::table('technician_cancellations')->insert([
                'technician_id'   => $user->id,
                'work_order_id'   => $workOrder->id,
                'client_user_id'  => $clientUserId,
                'tenant_id'       => $tenantId,
                'reason'          => $reason,
                'cancelled_at'    => now(),
                'expires_at'      => now()->addHours(24),
                'rated'           => false,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // 2. Actualizar la orden de trabajo
            $workOrder->cancelled_by_tech = true;
            $workOrder->cancelled_technician_id = $user->id;
            $workOrder->cancellation_reason = $reason;
            $workOrder->cancelled_at = now();
            $workOrder->status = 'Cancelado_Tecnico';
            $workOrder->tecnico_id = null;
            $workOrder->save();

            // 3. Registrar mensaje en el chat de la cotización si existe
            $quote = \App\Models\NetworkQuote::withoutGlobalScopes()
                ->where('work_order_id', $workOrder->id)
                ->where('technician_id', $user->id)
                ->first();

            $techName = trim("{$user->first_name} {$user->last_name}") ?: ($user->name ?: 'El Técnico');

            if ($quote) {
                $quote->status = 'rejected';
                $history = $quote->chat_history ?? [];
                $history[] = [
                    'sender_id' => $user->id,
                    'sender_name' => $techName,
                    'sender_role' => 'Técnico de la Red',
                    'message' => "🚫 [CANCELACIÓN DE SERVICIO]: He cancelado mi asistencia para este servicio. Motivo: \"{$reason}\"",
                    'is_cancellation' => true,
                    'created_at' => now()->toIso8601String(),
                ];
                $quote->chat_history = $history;
                $quote->save();
            }

            // 4. Notificar a los clientes / propietarios
            try {
                $clientUsers = collect();
                // Solo el dueño (nunca Root/Admin ni todos los usuarios del tenant)
                $duenoId = $clientUserId ?: \App\Http\Controllers\MisClientesController::duenoDeOrden((int) $workOrder->id);
                $u = $duenoId ? \App\Models\User::withoutGlobalScopes()->find($duenoId) : null;
                if ($u) $clientUsers->push($u);
                // ...y los invitados de la propiedad compartida
                foreach (\App\Services\AccesoPropiedad::invitadosDeOrden($workOrder->id) as $invitadoId) {
                    $u = \App\Models\User::withoutGlobalScopes()->find($invitadoId);
                    if ($u) $clientUsers->push($u);
                }
                $clientUsers = $clientUsers->unique('id');

                foreach ($clientUsers as $targetClient) {
                    try {
                        $targetClient->notify(new \App\Notifications\WorkOrderCancelledNotification($workOrder, 'client'));
                    } catch (\Throwable $ne) {}
                }
            } catch (\Throwable $e) {
                \Log::error("Error notificando cancelación del técnico: " . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Servicio cancelado. Se ha notificado al cliente y aplicado la restricción de 24 horas.',
                'work_order' => $workOrder
            ]);
        } catch (\Throwable $e) {
            \Log::error("Error cancelando servicio por técnico: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    })->middleware('auth:sanctum');

    // Obtener cancelaciones de técnicos pendientes de calificación para el cliente autenticado
    Route::get('/client/pending-cancellations', function (Request $request) {
        try {
            $user = auth('sanctum')->user();
            if (!$user) return response()->json(['success' => false, 'cancellations' => []]);

            $cancellations = \Illuminate\Support\Facades\DB::table('technician_cancellations')
                ->where('rated', false)
                ->where(function ($q) use ($user) {
                    $q->where('client_user_id', $user->id);
                    if ($user->tenant_id) {
                        $q->orWhere('tenant_id', $user->tenant_id);
                    }
                    // Propiedades que le compartieron: también califica al técnico como dueño
                    $compartidas = \App\Services\AccesoPropiedad::compartidasCon($user);
                    if (!empty($compartidas)) {
                        $q->orWhereIn('work_order_id', \Illuminate\Support\Facades\DB::table('work_orders')->whereIn('property_id', $compartidas)->select('id'));
                    }
                })
                ->orderBy('cancelled_at', 'desc')
                ->get();

            $result = [];
            foreach ($cancellations as $canc) {
                $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->with('property.client')->find($canc->work_order_id);
                // Si la orden ya no existe o ya no tiene la marca de cancelada por técnico
                if (!$workOrder || (!$workOrder->cancelled_by_tech && $workOrder->status !== 'Cancelado_Tecnico')) {
                    \Illuminate\Support\Facades\DB::table('technician_cancellations')
                        ->where('id', $canc->id)
                        ->update(['rated' => true, 'updated_at' => now()]);
                    continue;
                }

                $tech = \App\Models\User::withoutGlobalScopes()->find($canc->technician_id);

                $result[] = [
                    'cancellation_id'   => $canc->id,
                    'work_order_id'     => $canc->work_order_id,
                    'work_order_title'  => $workOrder ? ($workOrder->type . ($workOrder->equipment ? ' - ' . $workOrder->equipment : '')) : 'Servicio Solicitado',
                    'work_order'        => $workOrder,
                    'reason'            => $canc->reason,
                    'cancelled_at'      => $canc->cancelled_at,
                    'technician'        => $tech ? [
                        'id'              => $tech->id,
                        'name'            => trim("{$tech->first_name} {$tech->last_name}") ?: ($tech->name ?: 'Técnico Especialista'),
                        'picture'         => $tech->profile_picture,
                        'specialty'       => 'Técnico de la Red',
                        'rating_time_avg' => $tech->rating_time_avg ?? 5.0
                    ] : null
                ];
            }

            return response()->json([
                'success' => true,
                'cancellations' => $result
            ]);
        } catch (\Throwable $e) {
            \Log::error("Error obteniendo cancelaciones pendientes: " . $e->getMessage());
            return response()->json(['success' => false, 'cancellations' => []]);
        }
    })->middleware('auth:sanctum');

    // Resolver orden de trabajo cancelada (Reenviar a la Red o Borrar)
    Route::post('/client/resolve-cancelled-work-order/{id}', function (Request $request, $id) {
        $request->validate([
            'action' => 'required|in:reopen,delete'
        ]);

        try {
            $user = auth('sanctum')->user();
            $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->with(['property.client', 'networkQuotes'])->findOrFail($id);
            if (!\App\Http\Controllers\AjusteCotizacionController::puedeResponderPorDueno($user, $workOrder)) {
                return response()->json(['success' => false, 'message' => 'Solo el dueño del trabajo puede hacer esto'], 403);
            }
            $hayColumnasRed = \App\Http\Controllers\RedCancelacionController::hayColumnasNuevas();
            // Si el técnico canceló, primero el cliente lo califica
            if ($faltaCalificar = \App\Http\Controllers\RedCancelacionController::exigirCalificacionDelTecnico((int) $workOrder->id)) {
                return $faltaCalificar;
            }

            // Marcar cualquier registro de cancelación como evaluado/resuelto
            \Illuminate\Support\Facades\DB::table('technician_cancellations')
                ->where('work_order_id', $workOrder->id)
                ->update(['rated' => true, 'updated_at' => now()]);

            if ($request->action === 'reopen') {
                $workOrder->status = 'Por Hacer';
                $workOrder->tecnico_id = null;
                $workOrder->scheduled_at = null;
                $workOrder->cancelled_by_tech = false;
                $workOrder->cancelled_technician_id = null;
                $workOrder->publish_network = 1;
                if ($hayColumnasRed) {
                    $workOrder->cancelled_by_client = false;
                    $workOrder->reabierta_at = now(); // las cotizaciones anteriores piden confirmación al técnico
                }
                $workOrder->save();

                \App\Models\NetworkQuote::withoutGlobalScopes()
                    ->where('work_order_id', $workOrder->id)
                    ->where('status', 'accepted')
                    ->update(['status' => 'rejected']);

                return response()->json([
                    'success' => true,
                    'message' => '¡Solicitud reenviada a la Red! Puedes elegir una de las cotizaciones vigentes o esperar nuevas.',
                    'work_order' => $workOrder
                ]);
            } else {
                $workOrder->status = 'Cancelado';
                $workOrder->cancelled_by_tech = false;
                $workOrder->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Servicio eliminado correctamente.'
                ]);
            }
        } catch (\Throwable $e) {
            \Log::error("Error resolviendo orden cancelada: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    })->middleware('auth:sanctum');

    // Descartar aviso de cancelación para que no vuelva a aparecer
    Route::post('/client/dismiss-cancellation/{id}', function (Request $request, $id) {
        try {
            // La calificación es obligatoria: solo se descarta si el cliente ya calificó al técnico
            $registros = \Illuminate\Support\Facades\DB::table('technician_cancellations')
                ->where('id', $id)
                ->orWhere('work_order_id', $id)
                ->get();
            foreach ($registros as $r) {
                $calificado = \Illuminate\Support\Facades\DB::table('technician_reviews')
                    ->where('work_order_id', $r->work_order_id)->where('technician_id', $r->technician_id)->exists();
                if ($calificado) {
                    \Illuminate\Support\Facades\DB::table('technician_cancellations')->where('id', $r->id)
                        ->update(['rated' => true, 'updated_at' => now()]);
                }
            }
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false], 500);
        }
    })->middleware('auth:sanctum');

    // Reenviar a la Red una orden cancelada por técnico
    Route::post('/mercado-trabajos/{id}/reabrir-red', function (Request $request, $id) {
        try {
            $user = auth('sanctum')->user();
            $workOrder = \App\Models\WorkOrder::withoutGlobalScopes()->with(['property.client', 'networkQuotes'])->findOrFail($id);
            if (!\App\Http\Controllers\AjusteCotizacionController::puedeResponderPorDueno($user, $workOrder)) {
                return response()->json(['success' => false, 'message' => 'Solo el dueño del trabajo puede reenviarlo a la red'], 403);
            }
            // Si el técnico canceló, primero el cliente lo califica
            if ($faltaCalificar = \App\Http\Controllers\RedCancelacionController::exigirCalificacionDelTecnico((int) $workOrder->id)) {
                return $faltaCalificar;
            }

            // Resetear orden para que vuelva a estar disponible en la red
            $workOrder->status = 'Por Hacer';
            $workOrder->tecnico_id = null;
            $workOrder->scheduled_at = null;
            $workOrder->cancelled_by_tech = false;
            $workOrder->publish_network = 1;
            $workOrder->tecnico_dirigido_id = null; // ya no es solo para su técnico favorito: la ve toda la red
            if (\App\Http\Controllers\RedCancelacionController::hayColumnasNuevas()) {
                $workOrder->cancelled_by_client = false;
                $workOrder->reabierta_at = now(); // las cotizaciones anteriores piden confirmación al técnico
            }
            $workOrder->save();

            // Rechazar cotizaciones previas asignadas
            \App\Models\NetworkQuote::withoutGlobalScopes()
                ->where('work_order_id', $workOrder->id)
                ->where('status', 'accepted')
                ->update(['status' => 'rejected']);

            // Vuelve a estar disponible: cuenta para el aviso "Hay trabajos de tu especialidad"
            $reenviadoPor = $user?->id;
            app()->terminating(fn () => \App\Services\AvisoTrabajosRed::nuevoTrabajo($workOrder->fresh(), $reenviadoPor));

            return response()->json([
                'success' => true,
                'message' => '¡Solicitud reenviada a la Red! Puedes elegir una de las cotizaciones vigentes o esperar nuevas.',
                'work_order' => $workOrder
            ]);
        } catch (\Throwable $e) {
            \Log::error("Error reenviando orden a la red: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    })->middleware('auth:sanctum');

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
                        elseif (\App\Services\AccesoPropiedad::esInvitado($user, $service->property_id)) $isOwner = true;
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
                // Propiedad compartida: el invitado opera las solicitudes como dueño
                elseif (\App\Services\AccesoPropiedad::esInvitado($user, $workOrder->property_id)) $isOwner = true;
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

    Route::get('/work-orders/all', function () {
        $user = auth('sanctum')->user();
        $query = \App\Models\WorkOrder::withoutGlobalScopes()->with([
            'property.client',
            'tecnico' => fn($q) => $q->withoutGlobalScopes(),
            'technicians' => fn($q) => $q->withoutGlobalScopes(),
            'networkQuotes' => fn($q) => $q->withoutGlobalScopes(),
            'networkQuotes.technician' => fn($q) => $q->withoutGlobalScopes()
        ])->withCount('workReports'); // work_reports_count: el tablero sabe si ya hay reporte

        if ($user && $user->role_id == 4) {
            $query->where(function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id)
                    ->orWhereHas('property', function ($qp) use ($user) {
                        $qp->where('tenant_id', $user->tenant_id);
                    })
                    ->orWhereHas('tecnico', function ($qt) use ($user) {
                        $qt->where('tenant_id', $user->tenant_id);
                    })
                    // Propiedades que le compartieron: las usa como dueño
                    ->orWhereIn('property_id', \App\Services\AccesoPropiedad::compartidasCon($user));
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
            'workOrder.property.client',
            'workOrder.closer:id,first_name,last_name',
            'workOrder.elementos:id,work_order_id,label,estado'
        ]);

        if ($user && \App\Services\Cuadrilla::esContratista($user)) {
            // Contratista: sus reportes y los de su cuadrilla en SUS trabajos (no los trabajos propios de un técnico 9)
            $query->where(function ($q) use ($user) {
                $q->where('technician_id', $user->id)
                  ->orWhereHas('workOrder', fn ($qw) => $qw->withoutGlobalScopes()->where('tecnico_id', $user->id));
            });
        } elseif ($user && \App\Services\RolesRed::esCualquierTecnico($user->role_id)) {
            $query->where(function ($q) use ($user) {
                $q->where('technician_id', $user->id)
                  ->orWhere('user_id', $user->id)
                  ->orWhereHas('technician', function ($qt) use ($user) {
                      $qt->where('id', $user->id)->orWhere('email', $user->email);
                  })
                  ->orWhereHas('service', function ($qs) use ($user) {
                      $qs->where('technician_id', $user->id)
                         ->orWhere('assigned_technician_id', $user->id)
                         ->orWhere('user_id', $user->id);
                  })
                  ->orWhereHas('workOrder', function ($qw) use ($user) {
                      $qw->where('technician_id', $user->id)
                         ->orWhere('assigned_technician_id', $user->id)
                         ->orWhere('user_id', $user->id);
                  });
            });
        } elseif ($user && $user->role_id == 4) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('technician', function ($qt) use ($user) {
                    $qt->where('tenant_id', $user->tenant_id)->orWhere('id', $user->id);
                })->orWhereHas('service', function ($qs) use ($user) {
                    $qs->where('tenant_id', $user->tenant_id);
                })->orWhereHas('workOrder', function ($qw) use ($user) {
                    $qw->where('tenant_id', $user->tenant_id);
                })->orWhereHas('workOrder', function ($qw) use ($user) {
                    // Propiedades que le compartieron: ve sus reportes como dueño
                    $qw->withoutGlobalScopes()->whereIn('property_id', \App\Services\AccesoPropiedad::compartidasCon($user));
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

    // Asistente de ayuda con IA para clientes (además del límite diario del controlador)
    Route::post('/asistente/ayuda', [\App\Http\Controllers\AsistenteController::class, 'ayuda'])->middleware('throttle:10,1');
});

