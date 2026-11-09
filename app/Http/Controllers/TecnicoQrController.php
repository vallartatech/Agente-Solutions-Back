<?php

namespace App\Http\Controllers;

use App\Models\ClientTechnicianRelation;
use App\Models\User;
use App\Services\PerfilProfesional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * QR del técnico de la red (roles 6 y 8).
 *
 * El QR no se guarda como imagen: solo codifica la URL {app}/t/{qr_codigo}, y el navegador lo dibuja.
 * qr_codigo es aleatorio (no deja adivinar otros perfiles cambiando un número) y permanente:
 * se crea la primera vez que se pide y nunca cambia, así un QR impreso sirve para siempre.
 *
 * - Técnico: GET /mi-qr
 * - Cualquiera (sin sesión): GET /red/qr/{codigo} → perfil público
 * - Cliente particular (rol 4): POST /red/qr/{codigo}/contratar → lo agrega a sus favoritos (máx. 3)
 * - Cliente particular: GET /mis-tecnicos/{id}/detalle → perfil público + código, para "Ver detalles"
 */
class TecnicoQrController extends Controller
{
    public static function codigoDe(User $tecnico): string
    {
        if ($tecnico->qr_codigo) {
            return $tecnico->qr_codigo;
        }
        do {
            $codigo = Str::lower(Str::random(10));
        } while (User::withoutGlobalScopes()->where('qr_codigo', $codigo)->exists());

        $tecnico->forceFill(['qr_codigo' => $codigo])->saveQuietly();
        return $codigo;
    }

    private static function tecnicoPorCodigo(string $codigo): ?User
    {
        $tecnico = User::withoutGlobalScopes()->where('qr_codigo', $codigo)->first();
        return PerfilProfesional::esTecnicoRed($tecnico) ? $tecnico : null;
    }

    // Perfil público (sin contacto, RFC, CLABE, edad ni documentos) + código del QR
    private static function perfil(User $tecnico): array
    {
        $datos = app(PerfilProfesionalController::class)->datosPublicos($tecnico);
        unset($datos['edad']);
        $datos['qr_codigo'] = self::codigoDe($tecnico);
        return $datos;
    }

    // Relación del cliente que mira con el técnico (para el botón "Contratar")
    private static function relacionCon(?User $cliente, User $tecnico): ?array
    {
        if (!$cliente || (int) $cliente->role_id !== MisTecnicosController::ROL_CLIENTE_PARTICULAR) {
            return null;
        }
        $estado = ClientTechnicianRelation::where('client_user_id', $cliente->id)->where('technician_id', $tecnico->id)
            ->where('set_by', ClientTechnicianRelation::POR_CLIENTE)->value('status');
        $teBloqueo = ClientTechnicianRelation::where('client_user_id', $cliente->id)->where('technician_id', $tecnico->id)
            ->where('set_by', ClientTechnicianRelation::POR_TECNICO)->where('status', ClientTechnicianRelation::BLOQUEADO)->exists();

        return [
            'estado' => $estado,
            'no_disponible' => $teBloqueo,
            'favoritos' => MisTecnicosController::totalFavoritos($cliente->id),
            'limite_favoritos' => MisTecnicosController::LIMITE_FAVORITOS,
        ];
    }

    public function miQr(Request $request)
    {
        $user = $request->user();
        if (!PerfilProfesional::esTecnicoRed($user)) {
            return response()->json(['message' => 'Solo los técnicos de la red tienen código QR.'], 403);
        }
        return response()->json(['success' => true, 'codigo' => self::codigoDe($user)]);
    }

    public function porCodigo(Request $request, string $codigo)
    {
        $tecnico = self::tecnicoPorCodigo($codigo);
        if (!$tecnico) {
            return response()->json(['message' => 'Este código QR no corresponde a ningún técnico de la red.'], 404);
        }
        $viewer = auth('sanctum')->user();

        return response()->json([
            'success' => true,
            'data' => self::perfil($tecnico),
            'relacion' => self::relacionCon($viewer, $tecnico),
        ]);
    }

    public function contratar(Request $request, string $codigo)
    {
        $cliente = $request->user();
        if ((int) $cliente->role_id !== MisTecnicosController::ROL_CLIENTE_PARTICULAR) {
            return response()->json(['message' => 'Solo los clientes de la red pueden contratar técnicos.'], 403);
        }
        $tecnico = self::tecnicoPorCodigo($codigo);
        if (!$tecnico || (int) $tecnico->id === (int) $cliente->id) {
            return response()->json(['message' => 'Este código QR no corresponde a ningún técnico de la red.'], 404);
        }

        $relacion = self::relacionCon($cliente, $tecnico);
        if ($relacion['no_disponible']) {
            return response()->json(['message' => 'Este técnico no está disponible para ti.'], 403);
        }
        if ($relacion['estado'] === ClientTechnicianRelation::FAVORITO) {
            return response()->json(['success' => true, 'estado' => ClientTechnicianRelation::FAVORITO, 'ya_era_favorito' => true]);
        }
        if ($relacion['favoritos'] >= MisTecnicosController::LIMITE_FAVORITOS) {
            return response()->json(['message' => MisTecnicosController::mensajeLimite(), 'codigo' => 'limite_favoritos'], 422);
        }

        // Si el cliente lo tenía bloqueado, contratarlo lo desbloquea y lo vuelve favorito
        ClientTechnicianRelation::updateOrCreate(
            ['client_user_id' => $cliente->id, 'technician_id' => $tecnico->id, 'set_by' => ClientTechnicianRelation::POR_CLIENTE],
            ['status' => ClientTechnicianRelation::FAVORITO, 'tenant_id' => ClientTechnicianRelation::tenantDeBloqueo($cliente->tenant_id)]
        );

        return response()->json(['success' => true, 'estado' => ClientTechnicianRelation::FAVORITO, 'tecnico_id' => $tecnico->id]);
    }

    public function detalle(Request $request, $tecnicoId)
    {
        $cliente = $request->user();
        if ((int) $cliente->role_id !== MisTecnicosController::ROL_CLIENTE_PARTICULAR) {
            return response()->json(['message' => 'Solo disponible para clientes particulares.'], 403);
        }
        $tecnico = User::withoutGlobalScopes()->find((int) $tecnicoId);
        if (!PerfilProfesional::esTecnicoRed($tecnico)) {
            return response()->json(['message' => 'Técnico no encontrado.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => self::perfil($tecnico),
            'relacion' => self::relacionCon($cliente, $tecnico),
        ]);
    }
}
