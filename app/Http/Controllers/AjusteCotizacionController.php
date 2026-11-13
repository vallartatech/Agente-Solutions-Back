<?php

namespace App\Http\Controllers;

use App\Models\NetworkQuote;
use App\Models\NetworkQuoteAjuste;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\MovimientoNotificacion;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Red (Agente Market): ajuste de una cotización ya aceptada.
 *
 * Si el problema resulta más grande, el técnico propone un nuevo precio (v2, v3…) con motivo,
 * desglose opcional de servicios/materiales y fotos opcionales. El dueño lo acepta o lo rechaza.
 * - Aceptado: network_quotes.price pasa a ser el nuevo precio (el anterior queda en el historial).
 * - Rechazado: el técnico decide si continúa con el precio vigente o cancela sin penalización.
 */
class AjusteCotizacionController extends Controller
{
    // Estados de la orden en los que se puede ajustar: antes de comenzar y ya en curso
    private const ESTADOS_AJUSTABLES = ['Asignado', 'En Progreso'];
    private const MAX_FOTOS = 4;
    private const MAX_FILAS = 50;
    private const IVA = 0.16;

    /**
     * ¿Puede este usuario aceptar/rechazar por el dueño de la orden?
     * Root/Admin, el dueño de la propiedad o un cliente de la red del mismo tenant.
     * También lo usan /network-quotes/{id}/accept y /reject.
     */
    public static function puedeResponderPorDueno(?User $user, ?WorkOrder $orden): bool
    {
        if (!$user || !$orden) return false;
        if (in_array((int) $user->role_id, [0, 1], true)) return true;

        $duenoId = MisClientesController::duenoDeOrden((int) $orden->id);
        if ($duenoId && (int) $duenoId === (int) $user->id) return true;

        $orden->loadMissing(['property' => fn ($q) => $q->withoutGlobalScopes(), 'property.client' => fn ($q) => $q->withoutGlobalScopes()]);
        $client = $orden->property?->client;
        if ($client && ((int) $client->user_id === (int) $user->id || ($client->email && $client->email === $user->email))) {
            return true;
        }

        // Equipo del cliente de la red (gestor, contratista): mismo tenant que la orden
        $tenantOrden = \App\Models\ClientTechnicianRelation::tenantDeBloqueo($orden->tenant_id ?: $orden->property?->tenant_id);
        return $tenantOrden
            && (int) $user->tenant_id === $tenantOrden
            && in_array((int) $user->role_id, [3, 4, 5, 7], true);
    }

    // GET /mercado-trabajos/{id}/ajustes — historial para el técnico y para el dueño
    public function index(Request $request, $workOrderId)
    {
        $user = $request->user();
        $orden = WorkOrder::withoutGlobalScopes()->find($workOrderId);
        if (!$orden) return response()->json(['success' => false, 'message' => 'Trabajo no encontrado'], 404);

        $quote = $this->cotizacionAceptada($orden->id);
        if (!$quote) {
            return response()->json(['success' => true, 'ajustes' => [], 'precio_original' => null, 'precio_vigente' => null, 'puede_ajustar' => false]);
        }

        $esTecnico = (int) $quote->technician_id === (int) $user->id;
        $esDueno = self::puedeResponderPorDueno($user, $orden);
        if (!$esTecnico && !$esDueno) {
            return response()->json(['success' => false, 'message' => 'No tienes acceso a este trabajo'], 403);
        }

        return response()->json(array_merge(['success' => true], $this->resumen($quote, $orden, $esTecnico, $esDueno)));
    }

