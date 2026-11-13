<?php

namespace App\Http\Controllers;

use App\Models\AccessQr;
use App\Models\NetworkQuote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\MovimientoNotificacion;
use App\Services\AccesoQr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Red (Agente Market): acceso a la propiedad de un trabajo (QR y/o código).
 *
 * - El dueño del trabajo (cliente de la red, o Root/Admin) sube hasta 2 accesos: imagen del QR,
 *   código de texto (PIN, caseta) o ambos. La vigencia es obligatoria y de máximo 7 días.
 * - El técnico asignado ve que hay acceso, pero el detalle (imagen y código) solo cuando marca "Llegué".
 * - Se borra solo al vencer o cuando el trabajo termina o se cancela (App\Services\AccesoQr).
 * Lo interno (Agente Solutions) sigue en AccesoQrController.
 */
class AccesoQrRedController extends Controller
{
    public const MAX_DIAS = 7;

    // ───────────────────────── Contexto y permisos ─────────────────────────

    /** [user, orden, esDueno, esTecnico] o aborta con 403/404. */
    private function contexto(Request $request, int $workOrderId): array
    {
        $user = $request->user();
        $orden = WorkOrder::withoutGlobalScopes()->find($workOrderId);
        if (!$orden) {
            abort(response()->json(['message' => 'Trabajo no encontrado.'], 404));
        }

        $esDueno = AjusteCotizacionController::puedeResponderPorDueno($user, $orden);
        $esTecnico = self::esTecnicoDelTrabajo($user, $orden);
        if (!$esDueno && !$esTecnico) {
            abort(response()->json(['message' => 'No tienes acceso a este trabajo.'], 403));
        }

        // Limpieza al leer: por si el cierre no pasó por el modelo o la vigencia ya venció
        AccesoQr::purgarSiCerrado($orden);
        AccesoQr::purgarVencidos($orden);

        return [$user, $orden, $esDueno, $esTecnico];
    }

    public static function esTecnicoDelTrabajo(User $user, WorkOrder $orden): bool
    {
        if ((int) $orden->tecnico_id === (int) $user->id) return true;
        return NetworkQuote::withoutGlobalScopes()
            ->where('work_order_id', $orden->id)->where('technician_id', $user->id)->where('status', 'accepted')
            ->exists();
    }

    // El técnico ya marcó "Llegué al lugar"
    private static function tecnicoLlego(WorkOrder $orden): bool
    {
        return !empty($orden->arrived_at) || $orden->arrival_status === 'EN_SITIO';
    }

    private function puedeVerDetalle(WorkOrder $orden, bool $esDueno): bool
    {
        return $esDueno || self::tecnicoLlego($orden);
    }

    private function formato(AccessQr $qr, WorkOrder $orden, bool $conDetalle): array
    {
        $codigo = null;
        if ($conDetalle && $qr->codigo) {
            try {
                $codigo = Crypt::decryptString($qr->codigo);
            } catch (\Throwable $e) {
                Log::warning("Acceso de la red: no se pudo descifrar el código del QR {$qr->id}");
            }
        }

        return [
            'slot' => $qr->slot,
            'label' => $qr->label,
            'tiene_imagen' => !empty($qr->mime),
            'tiene_codigo' => !empty($qr->codigo),
            'codigo' => $codigo,
            'note' => $conDetalle ? $qr->note : null,
            'valid_until' => $qr->valid_until?->toIso8601String(),
            'imagen_url' => ($conDetalle && $qr->mime) ? "/red/trabajos/{$orden->id}/acceso/{$qr->slot}/imagen" : null,
            'updated_at' => $qr->updated_at?->toIso8601String(),
        ];
    }

    // ───────────────────────── Endpoints ─────────────────────────

    // GET /red/trabajos/{id}/acceso
    public function index(Request $request, int $id)
    {
        [$user, $orden, $esDueno] = $this->contexto($request, $id);
        $conDetalle = $this->puedeVerDetalle($orden, $esDueno);
        $qrs = AccesoQr::query($orden)->orderBy('slot')->get();

        if (!$esDueno && $conDetalle && $qrs->contains(fn ($q) => $q->codigo)) {
            AccesoQr::log(AccesoQr::alcance($orden) + ['property_id' => $orden->property_id], 'visto', null, (int) $user->id, 'Código (red)', $orden->tenant_id);
        }

        $cerrado = AccesoQr::alcanceCerrado($orden);

        return response()->json([
            'accesos' => $qrs->map(fn ($q) => $this->formato($q, $orden, $conDetalle))->values(),
            'puede_editar' => $esDueno && !$cerrado,
            'puede_ver' => $conDetalle,
            // Para el técnico: hay acceso, pero lo verá al marcar "Llegué"
            'disponible_al_llegar' => !$esDueno && !$conDetalle && $qrs->isNotEmpty(),
            'cerrado' => $cerrado,
            'max_dias' => self::MAX_DIAS,
        ]);
    }

