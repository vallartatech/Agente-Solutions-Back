<?php

namespace App\Http\Controllers;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use App\Models\Quote;
use App\Models\User;
use App\Models\Service; // Asegúrate de importar el modelo Service
use App\Models\WorkOrder;
use App\Notifications\QuoteStatusUpdated;
use App\Notifications\QuotePaymentReceived;
use App\Notifications\QuotePaymentValidated;
use App\Notifications\TechnicianQuoteSubmitted;
use App\Notifications\TechnicianQuoteUpdated;

class QuoteController extends Controller
{
    public function store(Request $request)
    {
        try {
            // Normalización inteligente de work_order_id y service_id (soporte para batch_ y reasignación cruzada)
            if ($request->filled('work_order_id')) {
                $val = $request->input('work_order_id');
                if (is_string($val) && str_starts_with($val, 'batch_')) {
                    $batchId = str_replace('batch_', '', $val);
                    $firstOrder = \App\Models\WorkOrder::where('batch_id', $batchId)->first();
                    if ($firstOrder) {
                        $allIds = \App\Models\WorkOrder::where('batch_id', $batchId)->pluck('id')->toArray();
                        $request->merge([
                            'work_order_id' => $firstOrder->id,
                            'related_service_ids' => $request->has('related_service_ids') ? $request->related_service_ids : $allIds,
                            'is_unified_batch' => true
                        ]);
                    } else {
                        $request->merge(['work_order_id' => null]);
                    }
                } elseif (!\App\Models\WorkOrder::where('id', $val)->exists()) {
                    if (\App\Models\Service::where('id', $val)->exists() && !$request->filled('service_id')) {
                        $request->merge([
                            'service_id' => $val,
                            'work_order_id' => null
                        ]);
                    } else {
                        $request->merge(['work_order_id' => null]);
                    }
                }
            }
            if ($request->filled('service_id')) {
                $valServ = $request->input('service_id');
                if (!\App\Models\Service::where('id', $valServ)->exists()) {
                    if (\App\Models\WorkOrder::where('id', $valServ)->exists() && !$request->filled('work_order_id')) {
                        $request->merge([
                            'work_order_id' => $valServ,
                            'service_id' => null
                        ]);
                    } else {
                        $request->merge(['service_id' => null]);
                    }
                }
            }

            // Validamos lo básico
            $request->validate([
                'service_id' => 'nullable|exists:services,id',
                'work_order_id' => 'nullable|exists:work_orders,id',
                'type' => 'required|in:manual,archivo',
            ]);

            $user = auth('sanctum')->user() ?: auth()->user();
            $quote = new Quote();
            $quote->service_id = $request->service_id;
            $quote->work_order_id = $request->work_order_id;
            $quote->type = $request->type;
            if ($request->has('related_service_ids')) {
                $relatedIds = is_string($request->related_service_ids) ? json_decode($request->related_service_ids, true) : $request->related_service_ids;
                $quote->related_service_ids = is_array($relatedIds) ? $relatedIds : null;
            }
            if ($request->has('is_unified_batch')) {
                $quote->is_unified_batch = filter_var($request->is_unified_batch, FILTER_VALIDATE_BOOLEAN);
            }

            // Si el usuario es técnico (rol 2), el estado es "Pendiente de Admin"
            if ($user && $user->role_id === 2) {
                $quote->status = 'Pendiente de Admin';
                $quote->created_by_role = 'Técnico';
            } else {
                $quote->status = 'Pendiente'; // Estado por defecto para Admin
                $quote->created_by_role = 'Admin';
            }

            // Si es manual, guardamos los textos
            if ($request->type === 'manual') {
                $quote->concept = is_string($request->concept) ? json_decode($request->concept, true) : $request->concept;
                $quote->estimated_amount = $request->estimated_amount;
                $quote->validity_days = $request->validity_days ?? 15;
                $quote->observations = $request->observations;
                $quote->internal_observations = $request->internal_observations;

                if ($request->hasFile('evidence_photo')) {
                    $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
                    $respuestaNube = $cloudinary->uploadApi()->upload($request->file('evidence_photo')->getRealPath(), [
                        'folder' => 'cotizaciones_evidence'
                    ]);
                    $quote->evidence_photo_path = $respuestaNube['secure_url'];
                }
            }
            // Si es archivo, subimos el documento
            else {
                if ($request->hasFile('file')) {
                    $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));

                    $respuestaNube = $cloudinary->uploadApi()->upload($request->file('file')->getRealPath(), [
                        'folder' => 'cotizaciones_pdf',
                        'resource_type' => 'raw'
                    ]);

                    $quote->file_path = $respuestaNube['secure_url'];
                } else {
                    return response()->json(['error' => 'No se adjuntó ningún archivo'], 400);
                }
            }

            $quote->save();

            // Si se está basando en otra cotización (parent_id), marcamos la original como procesada/aceptada
            if ($request->parent_id) {
                $quote->parent_id = $request->parent_id;
                $parent = Quote::find($request->parent_id);
                if ($parent) {
                    $parent->status = 'Procesada por Admin';
                    $parent->save();
                }
            }

            // Notificar a los administradores / autónomos si la crea un técnico
            if ($user && $user->role_id === 2) {
                $tenantId = $quote->tenant_id ?? $user->tenant_id ?? null;
                if (!$tenantId && $quote->service_id) {
                    $service = \App\Models\Service::find($quote->service_id);
                    $tenantId = $service->tenant_id ?? ($service->property ? $service->property->tenant_id : null) ?? null;
                } elseif (!$tenantId && $quote->work_order_id) {
                    $workOrder = \App\Models\WorkOrder::find($quote->work_order_id);
                    $tenantId = $workOrder->tenant_id ?? ($workOrder->property ? $workOrder->property->tenant_id : null) ?? null;
                }

                if ($tenantId) {
                    $destinatarios = \App\Models\User::whereIn('role_id', [0, 1, 4])
                        ->where('tenant_id', $tenantId)
                        ->get();
                    if ($destinatarios->isEmpty()) {
                        $destinatarios = \App\Models\User::whereIn('role_id', [0, 1])->get();
                    }
                } else {
                    $destinatarios = \App\Models\User::whereIn('role_id', [0, 1])->whereNull('tenant_id')->get();
                    if ($destinatarios->isEmpty()) {
                        $destinatarios = \App\Models\User::whereIn('role_id', [0, 1])->get();
                    }
                }

                \Illuminate\Support\Facades\Notification::send($destinatarios, new TechnicianQuoteSubmitted($quote));
            }