    // POST /mercado-trabajos/{id}/ajustes — el técnico propone un nuevo precio
    public function proponer(Request $request, $workOrderId)
    {
        $user = $request->user();
        $orden = WorkOrder::withoutGlobalScopes()->find($workOrderId);
        if (!$orden) return response()->json(['success' => false, 'message' => 'Trabajo no encontrado'], 404);

        $quote = $this->cotizacionAceptada($orden->id);
        if (!$quote || (int) $quote->technician_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Solo el técnico con la cotización aceptada puede ajustarla'], 403);
        }
        if (!in_array($orden->status, self::ESTADOS_AJUSTABLES, true)) {
            return response()->json(['success' => false, 'message' => 'Este trabajo ya no admite ajustes de cotización'], 422);
        }
        if ($quote->ajustes()->where('status', 'pending')->exists()) {
            return response()->json(['success' => false, 'message' => 'Ya tienes un ajuste esperando respuesta del cliente'], 422);
        }

        $request->validate([
            'precio' => 'required|numeric|min:0|max:99999999',
            'motivo' => 'required|string|min:5|max:1000',
            'desglose' => 'nullable|string|max:20000',
            'fotos' => 'nullable|array|max:' . self::MAX_FOTOS,
            'fotos.*' => 'image|max:12288',
            // Pestaña "Cargar archivo": la cotización del técnico en PDF o imagen
            'archivo' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
            'con_iva' => 'nullable|boolean',
        ], [
            'archivo.mimes' => 'El archivo debe ser PDF o imagen (JPG, PNG, WEBP).',
            'archivo.max' => 'El archivo debe pesar menos de 10 MB.',
            'motivo.required' => 'Explica al cliente por qué cambia el precio.',
            'motivo.min' => 'Explica al cliente por qué cambia el precio.',
            'fotos.max' => 'Puedes adjuntar hasta ' . self::MAX_FOTOS . ' fotos.',
            'fotos.*.image' => 'Las fotos deben ser imágenes.',
            'fotos.*.max' => 'Cada foto debe pesar menos de 12 MB.',
        ]);

        $desglose = $this->limpiarDesglose($request->input('desglose'));
        $conIva = $request->boolean('con_iva');
        // Con desglose, el total es la suma de las filas (+16% si marcó IVA); no se confía en el precio enviado
        $precio = $desglose
            ? round($this->totalDesglose($desglose) * ($conIva ? 1 + self::IVA : 1), 2)
            : round((float) $request->input('precio'), 2);
        $precioAnterior = (float) $quote->price;

        if (!$desglose && !$request->hasFile('archivo')) {
            return response()->json(['success' => false, 'message' => 'Agrega al menos un concepto o material, o carga el archivo de la cotización.'], 422);
        }

        if (abs($precio - $precioAnterior) < 0.01) {
            return response()->json(['success' => false, 'message' => 'El nuevo precio es igual al vigente'], 422);
        }

        $fotos = [];
        try {
            foreach ((array) $request->file('fotos', []) as $foto) {
                $fotos[] = $this->subirFoto($foto->getRealPath());
            }
        } catch (\Throwable $e) {
            Log::error('Ajuste de cotización: error subiendo fotos: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'No se pudieron subir las fotos. Inténtalo de nuevo.'], 500);
        }

        $archivo = null;
        if ($request->hasFile('archivo')) {
            try {
                $archivo = [
                    'archivo_url' => $this->subirArchivo($request->file('archivo')->getRealPath()),
                    'archivo_nombre' => mb_substr($request->file('archivo')->getClientOriginalName(), 0, 191),
                ];
            } catch (\Throwable $e) {
                Log::error('Ajuste de cotización: error subiendo el archivo: ' . $e->getMessage());
                return response()->json(['success' => false, 'message' => 'No se pudo subir el archivo. Inténtalo de nuevo.'], 500);
            }
        }
        // Mientras no corra la migración 2026_11_13_140000, el archivo y el IVA no se guardan aparte
        $extra = Schema::hasColumn('network_quote_ajustes', 'con_iva')
            ? (($archivo ?? []) + ['con_iva' => $conIva])
            : [];

        $ajuste = DB::transaction(function () use ($quote, $orden, $user, $precio, $precioAnterior, $request, $desglose, $fotos, $extra) {
            // Sin el orderBy de la relación: MAX con ORDER BY falla con ONLY_FULL_GROUP_BY
            $ultima = (int) NetworkQuoteAjuste::where('network_quote_id', $quote->id)->lockForUpdate()->max('version');
            $version = max($ultima, 1) + 1;
            return NetworkQuoteAjuste::create($extra + [
                'network_quote_id' => $quote->id,
                'work_order_id' => $orden->id,
                'technician_id' => $user->id,
                'version' => $version,
                'precio_anterior' => $precioAnterior,
                'precio' => $precio,
                'motivo' => trim($request->input('motivo')),
                'desglose' => $desglose,
                'fotos' => $fotos ?: null,
                'status' => 'pending',
            ]);
        });

        $this->anotarEnChat($quote, $user, sprintf(
            '📝 [AJUSTE DE COTIZACIÓN v%d]: propongo cambiar el precio de %s a %s. Motivo: "%s"',
            $ajuste->version, $this->dinero($precioAnterior), $this->dinero($precio), $ajuste->motivo
        ));

        // Avisar al dueño (campanita + push)
        $duenoId = MisClientesController::duenoDeOrden((int) $orden->id);
        if ($duenoId) {
            $this->avisar(
                (int) $duenoId,
                'Ajuste de cotización',
                sprintf('%s propone cambiar el precio de "%s" de %s a %s. Revísalo para que pueda continuar.',
                    $this->nombre($user), $this->tituloOrden($orden), $this->dinero($precioAnterior), $this->dinero($precio)),
                '/red-autonomos?trabajo=' . $orden->id,
                'ajuste_cotizacion_propuesto',
                $orden->id
            );
        }

        return response()->json(array_merge(
            ['success' => true, 'message' => 'Ajuste enviado. El cliente debe aceptarlo.'],
            $this->resumen($quote->fresh(), $orden, true, false)
        ));
    }

    // POST /ajustes-cotizacion/{id}/responder — el dueño acepta o rechaza
    public function responder(Request $request, $ajusteId)
    {
        $request->validate(['accion' => 'required|in:aceptar,rechazar']);
        $user = $request->user();

        $ajuste = NetworkQuoteAjuste::find($ajusteId);
        if (!$ajuste) return response()->json(['success' => false, 'message' => 'Ajuste no encontrado'], 404);
        $orden = WorkOrder::withoutGlobalScopes()->find($ajuste->work_order_id);
        if (!self::puedeResponderPorDueno($user, $orden)) {
            return response()->json(['success' => false, 'message' => 'Solo el dueño del trabajo puede responder el ajuste'], 403);
        }
        if ($ajuste->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Este ajuste ya fue respondido'], 422);
        }

        $quote = NetworkQuote::withoutGlobalScopes()->find($ajuste->network_quote_id);
        $acepta = $request->input('accion') === 'aceptar';

        DB::transaction(function () use ($ajuste, $quote, $user, $acepta) {
            $ajuste->status = $acepta ? 'accepted' : 'rejected';
            $ajuste->respondido_por = $user->id;
            $ajuste->respondido_at = now();
            $ajuste->save();

            if ($acepta && $quote) {
                $quote->price = $ajuste->precio;
                $quote->save();
            }
        });

        if ($quote) {
            $this->anotarEnChat($quote, $user, $acepta
                ? sprintf('✅ [AJUSTE v%d ACEPTADO]: el nuevo precio es %s.', $ajuste->version, $this->dinero($ajuste->precio))
                : sprintf('❌ [AJUSTE v%d RECHAZADO]: el precio sigue en %s.', $ajuste->version, $this->dinero($ajuste->precio_anterior)));
        }

        $this->avisar(
            (int) $ajuste->technician_id,
            $acepta ? '✅ Ajuste aceptado' : '❌ Ajuste rechazado',
            $acepta
                ? sprintf('%s aceptó el nuevo precio de %s para "%s". Ya puedes continuar.', $this->nombre($user), $this->dinero($ajuste->precio), $this->tituloOrden($orden))
                : sprintf('%s rechazó el ajuste para "%s". Decide si continúas con %s o cancelas sin penalización.', $this->nombre($user), $this->tituloOrden($orden), $this->dinero($ajuste->precio_anterior)),
            '/mercado-trabajos?trabajo=' . $orden->id,
            $acepta ? 'ajuste_cotizacion_aceptado' : 'ajuste_cotizacion_rechazado',
            $orden->id
        );

        return response()->json(array_merge(
            ['success' => true, 'message' => $acepta ? 'Ajuste aceptado. El técnico ya puede continuar.' : 'Ajuste rechazado. Le avisamos al técnico.'],
            $this->resumen($quote, $orden, false, true)
        ));
    }

    // POST /ajustes-cotizacion/{id}/retirar — el técnico se arrepiente antes de que respondan
    public function retirar(Request $request, $ajusteId)
    {
        $user = $request->user();
        $ajuste = NetworkQuoteAjuste::find($ajusteId);
        if (!$ajuste || (int) $ajuste->technician_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Ajuste no encontrado'], 404);
        }
        if ($ajuste->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'El cliente ya respondió este ajuste'], 422);
        }

