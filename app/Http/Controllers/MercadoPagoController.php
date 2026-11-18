<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\MercadoPagoPaymentReceived;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoController extends Controller
{
    public function __construct()
    {
        // Inicializamos MercadoPago con el token de acceso
        MercadoPagoConfig::setAccessToken(env('MERCADOPAGO_ACCESS_TOKEN'));
    }

    /**
     * Crear preferencia de pago para suscripción de Autónomo.
     * El frontend llama a esto justo después de registrarse o al renovar.
     */
    public function createSubscriptionPreference(Request $request, $tenantId)
    {
        $frontendUrl = rtrim($request->header('origin') ?? env('FRONTEND_URL', 'https://agentesolutions-production.up.railway.app'), '/');

        // 1. Si es compra de Propiedad Extra ($79.99 por cada espacio)
        if ($request->type === 'extra_property' || $request->has('extra_property')) {
            $tenant = \App\Models\Tenant::findOrFail($tenantId);
            $quantity = (int) ($request->quantity ?? 1);
            if ($quantity < 1) $quantity = 1;
            $amount = round(79.99 * $quantity, 2);
            $title  = "Propiedad(es) Extra (+{$quantity}) - Plan Personal";
            $desc   = "Cupo adicional de {$quantity} propiedad(es) para {$tenant->name}";
            $extRef = "EXT_PROP|" . $tenant->id . "|" . $quantity;

            try {
                $client = new PreferenceClient();
                $preference = $client->create([
                    "items" => [[
                        "id" => 'EXT_PROP_' . $tenant->id . '_' . $quantity, "title" => $title, "description" => $desc,
                        "quantity" => $quantity, "unit_price" => 79.99, "currency_id" => "MXN"
                    ]],
                    "back_urls" => [
                        "success" => $frontendUrl . "/activacion-cuenta?status=success&type=extra_property",
                        "failure" => $frontendUrl . "/activacion-cuenta?status=failure&type=extra_property",
                        "pending" => $frontendUrl . "/activacion-cuenta?status=pending&type=extra_property"
                    ],
                    "auto_return" => "approved", "external_reference" => $extRef,
                    "notification_url" => env('APP_URL', 'https://agentesolutionsback-production.up.railway.app') . "/api/mercadopago/webhook"
                ]);
                return response()->json(['id' => $preference->id, 'init_point' => $preference->init_point, 'sandbox_init_point' => $preference->sandbox_init_point, 'amount' => $amount, 'quantity' => $quantity, 'type' => 'Propiedad Extra']);
            } catch (\Exception $e) {
                return response()->json(['error' => 'Error MP', 'details' => $e->getMessage()], 500);
            }
        }

        // 2. Si es pago para Técnico Externo ($99/mes)
        if ($request->type === 'technician' || (!empty($request->user_id) && $request->type !== 'extra_property' && !isset($request->extra_property) && $request->type !== 'autonomo')) {
            $userId   = $request->user_id ?? auth('sanctum')->id();
            $techUser = \App\Models\User::findOrFail($userId);
            $amount   = 99.00;
            $title    = "Suscripción Mensual Técnico - Agente Solutions";
            $desc     = "Acceso mensual para técnico externo ($99.00 MXN)";
            $extRef   = "TECH|" . $techUser->id;

            try {
                $client = new PreferenceClient();
                $preference = $client->create([
                    "items" => [[
                        "id" => 'TECH_' . $techUser->id, "title" => $title, "description" => $desc,
                        "quantity" => 1, "unit_price" => $amount, "currency_id" => "MXN"
                    ]],
                    "back_urls" => [
                        "success" => $frontendUrl . "/activacion-cuenta?status=success&type=technician",
                        "failure" => $frontendUrl . "/activacion-cuenta?status=failure&type=technician",
                        "pending" => $frontendUrl . "/activacion-cuenta?status=pending&type=technician"
                    ],
                    "auto_return" => "approved", "external_reference" => $extRef,
                    "notification_url" => env('APP_URL', 'https://agentesolutionsback-production.up.railway.app') . "/api/mercadopago/webhook",
                    "payer" => ["email" => $techUser->email, "name" => trim($techUser->first_name . ' ' . $techUser->last_name)]
                ]);
                return response()->json(['id' => $preference->id, 'init_point' => $preference->init_point, 'sandbox_init_point' => $preference->sandbox_init_point, 'amount' => $amount, 'type' => 'Técnico Externo']);
            } catch (\Exception $e) {
                return response()->json(['error' => 'Error MP', 'details' => $e->getMessage()], 500);
            }
        }

        $tenant = \App\Models\Tenant::findOrFail($tenantId);
        $owner  = \App\Models\User::find($tenant->owner_user_id);

        // 3. Renovación de Plan Autónomo (Personal, Empresarial, Fundador)
        $option = $request->plan_option ?? 'monthly'; // 'monthly', 'annual', 'completion'
        $durationMonths = 1;
        $amount = 299.00;
        $typeLabel = 'Autónomo Personal';

        if ($tenant->membership_type === 'autonomo_fundador') {
            $typeLabel = 'Autónomo Plan Fundador';
            if ($option === 'annual' || $option === 'completion') {
                $amount = 3600.00; $durationMonths = 6; $title = "Plan Fundador - Completar Año (6 meses)";
            } else {
                $amount = 659.00; $durationMonths = 1; $title = "Plan Fundador - Mensual";
            }
        } elseif ($tenant->membership_type === 'autonomo_personal') {
            $typeLabel = 'Autónomo Personal';
            if ($option === 'annual') {
                $amount = 3229.20; $durationMonths = 12; $title = "Plan Personal - Anual (10% descuento)";
            } else {
                $amount = 299.00; $durationMonths = 1; $title = "Plan Personal - Mensual";
            }
        } else {
            $typeLabel = 'Autónomo Empresarial';
            if ($option === 'annual') {
                $amount = 10200.00; $durationMonths = 12; $title = "Plan Empresarial - Anual";
            } else {
                $amount = 935.00; $durationMonths = 1; $title = "Plan Empresarial - Mensual";
            }
        }

        $extRef = "SUB|{$tenant->id}|{$durationMonths}|{$amount}";

        try {
            $client = new PreferenceClient();
            $preferenceParams = [
                "items" => [[
                    "id"          => 'SUB_' . $tenantId . '_' . $durationMonths,
                    "title"       => $title . " - Agente Solutions",
                    "description" => "Acceso por {$durationMonths} mes(es) para {$tenant->name}",
                    "quantity"    => 1,
                    "unit_price"  => $amount,
                    "currency_id" => "MXN"
                ]],
                "back_urls" => [
                    "success" => $frontendUrl . "/activacion-cuenta?status=success&tenant_id={$tenantId}",
                    "failure" => $frontendUrl . "/activacion-cuenta?status=failure&tenant_id={$tenantId}",
                    "pending" => $frontendUrl . "/activacion-cuenta?status=pending&tenant_id={$tenantId}"
                ],
                "auto_return"        => "approved",
                "external_reference" => $extRef,
                "notification_url"   => env('APP_URL', 'https://agentesolutionsback-production.up.railway.app') . "/api/mercadopago/webhook"
            ];

            if ($owner && $owner->email) {
                $preferenceParams["payer"] = [
                    "email" => $owner->email,
                    "name"  => trim($owner->first_name . ' ' . $owner->last_name)
                ];
            }

            $preference = $client->create($preferenceParams);

            return response()->json([
                'id'                  => $preference->id,
                'init_point'          => $preference->init_point,
                'sandbox_init_point'  => $preference->sandbox_init_point,
                'amount'              => $amount,
                'type'                => $typeLabel
            ]);

        } catch (\MercadoPago\Exceptions\MPApiException $e) {
            $apiResponse = $e->getApiResponse();
            return response()->json(['error' => 'Error MP', 'details' => $apiResponse ? $apiResponse->getContent() : $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error interno', 'details' => $e->getMessage()], 500);
        }
    }

    public function createPreference(Request $request, $id)
    {
        $quote = \App\Services\AccesoPropiedad::cotizacion($id, null, ['service.property.client', 'workOrder.property.client']);

        try {
            $client = new PreferenceClient();

            // Configurar URLs de retorno a nuestro frontend
            $frontendUrl = $request->header('origin') ?? env('FRONTEND_URL', 'https://agentesolutions-production.up.railway.app');
            // Asegurar que no termine en slash
            $frontendUrl = rtrim($frontendUrl, '/');

            $subtotalBase = (float) str_replace(['$', ',', ' '], '', $quote->total ?? $quote->estimated_amount ?? 0);
            try {
                $rawConcept = $quote->concept ?? $quote->concepto;
                if ($rawConcept) {
                    $detalle = is_string($rawConcept) ? json_decode($rawConcept, true) : $rawConcept;
                    if (is_array($detalle)) {
                        $suma = 0;
                        foreach (($detalle['conceptos'] ?? $detalle['servicios'] ?? []) as $c) {
                            $suma += ((float)($c['precio_u'] ?? $c['precio'] ?? 0)) * ((float)($c['cantidad'] ?? 1));
                        }
                        foreach (($detalle['materiales'] ?? []) as $m) {
                            $suma += ((float)($m['costo_u'] ?? $m['precio'] ?? 0)) * ((float)($m['cantidad'] ?? 1));
                        }
                        if ($suma > 0) $subtotalBase = $suma;
                    }
                }
            } catch (\Exception $e) {}

            if ($subtotalBase > 0) {
                $iva = $subtotalBase * 0.16;
                $subConIva = $subtotalBase + $iva;
                $comisionMP = ($subConIva * 0.0349 + 4) * 1.16;
                $totalBase = round($subConIva + $comisionMP, 2);
            } else {
                $totalBase = 0;
            }

            // Determinar etapa de pago
            $stage = $request->input('payment_stage', 'full'); // 'advance', 'remaining', 'full'

            if ($stage === 'advance') {
                $total = round($totalBase * 0.60, 2);
                $stageLabel = 'Anticipo (60%)';
                // Guardar montos calculados si aun no existen
                if (!$quote->advance_amount) {
                    $quote->advance_amount   = $total;
                    $quote->remaining_amount = round($totalBase * 0.40, 2);
                    $quote->payment_scheme   = 'split';
                    $quote->save();
                }
            } elseif ($stage === 'remaining') {
                $total = round($totalBase * 0.40, 2);
                $stageLabel = 'Liquidación Finiquito (40%)';
            } else {
                $total = $totalBase;
                $stageLabel = 'Pago Total';
            }

            $clientModel = $quote->service?->property?->client ?? $quote->workOrder?->property?->client ?? null;
            $propertyName = $quote->service?->property?->property_name ?? $quote->workOrder?->property?->property_name ?? 'N/A';
            $clientName = $clientModel->name ?? 'Cliente';
            $title = "{$stageLabel} - Cotización #{$quote->id} / {$propertyName}";

            $preferenceParams = [
                "items" => [
                    [
                        "id"          => (string) $quote->id,
                        "title"       => $title,
                        "description" => "Pago de servicio de mantenimiento/reparación",
                        "quantity"    => 1,
                        "unit_price"  => $total,
                        "currency_id" => "MXN"
                    ]
                ],
                "back_urls" => [
                    "success" => $frontendUrl . "/vista-cotizaciones?payment_status=success&quote_id=" . $quote->id . "&stage={$stage}",
                    "failure" => $frontendUrl . "/vista-cotizaciones?payment_status=failure&quote_id=" . $quote->id,
                    "pending" => $frontendUrl . "/vista-cotizaciones?payment_status=pending&quote_id=" . $quote->id
                ],
                "auto_return"        => "approved",
                "external_reference" => $quote->id . '|' . $stage, // Include stage so webhook knows which payment it is
                "notification_url"   => env('APP_URL', 'https://agentesolutionsback-production.up.railway.app') . "/api/mercadopago/webhook"
            ];

            if ($request->user() && $request->user()->email) {
                $preferenceParams["payer"] = [
                    "email" => $request->user()->email,
                    "name" => $request->user()->name ?? 'Cliente'
                ];
            }

            $preference = $client->create($preferenceParams);

            return response()->json([
                'id' => $preference->id,
                'init_point' => $preference->init_point, // URL para redirigir al usuario en producción
                'sandbox_init_point' => $preference->sandbox_init_point // URL para pruebas
            ]);
            
        } catch (\MercadoPago\Exceptions\MPApiException $e) {
            $apiResponse = $e->getApiResponse();
            return response()->json([
                'error' => 'Error de la API de MercadoPago', 
                'details' => $apiResponse ? $apiResponse->getContent() : $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error interno', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Extrae los datos relevantes del pago para guardar como comprobante.
     */
    private function buildPaymentData($payment): array
    {
        $card = $payment->card ?? null;
        $paymentMethodId = $payment->payment_method_id ?? 'desconocido';
        $paymentTypeId = $payment->payment_type_id ?? 'desconocido';
        $lastFourDigits = $card ? ($card->last_four_digits ?? null) : null;
        $cardHolder = $card ? ($card->cardholder->name ?? null) : null;
        $brand = $payment->payment_method_id ?? null;

        // Formatear tipo de pago amigable
        $paymentLabel = match($paymentTypeId) {
            'credit_card' => 'Tarjeta de Crédito',
            'debit_card'  => 'Tarjeta de Débito',
            'account_money' => 'Saldo MercadoPago',
            'bank_transfer' => 'Transferencia Bancaria',
            'ticket' => 'Efectivo (OXXO/CoDi)',
            default => ucfirst($paymentTypeId)
        };

        return [
            'mp_payment_id'     => $payment->id,
            'status'            => $payment->status,
            'amount'            => $payment->transaction_amount,
            'currency'          => $payment->currency_id ?? 'MXN',
            'payment_type'      => $paymentLabel,
            'payment_method'    => strtoupper($brand ?? ''),
            'last_four_digits'  => $lastFourDigits,
            'card_holder'       => $cardHolder,
            'payer_email'       => $payment->payer->email ?? null,
            'date_approved'     => $payment->date_approved ?? null,
            'date_created'      => $payment->date_created ?? null,
        ];
    }

    public function webhook(Request $request)
    {
        // MP puede enviar 'type' o 'topic', y el ID puede estar en 'data.id' o 'id'
        $type = $request->input('type') ?? $request->input('topic');
        $action = $request->input('action');
        $dataId = $request->input('data.id') ?? $request->input('id');

        // Considerar válido si es payment, o si action es payment.created
        if (($type === 'payment' || $action === 'payment.created') && $dataId) {
            try {
                // Instanciar el cliente de pagos de MercadoPago para verificar el estado real del pago
                $paymentClient = new \MercadoPago\Client\Payment\PaymentClient();
                $payment = $paymentClient->get($dataId);

                if ($payment) {
                    $externalRef = $payment->external_reference ?? '';

                    // ─────────────────────────────────────────────────────────────
                    // PAGO DE SUSCRIPCIÓN (SUB|{tenant_id})
                    // ─────────────────────────────────────────────────────────────
                    if (str_starts_with($externalRef, 'SUB|')) {
                        if ($payment->status === 'approved') {
                            $parts = explode('|', $externalRef);
                            $tenantId = (int) ($parts[1] ?? substr($externalRef, 4));
                            $durationMonths = isset($parts[2]) ? (int) $parts[2] : 6;
                            $tenant   = \App\Models\Tenant::find($tenantId);
                            if ($tenant) {
                                $tenant->subscription_status        = 'active';
                                $tenant->subscription_start         = now();
                                $tenant->subscription_expires_at    = now()->addMonths($durationMonths);
                                $tenant->subscription_mp_payment_id = $payment->id;
                                $tenant->billing_cycle              = ($durationMonths >= 12) ? 'annual' : ($durationMonths >= 6 ? 'semiannual' : 'monthly');
                                $tenant->status                     = 'active';
                                $tenant->save();

                                \App\Models\User::where('id', $tenant->owner_user_id)->update(['is_active' => 1]);
                            }
                        }
                        return response()->json(['ok' => true]);
                    }

                    // ─────────────────────────────────────────────────────────────
                    // PAGO DE SUSCRIPCIÓN TÉCNICO EXTERNO ($99/mes - TECH|{user_id})
                    // ─────────────────────────────────────────────────────────────
                    if (str_starts_with($externalRef, 'TECH|')) {
                        if ($payment->status === 'approved') {
                            $techUserId = (int) substr($externalRef, 5);
                            $techUser   = \App\Models\User::find($techUserId);
                            if ($techUser) {
                                $techUser->subscription_status        = 'active';
                                $techUser->subscription_start         = now();
                                // Si ya tenía vigencia en el futuro, se le suma 1 mes, si no, es desde hoy
                                $baseDate = ($techUser->subscription_expires_at && now()->isBefore(\Carbon\Carbon::parse($techUser->subscription_expires_at)))
                                    ? \Carbon\Carbon::parse($techUser->subscription_expires_at)
                                    : now();
                                $techUser->subscription_expires_at    = $baseDate->addMonth();
                                $techUser->subscription_mp_payment_id = $payment->id;
                                $techUser->save();
                            }
                        }
                        return response()->json(['ok' => true]);
                    }

                    // ─────────────────────────────────────────────────────────────
                    // PAGO DE PROPIEDAD EXTRA ($79.99 - EXT_PROP|{tenant_id})
                    // ─────────────────────────────────────────────────────────────
                    if (str_starts_with($externalRef, 'EXT_PROP|')) {
                        if ($payment->status === 'approved') {
                            $parts = explode('|', $externalRef);
                            $tenantId = (int) ($parts[1] ?? 0);
                            $quantity = (int) ($parts[2] ?? 1);
                            if ($quantity < 1) $quantity = 1;
                            $tenant = \App\Models\Tenant::find($tenantId);
                            if ($tenant) {
                                $tenant->extra_properties_count = ($tenant->extra_properties_count ?? 0) + $quantity;
                                $tenant->save();
                            }
                        }
                        return response()->json(['ok' => true]);
                    }

                    // ─────────────────────────────────────────────────────────────
                    // PAGO DE COTIZACIÓN (formato original: "{quote_id}|{stage}")
                    // ─────────────────────────────────────────────────────────────
                    $refParts = explode('|', $externalRef);
                    $quoteIdRef = (int) $refParts[0];
                    $quote = Quote::find($quoteIdRef);

                    if ($quote) {
                        // Si el pago fue aprobado, marcamos la cotización según la etapa
                        if ($payment->status === 'approved') {
                            $stage = $refParts[1] ?? 'full';

                            $paymentData = $this->buildPaymentData($payment);

                            if ($stage === 'advance') {
                                $quote->advance_paid    = true;
                                $quote->advance_paid_at = now();
                                $quote->advance_mp_data = $paymentData;
                                $quote->status          = 'Anticipo Pagado (60%)';
                            } elseif ($stage === 'remaining') {
                                $quote->remaining_paid    = true;
                                $quote->remaining_paid_at = now();
                                $quote->remaining_mp_data = $paymentData;
                                $quote->status            = 'Pagado';
                            } else {
                                $quote->mp_payment_data = $paymentData;
                                $quote->status          = 'Pagado';
                            }

                            $quote->save();

                            // Notificar al Admin
                            $admin = User::where('role_id', 1)->first();
                            if ($admin) {
                                $admin->notify(new MercadoPagoPaymentReceived($quote));
                            }
                            // Notificar al Cliente
                            if ($quote->cliente_user_id) {
                                $clienteUser = User::find($quote->cliente_user_id);
                                if ($clienteUser) {
                                    \Illuminate\Support\Facades\Notification::send(\App\Services\AccesoPropiedad::conInvitados($clienteUser, \App\Services\AccesoPropiedad::propiedadDeCotizacion($quote)), new MercadoPagoPaymentReceived($quote));
                                }
                            }
                        } elseif ($payment->status === 'in_process') {
                            $quote->status = 'Pago en Revisión';
                            $quote->save();
                        }
                    }
                }
            } catch (\Exception $e) {
                // Loggear el error si falla la verificación
                \Log::error('Error verificando Webhook de MercadoPago: ' . $e->getMessage());
                return response()->json(['error' => 'Error processing webhook'], 500);
            }
        }

        // Siempre debemos responder con 200 OK a MercadoPago para que sepa que recibimos la notificación
        return response()->json(['status' => 'success'], 200);
    }

    public function verifyPayment(Request $request)
    {
        $quoteId   = $request->input('quote_id');
        $stage     = $request->input('stage', 'full');
        $paymentId = $request->input('payment_id'); // o collection_id

        if (!$quoteId) {
            return response()->json(['error' => 'quote_id is required'], 400);
        }

        $quote = Quote::find($quoteId);
        if (!$quote) {
            return response()->json(['error' => 'Quote not found'], 404);
        }

        // Si ya está pagado en esa etapa, retornamos success de inmediato
        if ($stage === 'advance' && $quote->advance_paid) {
            return response()->json(['status' => 'success', 'already_paid' => true, 'stage' => 'advance']);
        }
        if ($stage === 'remaining' && $quote->remaining_paid) {
            return response()->json(['status' => 'success', 'already_paid' => true, 'stage' => 'remaining']);
        }
        if ($stage === 'full' && $quote->status === 'Pagado') {
            return response()->json(['status' => 'success', 'already_paid' => true, 'mp_payment_data' => $quote->mp_payment_data]);
        }

        try {
            $paymentClient = new \MercadoPago\Client\Payment\PaymentClient();
            $payment = null;

            // 1. Si enviaron un payment_id concreto, verificar ese pago primero
            if ($paymentId && $paymentId !== 'null' && $paymentId !== 'undefined') {
                try {
                    $p = $paymentClient->get($paymentId);
                    if ($p && $p->status === 'approved') {
                        $payment = $p;
                    }
                } catch (\Exception $e) {}
            }

            // 2. Si no hubo payment_id o no fue approved, buscar por external_reference exacto (ej. "50|remaining")
            if (!$payment) {
                $extReferenceExact = $stage !== 'full' ? ($quote->id . '|' . $stage) : (string) $quote->id;
                $searchRequest = new \MercadoPago\Net\MPSearchRequest(10, 0, [
                    "external_reference" => $extReferenceExact,
                    "status" => "approved"
                ]);

                $searchResult = $paymentClient->search($searchRequest);
                if ($searchResult && !empty($searchResult->results)) {
                    $payment = $searchResult->results[0];
                }
            }

            if ($payment) {
                $paymentData = $this->buildPaymentData($payment);

                if ($stage === 'advance') {
                    $quote->advance_paid    = true;
                    $quote->advance_paid_at = now();
                    $quote->advance_mp_data = $paymentData;
                    $quote->status          = 'Anticipo Pagado (60%)';
                } elseif ($stage === 'remaining') {
                    $quote->remaining_paid    = true;
                    $quote->remaining_paid_at = now();
                    $quote->remaining_mp_data = $paymentData;
                    $quote->status            = 'Pagado';
                } else {
                    $quote->mp_payment_data = $paymentData;
                    $quote->status          = 'Pagado';
                }
                $quote->save();

                // Notificar a los Administradores (rol 0 o 1)
                $admins = User::whereIn('role_id', [0, 1])->get();
                foreach ($admins as $adm) {
                    $adm->notify(new MercadoPagoPaymentReceived($quote));
                }

                // Notificar al Cliente navegando relaciones
                $quote->load(['service.property.client', 'workOrder.property.client']);
                $cliente = $quote->service?->property?->client ?? $quote->workOrder?->property?->client ?? null;
                if ($cliente && $cliente->user_id) {
                    $clienteUser = User::find($cliente->user_id);
                    if ($clienteUser) {
                        \Illuminate\Support\Facades\Notification::send(\App\Services\AccesoPropiedad::conInvitados($clienteUser, \App\Services\AccesoPropiedad::propiedadDeCotizacion($quote)), new MercadoPagoPaymentReceived($quote));
                    }
                }

                return response()->json(['status' => 'success', 'verified' => true, 'stage' => $stage]);
            }

            return response()->json(['status' => 'pending']);

        } catch (\Exception $e) {
            \Log::error('Error verificando pago manualmente: ' . $e->getMessage());
            return response()->json(['status' => 'pending', 'debug' => $e->getMessage()]);
        }
    }
}
