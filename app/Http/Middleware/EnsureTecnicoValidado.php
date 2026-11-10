<?php

namespace App\Http\Middleware;

use App\Services\PerfilProfesional;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Candado del perfil profesional (como al solicitar una tarjeta de crédito):
 * un técnico nuevo de la red (roles 6 y 8) solo puede usar las rutas para completar
 * su perfil hasta que Admin/Root lo apruebe. Va en todo el grupo "api".
 */
class EnsureTecnicoValidado
{
    // Lo que sí puede usar mientras tanto (rutas relativas a /api)
    private const PERMITIDAS = [
        'tecnico/perfil-profesional',
        'tecnico/perfil-profesional/*',
        'perfil-profesional/*',
        'specialties',
        'logout',
        'login',
        'notifications/*',
        'tenants/my-membership-status',
        // Google Play: eliminar la cuenta debe ser posible siempre, aun con el perfil sin aprobar
        'users/delete-my-account',
        'usuarios/delete-my-account',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Sin sesión (login, registro, páginas públicas) no hay nada que revisar
        $user = $request->bearerToken() ? $request->user('sanctum') : $request->user();
        if (!$user || !PerfilProfesional::estaBloqueado($user)) {
            return $next($request);
        }

        foreach (self::PERMITIDAS as $patron) {
            if ($request->is('api/' . $patron)) {
                return $next($request);
            }
        }

        $perfil = PerfilProfesional::perfilDe($user);
        return response()->json([
            'blocked' => true,
            'reason' => 'perfil_profesional_pendiente',
            'estado' => $perfil->estado ?? 'incompleto',
            'error' => ($perfil && $perfil->estado === 'en_revision')
                ? 'Estamos revisando tus datos. Te avisaremos cuando tu cuenta esté validada.'
                : 'Completa tu perfil profesional para empezar a usar tu cuenta.',
        ], 403);
    }
}