        $ajuste->status = 'retirado';
        $ajuste->save();

        $quote = NetworkQuote::withoutGlobalScopes()->find($ajuste->network_quote_id);
        $orden = WorkOrder::withoutGlobalScopes()->find($ajuste->work_order_id);
        if ($quote) {
            $this->anotarEnChat($quote, $user, sprintf('↩️ [AJUSTE v%d RETIRADO]: el precio sigue en %s.', $ajuste->version, $this->dinero($ajuste->precio_anterior)));
        }

        return response()->json(array_merge(
            ['success' => true, 'message' => 'Ajuste retirado.'],
            $this->resumen($quote, $orden, true, false)
        ));
    }

    // POST /ajustes-cotizacion/{id}/decidir — tras un rechazo: continuar o cancelar sin penalización
    public function decidir(Request $request, $ajusteId)
    {
        $request->validate(['decision' => 'required|in:continuar,cancelar']);
        $user = $request->user();

        $ajuste = NetworkQuoteAjuste::find($ajusteId);
        if (!$ajuste || (int) $ajuste->technician_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Ajuste no encontrado'], 404);
        }
        if ($ajuste->status !== 'rejected' || $ajuste->decision_tecnico) {
            return response()->json(['success' => false, 'message' => 'No hay una decisión pendiente para este ajuste'], 422);
        }

        $orden = WorkOrder::withoutGlobalScopes()->with(['property' => fn ($q) => $q->withoutGlobalScopes()])->find($ajuste->work_order_id);
        $quote = NetworkQuote::withoutGlobalScopes()->find($ajuste->network_quote_id);
        $decision = $request->input('decision');

        if ($decision === 'cancelar' && $orden && !in_array($orden->status, self::ESTADOS_AJUSTABLES, true)) {
            return response()->json(['success' => false, 'message' => 'Este trabajo ya no se puede cancelar'], 422);
        }

        $ajuste->decision_tecnico = $decision;
        $ajuste->save();

        if ($decision === 'continuar') {
            if ($quote) {
                $this->anotarEnChat($quote, $user, sprintf('🤝 Continuaré el trabajo con el precio de %s.', $this->dinero($quote->price)));
            }
            $duenoId = $orden ? MisClientesController::duenoDeOrden((int) $orden->id) : null;
            if ($duenoId) {
                $this->avisar((int) $duenoId, 'El técnico continuará el trabajo',
                    sprintf('%s continuará "%s" con el precio de %s.', $this->nombre($user), $this->tituloOrden($orden), $this->dinero($quote?->price ?? $ajuste->precio_anterior)),
                    '/red-autonomos?trabajo=' . $orden->id, 'ajuste_cotizacion_continua', $orden->id);
            }

            return response()->json(array_merge(
                ['success' => true, 'message' => 'Listo, continúas con el precio vigente.'],
                $this->resumen($quote, $orden, true, false)
            ));
        }

        // Cancelar SIN penalización: igual que "cancelar servicio" pero sin el bloqueo de 24 h
        $motivo = 'El cliente no aceptó el ajuste de cotización.';
        $orden->cancelled_by_tech = true;
        $orden->cancelled_technician_id = $user->id;
        $orden->cancellation_reason = $motivo;
        $orden->cancelled_at = now();
        $orden->status = 'Cancelado_Tecnico';
        $orden->tecnico_id = null;
        $orden->save();

        if ($quote) {
            $quote->status = 'rejected';
            $quote->save();
            $this->anotarEnChat($quote, $user, "🚫 [CANCELACIÓN SIN PENALIZACIÓN]: {$motivo}", ['is_cancellation' => true]);
        }

        try {
            $duenoId = MisClientesController::duenoDeOrden((int) $orden->id);
            $dueno = $duenoId ? User::withoutGlobalScopes()->find($duenoId) : null;
            if ($dueno) {
                // Un solo aviso (campanita + push automático)
                $dueno->notify(new \App\Notifications\WorkOrderCancelledNotification($orden, 'client'));
            }
        } catch (\Throwable $e) {
            Log::warning('Ajuste de cotización: no se pudo avisar la cancelación: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Servicio cancelado sin penalización. Le avisamos al cliente.',
            'cancelado' => true,
        ]);
    }

    // ───────────────────────── Ayudantes ─────────────────────────

    private function cotizacionAceptada(int $workOrderId): ?NetworkQuote
    {
        return NetworkQuote::withoutGlobalScopes()
            ->where('work_order_id', $workOrderId)
            ->where('status', 'accepted')
            ->latest('updated_at')
            ->first();
    }

    private function resumen(?NetworkQuote $quote, ?WorkOrder $orden, bool $esTecnico, bool $esDueno): array
    {
        if (!$quote) {
            return ['ajustes' => [], 'precio_original' => null, 'precio_vigente' => null, 'puede_ajustar' => false];
        }

        $ajustes = $quote->ajustes()->get();
        $primero = $ajustes->first();
        $pendiente = $ajustes->firstWhere('status', 'pending');
        $porDecidir = $ajustes->first(fn ($a) => $a->status === 'rejected' && !$a->decision_tecnico);

        return [
            'network_quote_id' => $quote->id,
            'precio_original' => (float) ($primero ? $primero->precio_anterior : $quote->price),
            'precio_vigente' => (float) $quote->price,
            'ajuste_pendiente' => $pendiente ? $this->formato($pendiente) : null,
            'ajuste_por_decidir' => $porDecidir ? $this->formato($porDecidir) : null,
            'puede_ajustar' => $esTecnico && $orden && in_array($orden->status, self::ESTADOS_AJUSTABLES, true) && !$pendiente && !$porDecidir,
            'puede_responder' => $esDueno,
            'ajustes' => $ajustes->map(fn ($a) => $this->formato($a))->values(),
        ];
    }

    private function formato(NetworkQuoteAjuste $a): array
    {
        return [
            'id' => $a->id,
            'version' => (int) $a->version,
            'precio_anterior' => (float) $a->precio_anterior,
            'precio' => (float) $a->precio,
            'motivo' => $a->motivo,
            'desglose' => $a->desglose,
            'fotos' => $a->fotos ?: [],
            'archivo_url' => $a->archivo_url ?? null,
            'archivo_nombre' => $a->archivo_nombre ?? null,
            'con_iva' => (bool) ($a->con_iva ?? false),
            'status' => $a->status,
            'decision_tecnico' => $a->decision_tecnico,
            'created_at' => optional($a->created_at)->toIso8601String(),
            'respondido_at' => optional($a->respondido_at)->toIso8601String(),
        ];
    }

    // Filas válidas de servicios y materiales; null si no hay ninguna
    private function limpiarDesglose($json): ?array
    {
        if (!$json) return null;
        $datos = json_decode($json, true);
        if (!is_array($datos)) return null;

        $limpio = [];
        foreach (['servicios', 'materiales'] as $grupo) {
            $filas = [];
            foreach (array_slice((array) ($datos[$grupo] ?? []), 0, self::MAX_FILAS) as $f) {
                $desc = trim(mb_substr((string) ($f['descripcion'] ?? ''), 0, 200));
                $cant = max(0, (float) ($f['cantidad'] ?? 0));
                $precio = max(0, (float) ($f['precio'] ?? 0));
                if ($desc === '' && $precio <= 0) continue;
                $filas[] = ['descripcion' => $desc, 'cantidad' => $cant ?: 1, 'precio' => round($precio, 2)];
            }
            $limpio[$grupo] = $filas;
        }

        return ($limpio['servicios'] || $limpio['materiales']) ? $limpio : null;
    }

    private function totalDesglose(array $desglose): float
    {
        $total = 0;
        foreach (['servicios', 'materiales'] as $grupo) {
            foreach ($desglose[$grupo] ?? [] as $f) {
                $total += $f['cantidad'] * $f['precio'];
            }
        }
        return round($total, 2);
    }

    private function subirArchivo(string $ruta): string
    {
        $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
        return $cloudinary->uploadApi()->upload($ruta, ['folder' => 'ajustes_cotizacion/archivos', 'resource_type' => 'auto'])['secure_url'];
    }

    private function subirFoto(string $ruta): string
    {
        $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
        return $cloudinary->uploadApi()->upload($ruta, ['folder' => 'ajustes_cotizacion', 'resource_type' => 'image'])['secure_url'];
    }

    // Deja constancia en el chat de la cotización (historial que ya ven ambos)
    private function anotarEnChat(NetworkQuote $quote, User $user, string $mensaje, array $extra = []): void
    {
        try {
            $rol = (int) $user->id === (int) $quote->technician_id ? 'Técnico de la Red' : 'Cliente';
            $historial = $quote->chat_history ?? [];
            $historial[] = array_merge([
                'sender_id' => $user->id,
                'sender_name' => $this->nombre($user),
                'sender_role' => $rol,
                'message' => $mensaje,
                'is_ajuste' => true,
                'created_at' => now()->toIso8601String(),
            ], $extra);
            $quote->chat_history = $historial;
            $quote->save();
        } catch (\Throwable $e) {
            Log::warning('Ajuste de cotización: no se pudo anotar en el chat: ' . $e->getMessage());
        }
    }

    // Campanita (el push sale solo con PushForDatabaseNotifications)
    private function avisar(int $userId, string $titulo, string $mensaje, string $url, string $tipo, int $workOrderId): void
    {
        try {
            $destino = User::withoutGlobalScopes()->find($userId);
            if (!$destino) return;
            $destino->notify(new MovimientoNotificacion([
                'titulo' => $titulo,
                'mensaje' => $mensaje,
                'url' => $url,
                'alert_type' => $tipo,
                'extra' => ['work_order_id' => $workOrderId],
            ]));
            // El push sale solo: PushForDatabaseNotifications lo envía por cada aviso de la campanita
        } catch (\Throwable $e) {
            Log::warning("Ajuste de cotización: no se pudo avisar al usuario {$userId}: " . $e->getMessage());
        }
    }

    private function nombre(User $u): string
    {
        return trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->name ?? 'Usuario');
    }

    private function tituloOrden(?WorkOrder $o): string
    {
        if (!$o) return 'el trabajo';
        return trim($o->type . ($o->equipment ? ' - ' . $o->equipment : '')) . " (folio #{$o->id})";
    }

    private function dinero($valor): string
    {
        return '$' . number_format((float) $valor, 2);
    }
}
