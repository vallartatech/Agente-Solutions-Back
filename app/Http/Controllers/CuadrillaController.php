<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Cuadrilla;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cuadrilla del contratista (rol 7) y de sus técnicos (8 y 9):
 * - el contratista ve a su equipo, lo quita y decide quién va a cada trabajo;
 * - el técnico con cuenta pide unirse con un código, o pide a Root volverse independiente o tener acceso a la red.
 */
class CuadrillaController extends Controller
{
    /**
     * "Mi cuadrilla": cupo, código para invitar, solicitudes pendientes y el equipo.
     * Del perfil profesional de cada técnico solo se ve si ya está validado, nunca sus documentos.
     */
    public function index()
    {
        $actual = auth('sanctum')->user();
        if (!Cuadrilla::esContratista($actual)) {
            return response()->json(['error' => 'Solo el contratista tiene una cuadrilla.'], 403);
        }

        $tenant = \App\Models\Tenant::find($actual->tenant_id);
        $miembros = User::withoutGlobalScopes()
            ->where('tenant_id', $actual->tenant_id)
            ->whereIn('role_id', Cuadrilla::ROLES_MIEMBRO)
            ->whereIn('approval_status', ['approved', Cuadrilla::SIN_CUADRILLA])
            ->with('specialties:id,name,icon')
            ->orderBy('first_name')
            ->get();

        $perfiles = \App\Services\PerfilProfesional::tablaLista()
            ? \App\Models\TechnicianProfile::whereIn('user_id', $miembros->pluck('id'))->get()->keyBy('user_id')
            : collect();

        $equipo = $miembros->map(function (User $u) use ($perfiles) {
            $perfil = $perfiles->get($u->id);
            $fueraDeCuadrilla = $u->approval_status === Cuadrilla::SIN_CUADRILLA;
            return [
                'id' => $u->id,
                'nombre' => trim("{$u->first_name} {$u->last_name}"),
                'email' => $u->email,
                'telefono' => $u->phone_number,
                'foto' => $u->profile_picture,
                'role_id' => (int) $u->role_id,
                // Técnico 9: además trabaja por su cuenta en la red (sus clientes no se muestran aquí)
                'con_red' => (int) $u->role_id === Cuadrilla::ROL_CUADRILLA_CON_RED,
                'especialidades' => $u->specialties->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'icon' => $s->icon])->values(),
                // activo | bloqueado | fuera (lo quitó; se conserva por su historial)
                'estado' => $fueraDeCuadrilla ? 'fuera' : ($u->is_active ? 'activo' : 'bloqueado'),
                'perfil' => [
                    'estado' => $perfil->estado ?? 'incompleto',
                    'validado' => (bool) ($perfil?->aprobado_at),
                    'identidad_verificada' => (bool) ($perfil?->identidad_verificada_at),
                    'estudios_verificados' => (bool) ($perfil?->estudios_verificados_at),
                ],
                'calificacion' => $u->total_reviews_count ? round((float) $u->rating_stars_avg, 1) : null,
                'total_resenas' => (int) ($u->total_reviews_count ?? 0),
            ];
        })->values();

        $pendientes = User::withoutGlobalScopes()
            ->where('tenant_id', $actual->tenant_id)
            ->whereIn('role_id', Cuadrilla::ROLES_MIEMBRO)
            ->where('approval_status', 'pending')
            ->count() + count(Cuadrilla::usuariosConSolicitud((int) $actual->tenant_id));

        return response()->json([
            'success' => true,
            'cuadrilla' => ['nombre' => $tenant?->name, 'codigo' => $tenant?->code],
            'cupo' => Cuadrilla::cupo($actual->tenant_id),
            'pendientes' => $pendientes,
            'equipo' => $equipo,
        ]);
    }

    /**
     * Público: el formulario de registro del técnico de cuadrilla verifica el código (escrito o del QR)
     * antes de enviarlo. Solo dice de qué cuadrilla es y si tiene lugar; nada de su equipo.
     */
    public function verificarCodigo(Request $request)
    {
        $codigo = trim((string) $request->query('codigo', ''));
        $tenant = ($codigo !== '' && mb_strlen($codigo) <= 64) ? Cuadrilla::cuadrillaPorCodigo($codigo) : null;
        if (!$tenant) {
            return response()->json([
                'valido' => false,
                'error' => 'Ese código no es de ninguna cuadrilla. Revísalo o pídeselo de nuevo a tu contratista.',
            ], 404);
        }

        $dueno = User::withoutGlobalScopes()->find($tenant->owner_user_id);
        return response()->json([
            'valido' => true,
            'codigo' => $tenant->code,
            'cuadrilla' => $tenant->name,
            'contratista' => trim("{$dueno->first_name} {$dueno->last_name}"),
            // Puede registrarse igual, pero su contratista necesita lugar para aprobarlo
            'llena' => Cuadrilla::estaLlena((int) $tenant->id),
        ]);
    }

    /** El contratista saca a un técnico: el 8 queda sin cuadrilla y el 9 pasa a independiente. */
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

        // Solo cuentan los trabajos del contratista (los trabajos propios de un 9 son suyos)
        $contratistaId = $esRoot ? Cuadrilla::contratistaDe($tecnico)?->id : $actual->id;
        $enCurso = Cuadrilla::trabajosEnCurso($tecnico, $contratistaId);
        if ($enCurso > 0) {
            return response()->json([
                'error' => "Tiene {$enCurso} " . ($enCurso === 1 ? 'trabajo en curso' : 'trabajos en curso')
                    . '. Asígnalos a alguien más o termínalos antes de quitarlo.',
                'trabajos_en_curso' => $enCurso,
            ], 422);
        }

        $tenantId = $tecnico->tenant_id;
        $resultado = Cuadrilla::quitar($tecnico);

        return response()->json([
            'success' => true,
            'resultado' => $resultado,
            'message' => $resultado === 'independiente'
                ? 'Técnico quitado de tu cuadrilla. Como ya tenía acceso a la red, sigue trabajando como técnico independiente.'
                : 'Técnico quitado de tu cuadrilla. Su cuenta queda desactivada y su historial de trabajos se conserva.',
            'cupo' => Cuadrilla::cupo($tenantId),
        ]);
    }

    /** Orden donde el usuario es el titular (contratista), o la respuesta de error. */
    private function ordenDelContratista($workOrderId): array
    {
        $actual = auth('sanctum')->user();
        if (!Cuadrilla::esContratista($actual)) {
            return [null, null, response()->json(['error' => 'Solo el contratista asigna trabajos a su cuadrilla.'], 403)];
        }
        $orden = WorkOrder::withoutGlobalScopes()->find((int) $workOrderId);
        if (!$orden) {
            return [null, null, response()->json(['error' => 'Trabajo no encontrado.'], 404)];
        }
        if (!Cuadrilla::esTitular($actual, $orden)) {
            return [null, null, response()->json(['error' => 'Este trabajo no es tuyo.'], 403)];
        }
        return [$actual, $orden, null];
    }

    /** Quién va hoy a esta orden y a quién más puede mandar (con sus horarios ocupados). */
    public function equipoDeTrabajo($workOrderId)
    {
        [$actual, $orden, $error] = $this->ordenDelContratista($workOrderId);
        if ($error) {
            return $error;
        }
        return response()->json([
            'success' => true,
            'equipo' => Cuadrilla::equipoDeOrden((int) $orden->id),
            'contratista_id' => (int) $actual->id,
            'scheduled_at' => $orden->scheduled_at,
            'cerrado' => Cuadrilla::estaCerrada($orden->status),
            'miembros' => Cuadrilla::miembrosParaAsignar($actual, (int) $orden->id),
            'ocupado_contratista' => Cuadrilla::horariosOcupados($actual, (int) $orden->id),
        ]);
    }

    /** El contratista decide quién va: él y/o técnicos de su cuadrilla. Se puede cambiar mientras no termine. */
    public function asignarEquipo(Request $request, $workOrderId)
    {
        [$actual, $orden, $error] = $this->ordenDelContratista($workOrderId);
        if ($error) {
            return $error;
        }
        if (Cuadrilla::estaCerrada($orden->status)) {
            return response()->json(['error' => 'Este trabajo ya terminó: ya no se puede cambiar quién fue.'], 422);
        }
        $datos = $request->validate(['tecnicos' => 'required|array|min:1', 'tecnicos.*' => 'integer'], [
            'tecnicos.required' => 'Elige al menos a una persona para este trabajo (puedes ser tú).',
            'tecnicos.min' => 'Elige al menos a una persona para este trabajo (puedes ser tú).',
        ]);

        $resultado = Cuadrilla::asignar($orden, $actual, $datos['tecnicos']);
        if (is_string($resultado)) {
            return response()->json(['error' => $resultado], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Listo: el equipo de este trabajo quedó actualizado.',
            'equipo' => Cuadrilla::equipoDeOrden((int) $orden->id),
            'agregados' => $resultado['agregados'],
            'quitados' => $resultado['quitados'],
        ]);
    }

    /** Un técnico con cuenta pide unirse a una cuadrilla con el código del contratista. */
    public function unirme(Request $request)
    {
        $actual = auth('sanctum')->user();
        $datos = $request->validate(['codigo' => 'required|string|max:100'], [
            'codigo.required' => 'Escribe el código de la cuadrilla.',
        ]);

        $resultado = Cuadrilla::solicitarUnirse($actual, $datos['codigo']);
        if (is_string($resultado)) {
            return response()->json(['error' => $resultado], 422);
        }

        $jefe = User::withoutGlobalScopes()->find(DB::table('tenants')->where('id', $resultado->tenant_id)->value('owner_user_id'));
        return response()->json([
            'success' => true,
            'message' => 'Tu solicitud llegó a la Sala de espera de ' . trim(($jefe->first_name ?? '') . ' ' . ($jefe->last_name ?? 'el contratista'))
                . '. Te avisaremos cuando te acepte.',
        ]);
    }

    /** Estado del técnico sin cuadrilla (o que pidió unirse a una): para su pantalla de opciones. */
    public function miEstado()
    {
        $actual = auth('sanctum')->user();
        $solicitud = Cuadrilla::solicitudPendiente((int) $actual->id);
        $contratista = $solicitud
            ? User::withoutGlobalScopes()->find(DB::table('tenants')->where('id', $solicitud->tenant_id)->value('owner_user_id'))
            : null;

        return response()->json([
            'success' => true,
            'role_id' => (int) $actual->role_id,
            'approval_status' => $actual->approval_status,
            'sin_cuadrilla' => Cuadrilla::estaSinCuadrilla($actual),
            'solicitud' => $solicitud ? [
                'contratista' => $contratista ? trim("{$contratista->first_name} {$contratista->last_name}") : null,
                'enviada_at' => $solicitud->created_at,
            ] : null,
        ]);
    }

    /** Pide a Root volverse técnico independiente (6). Mientras los cobros estén en pausa, Root lo hace a mano. */
    public function solicitarIndependiente()
    {
        $actual = auth('sanctum')->user();
        if (!Cuadrilla::esRolMiembro($actual->role_id)) {
            return response()->json(['error' => 'Tu cuenta ya trabaja de forma independiente.'], 422);
        }
        $nombre = trim("{$actual->first_name} {$actual->last_name}");
        Cuadrilla::avisarRoot('Quiere ser técnico independiente', "{$nombre} ({$actual->email}) pidió volverse técnico independiente. Cámbialo a rol 6 en Usuarios.", 'cuadrilla_pide_independiente');

        return response()->json(['success' => true, 'message' => 'Listo: le avisamos al equipo de Agente Market. Te contactarán para activar tu cuenta como técnico independiente.']);
    }

    /** El técnico de cuadrilla (8) pide acceso a la red (rol 9). Su contratista no tiene que autorizarlo. */
    public function solicitarRed()
    {
        $actual = auth('sanctum')->user();
        if ((int) $actual->role_id !== Cuadrilla::ROL_CUADRILLA || Cuadrilla::estaSinCuadrilla($actual)) {
            return response()->json(['error' => 'Esta opción es para técnicos de cuadrilla.'], 422);
        }
        $nombre = trim("{$actual->first_name} {$actual->last_name}");
        Cuadrilla::avisarRoot('Pide acceso a la red', "{$nombre} ({$actual->email}) quiere trabajar también por su cuenta en la red. Cámbialo a rol 9 en Usuarios.", 'cuadrilla_pide_red');

        return response()->json(['success' => true, 'message' => 'Listo: le avisamos al equipo de Agente Market. Te contactarán para activar tu acceso a la red.']);
    }
}
