<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Cuadrilla;

/** El contratista (rol 7) administra su cuadrilla: aquí, sacar a un técnico sin borrar su historial. */
class CuadrillaController extends Controller
{
    public function quitar($userId)
    {
        $actual = auth('sanctum')->user();
        $esRoot = $actual && (int) $actual->role_id === 0;
        if (!$esRoot && !Cuadrilla::esContratista($actual)) {
            return response()->json(['error' => 'Solo el contratista puede quitar técnicos de su cuadrilla.'], 403);
        }

        $tecnico = User::withoutGlobalScopes()->find((int) str_replace('u_', '', (string) $userId));
        if (!$tecnico || !Cuadrilla::esRolMiembro($tecnico->role_id)) {
            return response()->json(['error' => 'Técnico de cuadrilla no encontrado.'], 404);
        }
        if (!$esRoot && !Cuadrilla::esDeSuCuadrilla($actual, $tecnico)) {
            return response()->json(['error' => 'Este técnico no es de tu cuadrilla.'], 403);
        }
        if ($tecnico->approval_status === Cuadrilla::SIN_CUADRILLA) {
            return response()->json(['message' => 'Este técnico ya estaba fuera de la cuadrilla.'], 200);
        }
        if ($tecnico->approval_status === 'pending') {
            return response()->json(['error' => 'Su solicitud sigue en la Sala de espera: recházala desde ahí.'], 422);
        }

        $enCurso = Cuadrilla::trabajosEnCurso($tecnico);
        if ($enCurso > 0) {
            return response()->json([
                'error' => "Tiene {$enCurso} " . ($enCurso === 1 ? 'trabajo en curso' : 'trabajos en curso')
                    . '. Asígnalos a alguien más o termínalos antes de quitarlo.',
                'trabajos_en_curso' => $enCurso,
            ], 422);
        }

        Cuadrilla::quitar($tecnico);

        return response()->json([
            'success' => true,
            'message' => 'Técnico quitado de tu cuadrilla. Su cuenta queda desactivada y su historial de trabajos se conserva.',
            'cupo' => Cuadrilla::cupo($tecnico->tenant_id),
        ]);
    }
}
