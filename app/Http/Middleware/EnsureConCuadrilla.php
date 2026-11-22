<?php

namespace App\Http\Middleware;

use App\Services\Cuadrilla;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Técnico de cuadrilla que quedó sin cuadrilla (lo quitaron o no aceptaron su registro):
 * solo usa su pantalla "Sin cuadrilla" — unirse a otra con un código o pedir volverse independiente —
 * hasta que lo acepten en otra cuadrilla o Root lo cambie a independiente. Va en todo el grupo "api".
 */
class EnsureConCuadrilla
{
    private const PERMITIDAS = [
        'cuadrilla/unirme',
        'cuadrilla/mi-estado',
        'cuadrilla/solicitar-independiente',
        'logout',
        'login',
        'notifications',
        'notifications/*',
        // Google Play: eliminar la cuenta debe ser posible siempre
        'users/delete-my-account',
        'usuarios/delete-my-account',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->bearerToken() ? $request->user('sanctum') : $request->user();
        if (!Cuadrilla::estaSinCuadrilla($user)) {
            return $next($request);
        }
        foreach (self::PERMITIDAS as $patron) {
            if ($request->is('api/' . $patron)) {
                return $next($request);
            }
        }
        return response()->json([
            'blocked' => true,
            'reason' => 'sin_cuadrilla',
            'error' => 'Ya no perteneces a una cuadrilla. Únete a otra con su código o pide trabajar como técnico independiente.',
        ], 403);
    }
}