            // Solo notificar al cliente si la crea el Admin (rol 0 o 1)
            if ($user && in_array($user->role_id, [0, 1])) {
                // Intentamos obtener el usuario del cliente desde Servicio o desde Orden de Trabajo
                $quote->load(['service.property.client', 'workOrder.property.client']);

                $cliente = $quote->service->property->client ?? $quote->workOrder->property->client ?? null;

                if ($cliente && $cliente->user_id) {
                    $clienteUser = User::find($cliente->user_id);
                    if ($clienteUser) {
                        \Illuminate\Support\Facades\Notification::send($clienteUser, new \App\Notifications\NewQuoteAvailable($quote));
                    }
                }
            }

            return response()->json(['message' => 'Cotización guardada exitosamente', 'quote' => $quote], 201);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al guardar: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $originalQuote = Quote::findOrFail($id);
            $user = auth('sanctum')->user() ?: auth()->user();
            $isAdmin = $user && in_array($user->role_id, [0, 1]);

            // Si es un Admin editando la cotización de un técnico (y la original no es ya un borrador),
            // creamos una nueva cotización y vinculamos la original como 'Borrador Técnico'.
            if ($isAdmin && $originalQuote->created_by_role === 'Técnico' && $originalQuote->status !== 'Borrador Técnico') {
                $quote = new Quote();
                $quote->parent_id = $originalQuote->id;
                $quote->service_id = $originalQuote->service_id;
                $quote->work_order_id = $originalQuote->work_order_id;
                $quote->created_by_role = 'Admin';
                $quote->status = 'Pendiente'; // Lista para el cliente

                $originalQuote->status = 'Borrador Técnico';
                $originalQuote->save();
            } else {
                $quote = $originalQuote;
                if ($user && $user->role_id === 2) {
                    $quote->status = 'Pendiente de Admin';
                } else {
                    $quote->status = 'Pendiente';
                }
            }

            $quote->type = $request->type;

            if ($request->type === 'manual') {
                $quote->concept = is_string($request->concept) ? json_decode($request->concept, true) : $request->concept;
                $quote->estimated_amount = $request->estimated_amount;
                $quote->validity_days = $request->validity_days ?? 15;

                // Agregamos el nuevo comentario a las observaciones existentes
                if ($request->observations) {
                    $quote->observations = ($quote->observations ? $quote->observations . "\n\n" : "") . $request->observations;
                }

                if ($request->internal_observations) {
                    $quote->internal_observations = ($quote->internal_observations ? $quote->internal_observations . "\n\n" : "") . $request->internal_observations;
                }

                if ($request->hasFile('evidence_photo')) {
                    $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
                    $respuestaNube = $cloudinary->uploadApi()->upload($request->file('evidence_photo')->getRealPath(), [
                        'folder' => 'cotizaciones_evidence'
                    ]);
                    $quote->evidence_photo_path = $respuestaNube['secure_url'];
                }
            } else {
                if ($request->hasFile('file')) {
                    $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
                    $respuestaNube = $cloudinary->uploadApi()->upload($request->file('file')->getRealPath(), [
                        'folder' => 'cotizaciones_pdf',
                        'resource_type' => 'raw'
                    ]);
                    $quote->file_path = $respuestaNube['secure_url'];
                }
            }

            $quote->save();

            // Notificar a los administradores / autónomos si la actualiza un técnico
            if ($user && $user->role_id === 2) {
                $tenantId = $quote->tenant_id ?? $user->tenant_id ?? null;
                if (!$tenantId && $quote->service_id) {
                    $service = \App\Models\Service::find($quote->service_id);
                    $tenantId = $service->tenant_id ?? ($service->property ? $service->property->tenant_id : null) ?? null;
                } elseif (!$tenantId && $quote->work_order_id) {
                    $workOrder = \App\Models\WorkOrder::find($quote->work_order_id);
                    $tenantId = $workOrder->tenant_id ?? ($workOrder->property ? $workOrder->property->tenant_id : null) ?? null;
                }

                if ($tenantId) {
                    $destinatarios = \App\Models\User::whereIn('role_id', [0, 1, 4])
                        ->where('tenant_id', $tenantId)
                        ->get();
                    if ($destinatarios->isEmpty()) {
                        $destinatarios = \App\Models\User::whereIn('role_id', [0, 1])->get();
                    }
                } else {
                    $destinatarios = \App\Models\User::whereIn('role_id', [0, 1])->whereNull('tenant_id')->get();
                    if ($destinatarios->isEmpty()) {
                        $destinatarios = \App\Models\User::whereIn('role_id', [0, 1])->get();
                    }
                }

                \Illuminate\Support\Facades\Notification::send($destinatarios, new TechnicianQuoteUpdated($quote));
            }

            // Notificar al cliente si edita el Admin
            if ($user && in_array($user->role_id, [0, 1])) {
                $quote->load(['service.property.client', 'workOrder.property.client']);
                $cliente = $quote->service->property->client ?? $quote->workOrder->property->client ?? null;
                if ($cliente && $cliente->user_id) {
                    $clienteUser = User::find($cliente->user_id);
                    if ($clienteUser) {
                        \Illuminate\Support\Facades\Notification::send($clienteUser, new \App\Notifications\NewQuoteAvailable($quote));
                    }
                }
            }

