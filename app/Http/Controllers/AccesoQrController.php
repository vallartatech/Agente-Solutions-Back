<?php

namespace App\Http\Controllers;

use App\Models\AccessQr;
use App\Services\AccesoQr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * QR de acceso a la propiedad de un trabajo (Agente Solutions).
 *
 * {tipo}: work_order | servicio      {id}: id real del trabajo
 * - Root (0) y Admin (1): suben, reemplazan, ven y eliminan.
 * - Técnico (2): solo si está asignado y ya confirmó su llegada (EN_SITIO).
 */
class AccesoQrController extends Controller
{
    private const ADMIN = [0, 1];
    private const TECNICO = 2;

    private function contexto(Request $request, string $tipo, int $id): array
    {
        $user = $request->user();
        $rol = (int) $user->role_id;

        if (!in_array($rol, [...self::ADMIN, self::TECNICO], true)) {
            abort(response()->json(['message' => 'No tienes acceso a esta información.'], 403));
        }

        $trabajo = AccesoQr::resolverTrabajo($tipo, $id);
        if (!$trabajo) {
            abort(response()->json(['message' => 'Trabajo no encontrado.'], 404));
        }

        $esAdmin = in_array($rol, self::ADMIN, true);
        if (!$esAdmin && !AccesoQr::tecnicoAsignado($user, $trabajo)) {
            abort(response()->json(['message' => 'No estás asignado a este trabajo.'], 403));
        }

        // Limpieza al leer: por si el cierre no pasó por el modelo o la vigencia ya venció
        AccesoQr::purgarSiCerrado($trabajo);
        AccesoQr::purgarVencidos($trabajo);

        return [$user, $trabajo, $esAdmin];
    }

    private function meta(AccessQr $qr, bool $conDetalle): array
    {
        return [
            'slot' => $qr->slot,
            'label' => $qr->label,
            'note' => $conDetalle ? $qr->note : null,
            'valid_until' => $conDetalle ? $qr->valid_until?->toIso8601String() : null,
            'updated_at' => $conDetalle ? $qr->updated_at?->toIso8601String() : null,
        ];
    }

    public function index(Request $request, string $tipo, int $id)
    {
        [$user, $trabajo, $esAdmin] = $this->contexto($request, $tipo, $id);

        $qrs = AccesoQr::query($trabajo)->orderBy('slot')->get(['id', 'slot', 'label', 'note', 'valid_until', 'updated_at']);
        $enSitio = $esAdmin || AccesoQr::tecnicoEnSitio($user, $trabajo);
        $cerrado = AccesoQr::alcanceCerrado($trabajo);

        return response()->json([
            'cantidad' => $qrs->count(),
            'es_lote' => AccesoQr::esLote($trabajo),
            'trabajos_en_lote' => AccesoQr::esLote($trabajo) ? AccesoQr::trabajosDelAlcance($trabajo)->count() : 1,
            'cerrado' => $cerrado,
            'puede_editar' => $esAdmin && !$cerrado,
            // El técnico ve que existe el QR, pero no la imagen ni la nota hasta confirmar su llegada
            'bloqueado' => !$enSitio,
            'qrs' => $qrs->map(fn ($qr) => $this->meta($qr, $enSitio))->values(),
        ]);
    }

    public function imagen(Request $request, string $tipo, int $id, int $slot)
    {
        [$user, $trabajo, $esAdmin] = $this->contexto($request, $tipo, $id);

        if (!$esAdmin && !AccesoQr::tecnicoEnSitio($user, $trabajo)) {
            return response()->json(['message' => 'Confirma tu llegada a la propiedad para ver el QR.'], 403);
        }

        $qr = AccesoQr::query($trabajo)->where('slot', $slot)->first();
        if (!$qr) {
            return response()->json(['message' => 'Este QR ya no está disponible.'], 404);
        }

        try {
            $binario = AccesoQr::descifrar($qr);
        } catch (\Throwable $e) {
            Log::error("QR de acceso {$qr->id} no se pudo descifrar: " . $e->getMessage());
            return response()->json(['message' => 'No se pudo abrir el QR. Pide al administrador que lo vuelva a subir.'], 500);
        }

        AccesoQr::log(AccesoQr::alcance($trabajo) + ['property_id' => $trabajo->property_id], 'visto', $qr->slot, (int) $user->id, null, $qr->tenant_id);

        return response($binario, 200, [
            'Content-Type' => $qr->mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, no-cache, private, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, string $tipo, int $id)
    {
        [$user, $trabajo] = $this->contexto($request, $tipo, $id);

        $request->validate([
            'slot' => 'required|integer|in:' . implode(',', AccesoQr::SLOTS),
            'imagen' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'label' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:500',
            'valid_until' => 'nullable|date|after:now',
        ], [
            'imagen.required' => 'Selecciona la imagen del QR.',
            'imagen.mimes' => 'El QR debe ser una imagen JPG, PNG o WEBP.',
            'imagen.max' => 'La imagen no debe pesar más de 5 MB.',
            'valid_until.after' => 'La vigencia debe ser una fecha futura.',
        ]);

        if (AccesoQr::alcanceCerrado($trabajo)) {
            return response()->json(['message' => 'El trabajo ya está finalizado; no se puede agregar un QR.'], 422);
        }

        $archivo = $request->file('imagen');
        $alcance = AccesoQr::alcance($trabajo);
        $slot = (int) $request->slot;
        $existente = AccesoQr::query($trabajo)->where('slot', $slot)->exists();

        AccessQr::updateOrCreate($alcance + ['slot' => $slot], [
            'tenant_id' => $trabajo->tenant_id,
            'property_id' => $trabajo->property_id,
            'label' => $request->input('label') ?: null,
            'note' => $request->input('note') ?: null,
            'valid_until' => $request->input('valid_until') ?: null,
            'mime' => $archivo->getMimeType(),
            'image' => AccesoQr::cifrar($archivo->get()),
            'uploaded_by' => $user->id,
        ]);

        AccesoQr::log($alcance + ['property_id' => $trabajo->property_id], $existente ? 'reemplazado' : 'subido', $slot, (int) $user->id, null, $trabajo->tenant_id);

        return $this->index($request, $tipo, $id);
    }

    public function destroy(Request $request, string $tipo, int $id, int $slot)
    {
        [$user, $trabajo] = $this->contexto($request, $tipo, $id);

        $qr = AccesoQr::query($trabajo)->where('slot', $slot)->first(['id', 'slot', 'tenant_id']);
        if ($qr) {
            $qr->delete();
            AccesoQr::log(AccesoQr::alcance($trabajo) + ['property_id' => $trabajo->property_id], 'eliminado', $slot, (int) $user->id, null, $trabajo->tenant_id);
        }

        return $this->index($request, $tipo, $id);
    }
}