    // GET /red/trabajos/{id}/acceso/{slot}/imagen
    public function imagen(Request $request, int $id, int $slot)
    {
        [$user, $orden, $esDueno] = $this->contexto($request, $id);
        if (!$this->puedeVerDetalle($orden, $esDueno)) {
            return response()->json(['message' => 'Podrás ver el acceso cuando marques que llegaste a la propiedad.'], 403);
        }

        $qr = AccesoQr::query($orden)->where('slot', $slot)->first();
        if (!$qr || !$qr->mime || !$qr->image) {
            return response()->json(['message' => 'Este acceso no tiene imagen.'], 404);
        }

        if (!$esDueno) {
            AccesoQr::log(AccesoQr::alcance($orden) + ['property_id' => $orden->property_id], 'visto', $slot, (int) $user->id, 'Imagen (red)', $orden->tenant_id);
        }

        return response(AccesoQr::descifrar($qr), 200, [
            'Content-Type' => $qr->mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // POST /red/trabajos/{id}/acceso — solo el dueño
    public function store(Request $request, int $id)
    {
        [$user, $orden, $esDueno] = $this->contexto($request, $id);
        if (!$esDueno) {
            return response()->json(['message' => 'Solo el dueño del trabajo puede agregar el acceso.'], 403);
        }

        $limite = now()->addDays(self::MAX_DIAS)->addMinute();
        $request->validate([
            'slot' => 'required|integer|in:' . implode(',', AccesoQr::SLOTS),
            'imagen' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'codigo' => 'nullable|string|max:120',
            'label' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:500',
            'valid_until' => 'required|date|after:now|before_or_equal:' . $limite->toDateTimeString(),
        ], [
            'imagen.mimes' => 'El QR debe ser una imagen JPG, PNG o WEBP.',
            'imagen.max' => 'La imagen no debe pesar más de 5 MB.',
            'valid_until.required' => 'Elige hasta cuándo es válido el acceso.',
            'valid_until.after' => 'La vigencia debe ser una fecha futura.',
            'valid_until.before_or_equal' => 'Por seguridad, el acceso puede durar máximo ' . self::MAX_DIAS . ' días.',
        ]);

        $codigo = trim((string) $request->input('codigo', ''));
        if (!$request->hasFile('imagen') && $codigo === '') {
            return response()->json(['message' => 'Sube la imagen del QR o escribe el código de acceso.', 'errors' => ['imagen' => ['Sube la imagen del QR o escribe el código de acceso.']]], 422);
        }
        if (AccesoQr::alcanceCerrado($orden)) {
            return response()->json(['message' => 'El trabajo ya terminó o se canceló; no se puede agregar un acceso.'], 422);
        }

        $archivo = $request->file('imagen');
        $alcance = AccesoQr::alcance($orden);
        $slot = (int) $request->input('slot');
        $existente = AccesoQr::query($orden)->where('slot', $slot)->exists();

        AccessQr::updateOrCreate($alcance + ['slot' => $slot], [
            'tenant_id' => $orden->tenant_id,
            'property_id' => $orden->property_id,
            'label' => $request->input('label') ?: null,
            'note' => $request->input('note') ?: null,
            // El navegador manda UTC ("…Z"); se guarda en la hora de la app para que venza a la hora elegida
            'valid_until' => \Carbon\Carbon::parse($request->input('valid_until'))->setTimezone(config('app.timezone')),
            'mime' => $archivo ? $archivo->getMimeType() : null,
            'image' => $archivo ? AccesoQr::cifrar($archivo->get()) : null,
            'codigo' => $codigo !== '' ? Crypt::encryptString($codigo) : null,
            'uploaded_by' => $user->id,
        ]);

        AccesoQr::log($alcance + ['property_id' => $orden->property_id], $existente ? 'reemplazado' : 'subido', $slot, (int) $user->id, 'Red', $orden->tenant_id);

        // Avisar al técnico asignado: lo verá al llegar
        $tecnicoId = $orden->tecnico_id ?: NetworkQuote::withoutGlobalScopes()
            ->where('work_order_id', $orden->id)->where('status', 'accepted')->value('technician_id');
        if ($tecnicoId) {
            $this->avisar((int) $tecnicoId, '🔑 Acceso a la propiedad',
                sprintf('El cliente agregó el acceso para "%s" (folio #%d). Lo verás al marcar que llegaste.', trim($orden->type . ($orden->equipment ? ' - ' . $orden->equipment : '')), $orden->id),
                '/mercado-trabajos?trabajo=' . $orden->id, $orden->id);
        }

        return $this->index($request, $id);
    }

    // DELETE /red/trabajos/{id}/acceso/{slot} — solo el dueño
    public function destroy(Request $request, int $id, int $slot)
    {
        [$user, $orden, $esDueno] = $this->contexto($request, $id);
        if (!$esDueno) {
            return response()->json(['message' => 'Solo el dueño del trabajo puede quitar el acceso.'], 403);
        }

        $qr = AccesoQr::query($orden)->where('slot', $slot)->first(['id', 'slot', 'tenant_id']);
        if ($qr) {
            $qr->delete();
            AccesoQr::log(AccesoQr::alcance($orden) + ['property_id' => $orden->property_id], 'eliminado', $slot, (int) $user->id, 'Red', $orden->tenant_id);
        }

        return $this->index($request, $id);
    }

    private function avisar(int $userId, string $titulo, string $mensaje, string $url, int $workOrderId): void
    {
        try {
            $destino = User::withoutGlobalScopes()->find($userId);
            if (!$destino) return;
            $destino->notify(new MovimientoNotificacion([
                'titulo' => $titulo,
                'mensaje' => $mensaje,
                'url' => $url,
                'alert_type' => 'acceso_propiedad',
                'extra' => ['work_order_id' => $workOrderId],
            ]));
            // El push sale solo: PushForDatabaseNotifications lo envía por cada aviso de la campanita
        } catch (\Throwable $e) {
            Log::warning("Acceso de la red: no se pudo avisar al técnico {$userId}: " . $e->getMessage());
        }
    }
}