            return response()->json(['message' => 'Cotización actualizada y reenviada', 'quote' => $quote], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al actualizar: ' . $e->getMessage()], 500);
        }
    }

    public function index()
    {
        try {
            $user = auth('sanctum')->user() ?: auth()->user();

            // Cargamos relaciones para soportar ambos flujos (Services y WorkOrders)
            $quotesQuery = Quote::with([
                'service.property.client',
                'service.technician',
                'service.technicians',
                'workOrder.property.client',
                'workOrder.tecnico',
                'workOrder.technicians',
                'cashConfirmedBy'
            ]);

            // Si es cliente (rol 3), filtrar por sus servicios o sus órdenes de trabajo en sus propiedades
            if ($user && $user->role_id === 3) {
                $clientIds = \App\Models\Client::where('user_id', $user->id)
                    ->orWhere('email', $user->email)
                    ->pluck('id')
                    ->toArray();

                $propertyIds = \App\Models\Property::whereIn('client_id', $clientIds)
                    ->pluck('id')
                    ->toArray();

                $quotesQuery = $quotesQuery->where(function ($q) use ($user, $clientIds, $propertyIds) {
                    $q->whereHas('service.property', function ($query) use ($user, $clientIds, $propertyIds) {
                        $query->whereIn('id', $propertyIds)
                              ->orWhereIn('client_id', $clientIds)
                              ->orWhereHas('client', function($cq) use ($user) {
                                  $cq->where('user_id', $user->id)->orWhere('email', $user->email);
                              });
                    })->orWhereHas('workOrder.property', function ($query) use ($user, $clientIds, $propertyIds) {
                        $query->whereIn('id', $propertyIds)
                              ->orWhereIn('client_id', $clientIds)
                              ->orWhereHas('client', function($cq) use ($user) {
                                  $cq->where('user_id', $user->id)->orWhere('email', $user->email);
                              });
                    })->orWhereHas('workOrder', function ($query) use ($propertyIds) {
                        $query->whereIn('property_id', $propertyIds);
                    })->orWhereHas('service', function ($query) use ($propertyIds) {
                        $query->whereIn('property_id', $propertyIds);
                    });
                })->where(function($q) {
                    $q->whereNull('created_by_role')->orWhere('created_by_role', '!=', 'Técnico');
                });
            } elseif ($user && $user->role_id === 4) {
                // Autónomo: solo ve cotizaciones de su empresa (o de propiedades de su empresa)
                $quotesQuery = $quotesQuery->where(function ($q) use ($user) {
                    $q->where('tenant_id', $user->tenant_id)
                        ->orWhereHas('service.property', function ($query) use ($user) {
                            $query->where('tenant_id', $user->tenant_id);
                        })->orWhereHas('workOrder.property', function ($query) use ($user) {
                            $query->where('tenant_id', $user->tenant_id);
                        });
                });
            } elseif ($user && $user->role_id !== 0 && $user->tenant_id) {
                $quotesQuery = $quotesQuery->where(function ($q) use ($user) {
                    $q->where('tenant_id', $user->tenant_id)
                        ->orWhereHas('service.property', function ($query) use ($user) {
                            $query->where('tenant_id', $user->tenant_id);
                        })->orWhereHas('workOrder.property', function ($query) use ($user) {
                            $query->where('tenant_id', $user->tenant_id);
                        });
                });
            }

            $quotes = $quotesQuery->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($quote) use ($user) {
                    // Obtenemos el cliente y técnico de la relación que esté disponible
                    $wo = $quote->workOrder;
                    $serv = $quote->service;
                    $prop = $serv?->property ?? $wo?->property ?? null;
                    if (!$prop && $quote->property_id) {
                        $prop = \App\Models\Property::find($quote->property_id);
                    }

                    $client = $prop?->client ?? $serv?->property?->client ?? $wo?->property?->client ?? null;
                    if (!$client && $prop && $prop->client_id) {
                        $client = \App\Models\Client::find($prop->client_id);
                    }
                    if (!$client && $user && $user->role_id === 3) {
                        $client = \App\Models\Client::where('user_id', $user->id)->orWhere('email', $user->email)->first();
                    }

                    $tecnicoModel = $quote->service?->technician ?? $quote->workOrder?->tecnico ?? ($quote->service?->technicians?->first() ?? $quote->workOrder?->technicians?->first() ?? null);

                    // Evidencias del reporte
                    $evidencias = [];
                    if ($wo?->evidence_path) $evidencias[] = str_starts_with($wo->evidence_path, 'http') ? $wo->evidence_path : asset('storage/' . ltrim($wo->evidence_path, '/'));
                    if ($wo?->evidence_path_2) $evidencias[] = str_starts_with($wo->evidence_path_2, 'http') ? $wo->evidence_path_2 : asset('storage/' . ltrim($wo->evidence_path_2, '/'));
                    if ($serv?->evidence_path) $evidencias[] = str_starts_with($serv->evidence_path, 'http') ? $serv->evidence_path : asset('storage/' . ltrim($serv->evidence_path, '/'));
                    if ($quote->evidence_photo_path) $evidencias[] = str_starts_with($quote->evidence_photo_path, 'http') ? $quote->evidence_photo_path : asset('storage/' . ltrim($quote->evidence_photo_path, '/'));

                    // Limpiar descripción de etiquetas de lote si existen
                    $descLimpia = $wo?->description ?? $serv?->description ?? $quote->observations ?? '';
                    if (is_string($descLimpia)) {
                        $descLimpia = preg_replace('/\[LOTE-[A-Z0-9]+\]\s*(\(\d+\/\d+\))?\s*/i', '', $descLimpia);
                        if (str_contains($descLimpia, '[EQUIPO AFECTADO]:')) {
                            $partes = explode('[EQUIPO AFECTADO]:', $descLimpia);
                            $descLimpia = trim($partes[0]);
                        }
                    }

                    // Problemas relacionados si es lote
                    $problemasLote = [];
                    if ($quote->is_unified_batch || !empty($quote->related_service_ids)) {
                        $relatedIds = is_array($quote->related_service_ids) ? $quote->related_service_ids : (is_string($quote->related_service_ids) ? json_decode($quote->related_service_ids, true) : []);
                        if (!empty($relatedIds)) {
                            $relatedWos = \App\Models\WorkOrder::whereIn('id', $relatedIds)->get();
                            foreach ($relatedWos as $rwo) {
                                $rEvidencias = [];
                                if ($rwo->evidence_path) $rEvidencias[] = str_starts_with($rwo->evidence_path, 'http') ? $rwo->evidence_path : asset('storage/' . ltrim($rwo->evidence_path, '/'));
                                if ($rwo->evidence_path_2) $rEvidencias[] = str_starts_with($rwo->evidence_path_2, 'http') ? $rwo->evidence_path_2 : asset('storage/' . ltrim($rwo->evidence_path_2, '/'));
                                $problemasLote[] = [
                                    'id' => $rwo->id,
                                    'tipo' => $rwo->type,
                                    'zona' => $rwo->zone,
                                    'equipo' => $rwo->equipment ?: 'General',
                                    'descripcion' => $rwo->description,
                                    'evidencias' => $rEvidencias
                                ];
                            }
                        }
                    }

                    $fotoFachada = $prop?->facade_photo_path ?? null;
                    if ($fotoFachada && !str_starts_with($fotoFachada, 'http')) {
                        $fotoFachada = asset('storage/' . ltrim($fotoFachada, '/'));
                    }

                    $scheduledFormatted = $wo?->scheduled_at ? $wo->scheduled_at->format('d/m/Y') : ($serv?->scheduled_start ? date('d/m/Y', strtotime($serv->scheduled_start)) : ($quote->created_at ? $quote->created_at->format('d/m/Y') : 'Pendiente'));

                    $clienteNombre = $client?->name ?? ($user && $user->role_id === 3 ? trim($user->first_name . ' ' . $user->last_name) : 'Sin Cliente');

                    return [
                        'id' => $quote->id,
                        'property_id' => $quote->property_id ?? $prop?->id ?? null,
                        'service_id' => $quote->service_id,
                        'work_order_id' => $quote->work_order_id,
                        'folio' => (function () use ($quote) {
                            $baseId = $quote->parent_id ?? $quote->id;
                            $suffix = '';
                            if ($quote->parent_id) {
                                $childrenCount = \App\Models\Quote::where('parent_id', $quote->parent_id)
                                    ->where('id', '<=', $quote->id)
                                    ->count();
                                $suffix = '-' . chr(64 + $childrenCount); // A, B, C...
                            }
                            return 'COT-' . str_pad($baseId, 3, '0', STR_PAD_LEFT) . $suffix;
                        })(),
                        'cliente' => $clienteNombre,
                        'cliente_nombre' => $clienteNombre,
                        'cliente_id' => $client->id ?? null,
                        'cliente_user_id' => $client->user_id ?? null,
                        'cliente_telefono' => $client?->phone ?? ($user && $user->role_id === 3 ? ($user->phone_number ?? '') : ''),
                        'cliente_email' => $client?->email ?? ($user && $user->role_id === 3 ? $user->email : ''),
                        'cliente_tipo_propiedad' => strtoupper($prop?->type ?? 'CASA'),
                        'tecnico' => $tecnicoModel ? ($tecnicoModel->first_name . ' ' . $tecnicoModel->last_name) : 'Sin Técnico',
                        'tecnico_id' => $tecnicoModel->id ?? null,
                        'tecnico_user_id' => $tecnicoModel->id ?? null,
                        'tecnico_telefono' => $tecnicoModel?->phone_number ?? $tecnicoModel?->phone ?? null,
                        'propiedad_nombre' => $prop?->property_name ?? 'Propiedad de Cliente',
                        'propiedad_direccion' => $prop?->address ?? 'Dirección no especificada',
                        'propiedad_curp' => $prop?->custom_curp ?? ($prop ? "PROP-{$prop->id}" : "COT-{$quote->id}"),
                        'propiedad_coordenadas' => $prop?->coordinates ?? null,
                        'propiedad_foto' => $fotoFachada,
                        'foto_fachada' => $fotoFachada,
                        'tipo_falla' => $wo?->type ?? $serv?->service_type ?? $serv?->title ?? 'Mantenimiento General',
                        'zona' => $wo?->zone ?? $serv?->zone ?? 'Área de la propiedad',
                        'equipo' => $wo?->equipment ?? $serv?->equipment ?? 'General',
                        'descripcion_problema' => $descLimpia,
                        'evidencias' => $evidencias,
                        'problemas_lote' => $problemasLote,
                        'prioridad' => $wo?->priority ?? 'Normal',
                        'batch_id' => $wo?->batch_id ?? null,
                        'scheduled_at' => $scheduledFormatted,
                        'fecha' => $quote->created_at ? $quote->created_at->format('Y-m-d') : '---',
                        'created_at' => $quote->created_at,
                        'total' => $quote->estimated_amount ?? 0,
                        'estimated_amount' => $quote->estimated_amount ?? 0,
                        'status' => $quote->status,
                        'type' => $quote->type,
                        'concept' => $quote->concept,
                        'observations' => $quote->observations,
                        'internal_observations' => ($user && $user->role_id !== 3) ? ($quote->internal_observations ?? null) : null,
                        'created_by_role' => $quote->created_by_role ?? 'Admin',
                        'parent_id' => $quote->parent_id ?? null,
                        'archivo_url' => $quote->file_path ? (str_starts_with($quote->file_path, 'http') ? $quote->file_path : asset('storage/' . ltrim($quote->file_path, '/'))) : null,
                        'evidence_photo_path' => $quote->evidence_photo_path,
                        'payment_receipt_path' => $quote->payment_receipt_path,
                        'payment_status' => $quote->payment_status,
                        'mp_payment_data' => $quote->mp_payment_data,
                        'advance_paid' => $quote->advance_paid,
                        'remaining_paid' => $quote->remaining_paid,
                        'advance_amount' => $quote->advance_amount,
                        'remaining_amount' => $quote->remaining_amount,
                        'advance_paid_at' => $quote->advance_paid_at,
                        'remaining_paid_at' => $quote->remaining_paid_at,
                        'cash_requested' => $quote->cash_requested,
                        'cash_confirmed' => $quote->cash_confirmed,
                        'cash_confirmed_at' => $quote->cash_confirmed_at,
                        'cash_confirmed_by' => $quote->cash_confirmed_by,
                        'cash_confirmed_by_name' => $quote->cash_confirmed_by_name,
                        'cash_amount_type' => $quote->cash_amount_type,
                        'cash_timing' => $quote->cash_timing,
                        'chat_history' => $quote->chat_history,
                    ];
                });

            // Cargar Cotizaciones de la Red (NetworkQuotes)
            try {
                $networkQuotesQuery = \App\Models\NetworkQuote::withoutGlobalScopes()
                    ->with([
                        'workOrder' => function ($q) {
                            $q->withoutGlobalScopes()->with([
                                'property' => function ($qp) {
                                    $qp->withoutGlobalScopes()->with('client'); }
                            ]);
                        },
                        'technician' => function ($q) {
                            $q->withoutGlobalScopes(); }
                    ]);

                if ($user) {
                    if ($user->role_id === 8 || $user->role_id === 2) {
                        $networkQuotesQuery->where('technician_id', $user->id);
                    } elseif ($user->role_id === 4) {
                        $networkQuotesQuery->whereHas('workOrder', function ($q) use ($user) {
                            $q->withoutGlobalScopes()->where('tenant_id', $user->tenant_id);
                        });
                    } elseif ($user->role_id === 3) {
                        $networkQuotesQuery->whereHas('workOrder.property.client', function ($q) use ($user) {
                            $q->where('user_id', $user->id)->orWhere('email', $user->email);
                        });
                    }
                }

                $networkQuotes = $networkQuotesQuery->orderBy('created_at', 'desc')->get()->map(function ($nq) use ($user) {
                    $wo = $nq->workOrder;
                    $prop = $wo?->property;
                    $client = $prop?->client;
                    $clientName = $client ? (trim($client->first_name . ' ' . $client->last_name) ?: $client->name) : ($wo?->owner_name ?? ($user && $user->role_id === 3 ? trim($user->first_name . ' ' . $user->last_name) : 'Cliente de la Red'));
                    $propName = $prop?->property_name ?: 'Propiedad en Red';
                    $propAddress = $prop?->address ?: 'Dirección no especificada';
                    $techName = $nq->technician ? trim($nq->technician->first_name . ' ' . $nq->technician->last_name) : 'Técnico de la Red';

                    $statusMapped = match ($nq->status) {
                        'accepted' => 'Aprobado',
                        'rejected' => 'Rechazado',
                        default => 'Por Pagar'
                    };

                    $tipoFalla = $wo?->type ?: 'Mantenimiento en Red';
                    $equipo = $wo?->equipment ?: 'otro';
                    $zona = $wo?->zone ?: 'General';
                    $descLimpia = $wo?->description ?: ($nq->message ?: 'Trabajo publicado en la Red');
                    
                    $rEvidencias = [];
                    if ($wo?->evidence_path) $rEvidencias[] = str_starts_with($wo->evidence_path, 'http') ? $wo->evidence_path : asset('storage/' . ltrim($wo->evidence_path, '/'));
                    if ($wo?->evidence_path_2) $rEvidencias[] = str_starts_with($wo->evidence_path_2, 'http') ? $wo->evidence_path_2 : asset('storage/' . ltrim($wo->evidence_path_2, '/'));

                    $fotoFachada = $prop?->facade_photo_path ?: ($wo?->evidence_path ?: $wo?->evidence_path_2);
                    if ($fotoFachada && !str_starts_with($fotoFachada, 'http')) {
                        $fotoFachada = asset('storage/' . ltrim($fotoFachada, '/'));
                    }

                    $conceptTitle = $wo ? ($wo->type . ($wo->equipment ? ' - ' . $wo->equipment : '')) : 'Trabajo de la Red';

                    return [
                        'id' => 'net_' . $nq->id,
                        'network_quote_id' => $nq->id,
                        'is_network_quote' => true,
                        'property_id' => $wo?->property_id,
                        'service_id' => null,
                        'work_order_id' => $nq->work_order_id,
                        'folio' => 'RED-' . str_pad($nq->id, 3, '0', STR_PAD_LEFT),
                        'cliente' => $clientName ?: 'Cliente de la Red',
                        'cliente_nombre' => $clientName ?: 'Cliente de la Red',
                        'cliente_id' => $client?->id,
                        'cliente_user_id' => $client?->user_id,
                        'cliente_telefono' => $client?->phone ?? ($user && $user->role_id === 3 ? ($user->phone_number ?? '') : ''),
                        'cliente_email' => $client?->email ?? ($user && $user->role_id === 3 ? $user->email : ''),
                        'cliente_tipo_propiedad' => strtoupper($prop?->type ?? 'CASA'),
                        'tecnico' => $techName,
                        'tecnico_id' => $nq->technician_id,
                        'tecnico_user_id' => $nq->technician_id,
                        'propiedad_nombre' => $propName,
                        'propiedad_direccion' => $propAddress,
                        'propiedad_curp' => $prop?->custom_curp ?: "RED-{$nq->id}",
                        'propiedad_coordenadas' => $prop?->coordinates ?? null,
                        'propiedad_foto' => $fotoFachada,
                        'foto_fachada' => $fotoFachada,
                        'tipo_falla' => $tipoFalla,
                        'zona' => $zona,
                        'equipo' => $equipo,
                        'descripcion_problema' => $descLimpia,
                        'evidencias' => $rEvidencias,
                        'problemas_lote' => [],
                        'prioridad' => $wo?->priority ?? 'Normal',
                        'scheduled_at' => $wo?->scheduled_at ? $wo->scheduled_at->format('d/m/Y') : ($nq->created_at ? $nq->created_at->format('d/m/Y') : 'Pendiente'),
                        'fecha' => $nq->created_at ? $nq->created_at->format('Y-m-d') : date('Y-m-d'),
                        'created_at' => $nq->created_at,
                        'total' => (float) $nq->price,
                        'estimated_amount' => (float) $nq->price,
                        'status' => $statusMapped,
                        'network_status' => $nq->status,
                        'type' => 'manual',
                        'concept' => json_encode([
                            'conceptos' => [
                                [
                                    'descripcion' => $conceptTitle,
                                    'cantidad' => 1,
                                    'precio_u' => (float) $nq->price,
                                    'precio' => (float) $nq->price
                                ]
                            ],
                            'materiales' => []
                        ]),
                        'observations' => $nq->message ?: ($wo?->description ?? 'Cotización enviada en la Red de Trabajos'),
                        'created_by_role' => 'Técnico de la Red',
                        'payment_status' => $nq->status === 'accepted' ? 'Pagado' : 'Pendiente',
                        'chat_history' => $nq->chat_history ?? [],
                    ];
                });

                $quotes = $quotes->concat($networkQuotes);
            } catch (\Throwable $netErr) {
                \Log::warning("Error cargando network quotes en QuoteController: " . $netErr->getMessage());
            }

            return response()->json($quotes, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al cargar cotizaciones: ' . $e->getMessage()], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|in:Aprobado,Rechazado',
                'rejection_reason' => 'nullable|string'
            ]);

            $quote = Quote::findOrFail($id);
            $quote->status = $request->status;

            if ($request->status === 'Rechazado' && $request->filled('rejection_reason')) {
                $quote->observations = $quote->observations . "\n\n[MOTIVO RECHAZO]: " . $request->rejection_reason;
            }

            // --- LÓGICA DE FLUJO: SI SE APRUEBA, ACTIVAMOS LOS SERVICIOS ---
            if ($request->status === 'Aprobado') {
                $serviceIds = [];
                if ($quote->service_id)
                    $serviceIds[] = $quote->service_id;
                if (is_array($quote->related_service_ids))
                    $serviceIds = array_unique(array_merge($serviceIds, $quote->related_service_ids));
                foreach ($serviceIds as $sId) {
                    $s = Service::find($sId);
                    if ($s) {
                        $s->update([
                            'status' => 'Pendiente de Pago',
                            'quote_approved' => true
                        ]);
                    }
                }
            }

            $quote->save();

            // Notificar admins
            $clientName = $quote->service->property->client->name ?? 'Cliente desconocido';
            $admins = User::where('role_id', 0)->get();
            foreach ($admins as $admin) {
                $admin->notify(new QuoteStatusUpdated($quote, $clientName));
            }

            return response()->json(['message' => 'Estado actualizado y servicio vinculado', 'quote' => $quote], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al actualizar: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Nuevo método para que el técnico confirme el check-list
     */
    public function confirmMaterials($serviceId)
    {
        try {
            $service = Service::findOrFail($serviceId);

            $service->update([
                'materials_checked' => true
            ]);

            return response()->json([
                'message' => 'Check-list de materiales confirmado',
                'materials_checked' => true
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al confirmar materiales: ' . $e->getMessage()], 500);
        }
    }

    public function updateObservations(Request $request, $id)
    {
        $quote = Quote::findOrFail($id);
        // Tu tabla usa 'observations'
        $quote->observations = $request->input('observaciones');
        $quote->save();

        return response()->json(['message' => 'Observaciones guardadas']);
    }

    public function finalizarCotizacion(Request $request, $id)
    {
        $quote = \App\Models\Quote::findOrFail($id);

        try {
            // Si viene un archivo PDF, usamos la "Opción Nuclear"
            if ($request->hasFile('pdf')) {
                // Instanciamos Cloudinary directamente con tu clave (igual que en ImageController)
                $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));

                // Subimos el archivo a la carpeta 'cotizaciones_pdf'
                // NOTA CRÍTICA: Se usa resource_type => 'raw' para PDFs para evitar el error 401 de Cloudinary
                $respuestaNube = $cloudinary->uploadApi()->upload($request->file('pdf')->getRealPath(), [
                    'folder' => 'cotizaciones_pdf',
                    'resource_type' => 'raw'
                ]);

                // Guardamos la URL segura
                $quote->file_path = $respuestaNube['secure_url'];
            }

            // Guardamos las observaciones
            $quote->observations = $request->input('observaciones');

            // Opcional: Cambiar estado (ej. de 'Pendiente' a 'Aprobado' o 'En Proceso')
            // $quote->status = 'En Proceso';

            $quote->save();

            return response()->json([
                'message' => 'Cotización generada y guardada correctamente',
                'url' => $quote->file_path
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error interno al subir PDF: ' . $e->getMessage(),
                'archivo' => $e->getFile(),
                'linea' => $e->getLine()
            ], 500);
        }
    }
    public function addMessage(Request $request, $id)
    {
        try {
            $request->validate([
                'message' => 'required|string',
            ]);

            if (str_starts_with((string) $id, 'net_')) {
                $netId = (int) str_replace('net_', '', (string) $id);
                $netQuote = \App\Models\NetworkQuote::withoutGlobalScopes()->with(['workOrder.property.client', 'technician'])->findOrFail($netId);
                $user = auth('sanctum')->user() ?: auth()->user();

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

                $history = $netQuote->chat_history ?? [];
                $history[] = $newMessage;
                $netQuote->chat_history = $history;
                $netQuote->save();

                $senderNameStr = $user->name ?: ($user->first_name . ' ' . $user->last_name);

                if (in_array($user->role_id, [2, 8])) {
                    $clientUserId = $netQuote->workOrder?->tenant_id ?: ($netQuote->workOrder?->property?->client?->user_id ?? null);
                    if ($clientUserId) {
                        $clientUser = User::find($clientUserId);
                        if ($clientUser) {
                            \Illuminate\Support\Facades\Notification::send($clientUser, new \App\Notifications\NewNetworkQuoteChatMessageNotification($netQuote, $senderNameStr, 'Técnico'));
                        }
                    }
                } else {
                    if ($netQuote->technician) {
                        \Illuminate\Support\Facades\Notification::send($netQuote->technician, new \App\Notifications\NewNetworkQuoteChatMessageNotification($netQuote, $senderNameStr, $senderRole));
                    }
                }

                return response()->json(['message' => 'Mensaje enviado', 'chat_history' => $history], 200);
            }

            $quote = Quote::findOrFail($id);
            $user = auth('sanctum')->user() ?: auth()->user();

            $newMessage = [
                'sender_id' => $user->id,
                'sender_name' => $user->name ?? $user->first_name . ' ' . $user->last_name,
                'sender_role' => $user->role_id == 3 ? 'Cliente' : ($user->role_id == 2 ? 'Técnico' : 'Admin'),
                'message' => $request->message,
                'created_at' => now()->toIso8601String(),
            ];

            $history = $quote->chat_history ?? [];
            $history[] = $newMessage;

            $quote->chat_history = $history;
            $quote->save();

            // Lógica para enviar notificación
            $quote->load(['service.property.client', 'workOrder.property.client']);
            $senderNameStr = $user->name ?? $user->first_name . ' ' . $user->last_name;

            if ($user->role_id == 3) {
                // Si lo mandó el Cliente, notificamos a los Admins
                $admins = User::whereIn('role_id', [0, 1])->get();
                \Illuminate\Support\Facades\Notification::send($admins, new \App\Notifications\NewQuoteMessageNotification($quote, $senderNameStr, 'Cliente'));
            } else {
                // Si lo mandó Admin o Técnico, notificamos al Cliente
                $roleStr = $user->role_id == 2 ? 'Técnico' : 'Admin';
                $cliente = $quote->service->property->client ?? $quote->workOrder->property->client ?? null;
                if ($cliente && $cliente->user_id) {
                    $clienteUser = User::find($cliente->user_id);
                    if ($clienteUser) {
                        \Illuminate\Support\Facades\Notification::send($clienteUser, new \App\Notifications\NewQuoteMessageNotification($quote, $senderNameStr, $roleStr));
                    }
                }
            }

            return response()->json(['message' => 'Mensaje enviado', 'chat_history' => $history], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al enviar mensaje: ' . $e->getMessage()], 500);
        }
    }

    public function uploadPaymentReceipt(Request $request, $id)
    {
        try {
            $request->validate([
                'receipt_file' => 'required|file|mimes:jpeg,png,jpg,pdf|max:10240'
            ]);

            $quote = Quote::findOrFail($id);

            // Subir a Cloudinary desde el backend usando la "Opción Nuclear"
            $cloudinary = new \Cloudinary\Cloudinary('cloudinary://942191234587844:VmNYB6w4vj3DdLqI9SZSKVofOi0@dcj5rcpi8');
            $respuestaNube = $cloudinary->uploadApi()->upload($request->file('receipt_file')->getRealPath(), [
                'folder' => 'comprobantes_pago'
            ]);
            $fileUrl = $respuestaNube['secure_url'];

            $quote->payment_receipt_path = $fileUrl;
            $quote->payment_status = 'Pago en Revisión';
            $quote->status = 'Pago en Revisión';
            $quote->save();

            // Notificar a los administradores
            $admins = \App\Models\User::whereIn('role_id', [0, 1])->get();
            \Illuminate\Support\Facades\Notification::send($admins, new QuotePaymentReceived($quote));

            return response()->json(['message' => 'Comprobante recibido exitosamente.', 'quote' => $quote]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al guardar el comprobante: ' . $e->getMessage()], 500);
        }
    }

    public function validatePayment(Request $request, $id)
    {
        try {
            $quote = Quote::findOrFail($id);
            $quote->payment_status = 'Validado';
            $quote->status = 'Pagado';
            $quote->save();

            // Activar los servicios ligados a Programado
            $serviceIds = [];
            if ($quote->service_id)
                $serviceIds[] = $quote->service_id;
            if (is_array($quote->related_service_ids))
                $serviceIds = array_unique(array_merge($serviceIds, $quote->related_service_ids));
            foreach ($serviceIds as $sId) {
                $service = Service::find($sId);
                if ($service) {
                    $service->update([
                        'status' => 'Programado',
                        'scheduled_at' => now(),
                    ]);

                    $workOrder = WorkOrder::where('service_id', $service->id)->first();
                    if ($workOrder && $workOrder->status === 'Pendiente') {
                        $workOrder->status = 'Asignado';
                        $workOrder->save();
                    }
                }
            }

            // Notificar al cliente
            $quote->load(['service.property.client', 'workOrder.property.client']);
            $cliente = $quote->service->property->client ?? $quote->workOrder->property->client ?? null;
            if ($cliente && $cliente->user_id) {
                $clienteUser = User::find($cliente->user_id);
                if ($clienteUser) {
                    $clienteUser->notify(new QuotePaymentValidated($quote));
                }
            }

            return response()->json(['message' => 'Pago validado y servicio activado.', 'quote' => $quote]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al validar el pago: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cliente solicita pago en efectivo (anticipo o total, ahora o al finalizar).
     */
    public function solicitarEfectivo(Request $request, $id)
    {
        try {
            $request->validate([
                'cash_amount_type' => 'required|in:advance,remaining,full',
                'cash_timing' => 'required|in:immediate,on_completion',
            ]);

            $quote = Quote::findOrFail($id);
            $quote->cash_requested = true;
            $quote->cash_amount_type = $request->cash_amount_type;
            $quote->cash_timing = $request->cash_timing;
            $quote->payment_scheme = 'cash';
            if ($request->cash_amount_type === 'remaining') {
                $quote->status = 'Liquidación en Efectivo Solicitada (40%)';
            } else {
                $quote->status = 'Pago en Efectivo Solicitado';
            }
            $quote->save();

            // Calcular monto final
            $subtotalBase = 0;
            try {
                if ($quote->concept) {
                    $detalle = is_string($quote->concept) ? json_decode($quote->concept, true) : $quote->concept;
                    if (is_array($detalle)) {
                        $suma = 0;
                        foreach (($detalle['conceptos'] ?? $detalle['servicios'] ?? []) as $c) {
                            $suma += ((float) ($c['precio_u'] ?? $c['precio'] ?? 0)) * ((float) ($c['cantidad'] ?? 1));
                        }
                        foreach (($detalle['materiales'] ?? []) as $m) {
                            $suma += ((float) ($m['costo_u'] ?? $m['precio'] ?? 0)) * ((float) ($m['cantidad'] ?? 1));
                        }
                        if ($suma > 0)
                            $subtotalBase = $suma;
                    }
                }
            } catch (\Exception $e) {
            }

            if ($subtotalBase > 0) {
                $subConIva = $subtotalBase * 1.16;
                $comisionMP = ($subConIva * 0.0349 + 4) * 1.16;
                $totalFinal = round($subConIva + $comisionMP, 2);
            } else {
                $totalFinal = (float) $quote->estimated_amount;
            }

            if ($request->cash_amount_type === 'remaining') {
                $quote->remaining_amount = round($totalFinal * 0.40, 2);
            } elseif (!$quote->advance_amount || $request->cash_amount_type === 'advance') {
                $quote->advance_amount = round($totalFinal * 0.60, 2);
                $quote->remaining_amount = round($totalFinal * 0.40, 2);
            }
            $quote->save();

            // Notificar a los Administradores y Root (rol 0 o 1)
            $clientName = $request->user()?->first_name . ' ' . $request->user()?->last_name ?? 'Cliente';
            $admins = User::whereIn('role_id', [0, 1])->get();
            foreach ($admins as $admin) {
                $admin->notify(new \App\Notifications\CashPaymentRequested(
                    $quote,
                    trim($clientName),
                    $request->cash_amount_type,
                    $request->cash_timing
                ));
            }

            return response()->json(['message' => 'Solicitud de pago en efectivo enviada.', 'quote' => $quote]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al solicitar pago en efectivo: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Solicitud de pago en efectivo para múltiples cotizaciones seleccionadas en el Carrito.
     */
    public function solicitarEfectivoBatch(Request $request)
    {
        try {
            $request->validate([
                'quote_ids' => 'required|array|min:1',
                'quote_ids.*' => 'integer|exists:quotes,id',
                'cash_amount_type' => 'required|in:advance,full,remaining',
                'cash_timing' => 'required|in:immediate,on_completion',
            ]);

            $quoteIds = $request->input('quote_ids', []);
            $quotes = Quote::whereIn('id', $quoteIds)->get();
            $clientName = trim(($request->user()?->first_name ?? '') . ' ' . ($request->user()?->last_name ?? 'Cliente'));
            $admins = User::whereIn('role_id', [0, 1])->get();
            $folios = [];

            foreach ($quotes as $quote) {
                $quote->cash_requested = true;
                $quote->cash_amount_type = $request->cash_amount_type;
                $quote->cash_timing = $request->cash_timing;
                $quote->payment_scheme = 'cash';
                $quote->status = ($request->cash_amount_type === 'remaining')
                    ? 'Liquidación en Efectivo Solicitada (40%)'
                    : 'Pago en Efectivo Solicitado';

                $subtotalBase = 0;
                try {
                    if ($quote->concept) {
                        $detalle = is_string($quote->concept) ? json_decode($quote->concept, true) : $quote->concept;
                        if (is_array($detalle)) {
                            $suma = 0;
                            foreach (($detalle['conceptos'] ?? $detalle['servicios'] ?? []) as $c) {
                                $suma += ((float) ($c['precio_u'] ?? $c['precio'] ?? 0)) * ((float) ($c['cantidad'] ?? 1));
                            }
                            foreach (($detalle['materiales'] ?? []) as $m) {
                                $suma += ((float) ($m['costo_u'] ?? $m['precio'] ?? 0)) * ((float) ($m['cantidad'] ?? 1));
                            }
                            if ($suma > 0) $subtotalBase = $suma;
                        }
                    }
                } catch (\Exception $e) {}

                $totalFinal = ($subtotalBase > 0) ? round(($subtotalBase * 1.16 * 1.0349 + 4 * 1.16), 2) : (float) ($quote->estimated_amount ?? 0);
                if ($request->cash_amount_type === 'remaining') {
                    $quote->remaining_amount = round($totalFinal * 0.40, 2);
                } else {
                    $quote->advance_amount = round($totalFinal * 0.60, 2);
                    $quote->remaining_amount = round($totalFinal * 0.40, 2);
                }
                $quote->save();

                $folios[] = 'COT-' . $quote->id;

                foreach ($admins as $admin) {
                    $admin->notify(new \App\Notifications\CashPaymentRequested(
                        $quote,
                        $clientName,
                        $request->cash_amount_type,
                        $request->cash_timing
                    ));
                }
            }

            return response()->json([
                'message' => 'Solicitud de pago en efectivo registrada para ' . count($quotes) . ' cotizaciones.',
                'processed_ids' => $quoteIds,
                'folios' => $folios
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al solicitar pago en efectivo para el lote: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Admin confirma recepción del pago en efectivo.
     */
    public function confirmarEfectivo(Request $request, $id)
    {
        try {
            $user = auth('sanctum')->user() ?: auth()->user();
            if (!$user || !in_array($user->role_id, [0, 1])) {
                return response()->json(['error' => 'No autorizado'], 403);
            }

            $quote = Quote::findOrFail($id);
            $quote->cash_confirmed = true;
            $quote->cash_confirmed_at = now();
            $quote->cash_confirmed_by = $user->id;

            // Si el tipo de efectivo es anticipo, dejamos pendiente el restante
            if ($quote->cash_amount_type === 'advance') {
                $quote->advance_paid = true;
                $quote->advance_paid_at = now();
                $quote->status = 'Anticipo Pagado (60%)';
            } else {
                // Pago total en efectivo
                $quote->advance_paid = true;
                $quote->advance_paid_at = now();
                $quote->remaining_paid = true;
                $quote->remaining_paid_at = now();
                $quote->status = 'Pagado (Efectivo)';
            }

            $quote->save();

            // Activar los servicios vinculados a Programado
            $serviceIds = [];
            if ($quote->service_id)
                $serviceIds[] = $quote->service_id;
            if (is_array($quote->related_service_ids))
                $serviceIds = array_unique(array_merge($serviceIds, $quote->related_service_ids));
            foreach ($serviceIds as $sId) {
                $service = Service::find($sId);
                if ($service) {
                    $service->update(['status' => 'Programado', 'scheduled_at' => now()]);
                    $workOrder = WorkOrder::where('service_id', $service->id)->first();
                    if ($workOrder && $workOrder->status === 'Pendiente') {
                        $workOrder->status = 'Asignado';
                        $workOrder->save();
                    }
                }
            }

            // Notificar al Cliente
            if ($quote->cliente_user_id) {
                $clienteUser = User::find($quote->cliente_user_id);
                if ($clienteUser) {
                    $clienteUser->notify(new \App\Notifications\QuotePaymentValidated($quote));
                }
            }

            return response()->json(['message' => 'Pago en efectivo confirmado.', 'quote' => $quote]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al confirmar pago en efectivo: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Admin confirma recepción del 40% restante en efectivo.
     */
    public function confirmarEfectivoRestante(Request $request, $id)
    {
        try {
            $user = auth('sanctum')->user() ?: auth()->user();
            if (!$user || !in_array($user->role_id, [0, 1])) {
                return response()->json(['error' => 'No autorizado'], 403);
            }

            $quote = Quote::findOrFail($id);
            $quote->remaining_paid = true;
            $quote->remaining_paid_at = now();
            $quote->cash_confirmed_by = $user->id; // Actualiza autorizador
            $quote->status = 'Pagado (Efectivo)';
            $quote->save();

            if ($quote->cliente_user_id) {
                $clienteUser = User::find($quote->cliente_user_id);
                if ($clienteUser) {
                    $clienteUser->notify(new \App\Notifications\QuotePaymentValidated($quote));
                }
            }

            return response()->json(['message' => 'Liquidación de 40% en efectivo confirmada.', 'quote' => $quote]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al confirmar 40% restante en efectivo: ' . $e->getMessage()], 500);
        }
    }
}
