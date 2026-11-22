<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MovimientoNotificacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Cuadrilla de un contratista (rol 7) en Agente Market.
 *
 * - Sus técnicos (8, y 9 si pagan el acceso a la red) viven en la empresa (tenant) del contratista.
 * - El contratista cotiza y es el titular del trabajo; después decide quién va a la casa: él mismo y/o
 *   técnicos de su cuadrilla. Los que van quedan en work_order_technician.
 * - El contratista solo da de alta, aprueba, rechaza o quita técnicos de su cuadrilla; no cambia roles.
 * - Cupo por contratista: tenants.max_cuadrilla (7 al empezar; Root lo amplía).
 * - Quitar no borra nada. El 8 queda "sin cuadrilla" (desactivado, ligado a la misma empresa para que sus
 *   trabajos sigan mostrando su nombre) hasta que se una a otra o se vuelva independiente; el 9 ya paga la red,
 *   así que pasa directo a independiente (6).
 * - Un técnico que ya tiene cuenta (independiente o sin cuadrilla) pide unirse con el código del contratista:
 *   la solicitud espera en la Sala de espera y, al aceptarla, entra como técnico de cuadrilla (8).
 */
class Cuadrilla
{
    public const ROL_CONTRATISTA = 7;
    public const ROL_CUADRILLA = 8;
    public const ROL_CUADRILLA_CON_RED = 9;
    public const ROL_INDEPENDIENTE = 6;
    /** Roles que forman parte de una cuadrilla. */
    public const ROLES_MIEMBRO = [8, 9];
    public const LIMITE_INICIAL = 7;

    /** Técnico que su contratista quitó: solo puede unirse a otra cuadrilla o pedir volverse independiente. */
    public const SIN_CUADRILLA = 'sin_cuadrilla';
    /** Registro con código que el contratista rechazó. */
    public const RECHAZADO = 'rechazado_cuadrilla';

    /** Estados de una orden en curso. */
    private const ESTADOS_EN_CURSO = ['asignado', 'en progreso', 'en proceso', 'en_sitio', 'por aprobar'];
    /** Estados de una orden que ya terminó: ahí no se cambia quién fue. */
    private const ESTADOS_CERRADOS = ['terminado', 'finalizado', 'listo', 'completado', 'aprobado', 'entregado'];

    // ─────────────────────────────── Roles ───────────────────────────────

    public static function esContratista(?User $user): bool
    {
        return $user && (int) $user->role_id === self::ROL_CONTRATISTA && !empty($user->tenant_id);
    }

    public static function esRolMiembro($roleId): bool
    {
        return in_array((int) $roleId, self::ROLES_MIEMBRO, true);
    }

    /** El técnico pertenece (o perteneció) a la cuadrilla del contratista. */
    public static function esDeSuCuadrilla(User $contratista, User $tecnico): bool
    {
        return self::esContratista($contratista)
            && self::esRolMiembro($tecnico->role_id)
            && (int) $tecnico->tenant_id === (int) $contratista->tenant_id;
    }

    /** Contratista (dueño de la empresa) del técnico de cuadrilla, o null. */
    public static function contratistaDe(?User $tecnico): ?User
    {
        if (!$tecnico || !self::esRolMiembro($tecnico->role_id) || !$tecnico->tenant_id) {
            return null;
        }
        $duenoId = DB::table('tenants')->where('id', $tecnico->tenant_id)->value('owner_user_id');
        $dueno = $duenoId ? User::withoutGlobalScopes()->find($duenoId) : null;
        return ($dueno && (int) $dueno->role_id === self::ROL_CONTRATISTA) ? $dueno : null;
    }

    /** Perfil profesional validado (o técnico que ya existía): ya puede ir a un trabajo. */
    public static function perfilListo(User $tecnico): bool
    {
        return !PerfilProfesional::estaBloqueado($tecnico);
    }

    // ─────────────────────────────── Cupo ───────────────────────────────

    public static function limite(?int $tenantId): int
    {
        if (!$tenantId || !Schema::hasColumn('tenants', 'max_cuadrilla')) {
            return self::LIMITE_INICIAL;
        }
        $limite = DB::table('tenants')->where('id', $tenantId)->value('max_cuadrilla');
        return $limite === null ? self::LIMITE_INICIAL : (int) $limite;
    }

    /** Técnicos activos y aprobados de la cuadrilla (no cuenta al contratista ni a los pendientes). */
    public static function ocupados(?int $tenantId): int
    {
        if (!$tenantId) {
            return 0;
        }
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('role_id', self::ROLES_MIEMBRO)
            ->where('approval_status', 'approved')
            ->where('is_active', 1)
            ->count();
    }

    public static function estaLlena(?int $tenantId): bool
    {
        return self::ocupados($tenantId) >= self::limite($tenantId);
    }

    public static function cupo(?int $tenantId): array
    {
        return ['ocupados' => self::ocupados($tenantId), 'limite' => self::limite($tenantId)];
    }

    public static function mensajeLlena(?int $tenantId): string
    {
        $c = self::cupo($tenantId);
        return "Tu cuadrilla está llena ({$c['ocupados']} de {$c['limite']} técnicos). "
            . 'Quita a alguien o amplía tu equipo para agregar a otro.';
    }

    // ─────────────────────────────── Trabajos ───────────────────────────────

    public static function estaCerrada(?string $status): bool
    {
        $s = mb_strtolower((string) $status);
        return in_array($s, self::ESTADOS_CERRADOS, true) || str_starts_with($s, 'cancelado');
    }

    /** Titular del trabajo: quien cotizó y lo ganó (el contratista en los trabajos de su cuadrilla). */
    public static function titularDe(object $orden): ?int
    {
        if (!empty($orden->tecnico_id)) {
            return (int) $orden->tecnico_id;
        }
        $id = DB::table('network_quotes')->where('work_order_id', $orden->id)->where('status', 'accepted')->value('technician_id');
        return $id ? (int) $id : null;
    }

    public static function esTitular(User $user, object $orden): bool
    {
        $titular = self::titularDe($orden);
        return $titular !== null && $titular === (int) $user->id;
    }

    /** Ids de quienes van a la casa (el titular y/o los técnicos de su cuadrilla). */
    public static function equipoDeOrden(int $ordenId): array
    {
        if (!Schema::hasTable('work_order_technician')) {
            return [];
        }
        return DB::table('work_order_technician')->where('work_order_id', $ordenId)
            ->pluck('technician_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public static function esDelEquipo(User $user, int $ordenId): bool
    {
        return in_array((int) $user->id, self::equipoDeOrden($ordenId), true);
    }

    /**
     * Órdenes en curso donde el técnico va como parte del equipo (o es el titular).
     * Con $titularId solo cuenta las de ese titular (las de su contratista, no los trabajos propios de un 9).
     */
    public static function trabajosEnCurso(User $tecnico, ?int $titularId = null): int
    {
        $ids = DB::table('work_orders')->where('tecnico_id', $tecnico->id)->pluck('id');
        if (Schema::hasTable('work_order_technician')) {
            $ids = $ids->merge(DB::table('work_order_technician')->where('technician_id', $tecnico->id)->pluck('work_order_id'));
        }
        if ($ids->isEmpty()) {
            return 0;
        }
        return DB::table('work_orders')
            ->whereIn('id', $ids->unique()->values())
            ->whereIn(DB::raw('LOWER(status)'), self::ESTADOS_EN_CURSO)
            ->when($titularId, fn ($q) => $q->where('tecnico_id', $titularId))
            ->count();
    }

    /** Trabajos propios en curso (donde él es el titular): no puede unirse a una cuadrilla con ellos. */
    public static function trabajosPropiosEnCurso(User $tecnico): int
    {
        $aceptadas = DB::table('network_quotes')->where('technician_id', $tecnico->id)->where('status', 'accepted')->pluck('work_order_id');
        return DB::table('work_orders')
            ->where(fn ($q) => $q->where('tecnico_id', $tecnico->id)->orWhereIn('id', $aceptadas))
            ->whereIn(DB::raw('LOWER(status)'), self::ESTADOS_EN_CURSO)
            ->count();
    }

    /** Lo que ve el técnico de un trabajo de su tablero: si va por su contratista, con quién va y si puede asignar. */
    public static function vistaDelTecnico(object $orden, User $user): array
    {
        $titularId = self::titularDe($orden);
        $equipoIds = self::equipoDeOrden((int) $orden->id);
        $esCuadrilla = $titularId !== null && $titularId !== (int) $user->id && in_array((int) $user->id, $equipoIds, true);

        $personas = User::withoutGlobalScopes()
            ->whereIn('id', array_values(array_unique(array_merge($equipoIds, $titularId ? [$titularId] : []))))
            ->get()
            ->keyBy('id');
        $titular = $titularId ? $personas->get($titularId) : null;

        return [
            'es_cuadrilla' => $esCuadrilla,
            'contratista' => ($esCuadrilla && $titular) ? [
                'id' => $titular->id,
                'nombre' => trim("{$titular->first_name} {$titular->last_name}"),
                'telefono' => $titular->phone_number,
                'foto' => $titular->profile_picture,
            ] : null,
            'equipo' => collect($equipoIds)->map(fn ($id) => $personas->get($id))->filter()->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => trim("{$p->first_name} {$p->last_name}"),
                'foto' => $p->profile_picture,
                'es_titular' => (int) $p->id === $titularId,
            ])->values()->all(),
            'puede_asignar_cuadrilla' => !$esCuadrilla && self::esContratista($user)
                && $titularId === (int) $user->id && !self::estaCerrada($orden->status ?? null),
        ];
    }

    /** Quién va a la casa, para el cliente (nombre, foto y si es el contratista). Sin teléfonos. */
    public static function equipoVisible(object $orden): array
    {
        $equipoIds = self::equipoDeOrden((int) $orden->id);
        if (empty($equipoIds)) {
            return [];
        }
        $titularId = self::titularDe($orden);
        return User::withoutGlobalScopes()->whereIn('id', $equipoIds)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'nombre' => trim("{$p->first_name} {$p->last_name}"),
                'foto' => $p->profile_picture,
                'es_titular' => (int) $p->id === $titularId,
                'calificacion' => $p->total_reviews_count ? round((float) $p->rating_stars_avg, 1) : null,
            ])
            ->sortByDesc('es_titular')->values()->all();
    }

    /**
     * Técnicos que el contratista puede mandar a esta orden, con sus horarios ocupados
     * (sin decir de qué cliente son: los trabajos propios de un 9 solo aparecen como "ocupado").
     */
    public static function miembrosParaAsignar(User $contratista, int $ordenId): array
    {
        $asignados = self::equipoDeOrden($ordenId);
        $miembros = User::withoutGlobalScopes()
            ->where('tenant_id', $contratista->tenant_id)
            ->whereIn('role_id', self::ROLES_MIEMBRO)
            ->where('approval_status', 'approved')
            ->where('is_active', 1)
            ->orderBy('first_name')
            ->get();

        return $miembros->map(fn (User $m) => [
            'id' => $m->id,
            'nombre' => trim("{$m->first_name} {$m->last_name}"),
            'foto' => $m->profile_picture,
            'perfil_listo' => self::perfilListo($m),
            'asignado' => in_array((int) $m->id, $asignados, true),
            'ocupado' => self::horariosOcupados($m, $ordenId),
        ])->values()->all();
    }

    /** Fechas de otros trabajos en curso del técnico (de su contratista o propios), sin datos del cliente. */
    public static function horariosOcupados(User $tecnico, int $excluirOrdenId): array
    {
        $ids = DB::table('work_orders')->where('tecnico_id', $tecnico->id)->pluck('id');
        if (Schema::hasTable('work_order_technician')) {
            $ids = $ids->merge(DB::table('work_order_technician')->where('technician_id', $tecnico->id)->pluck('work_order_id'));
        }
        return DB::table('work_orders')
            ->whereIn('id', $ids->unique()->values())
            ->where('id', '!=', $excluirOrdenId)
            ->whereIn(DB::raw('LOWER(status)'), self::ESTADOS_EN_CURSO)
            ->whereNotNull('scheduled_at')
            ->orderBy('scheduled_at')
            ->pluck('scheduled_at')
            ->map(fn ($f) => \Illuminate\Support\Carbon::parse($f)->format('Y-m-d H:i'))
            ->values()->all();
    }

    /**
     * El contratista decide quién va a la casa: él mismo y/o técnicos de su cuadrilla (aprobados y con el perfil
     * validado). Devuelve [agregados, quitados] o un texto de error.
     */
    public static function asignar(object $orden, User $contratista, array $ids): array|string
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 'Elige al menos a una persona para este trabajo (puedes ser tú).';
        }

        $miembros = User::withoutGlobalScopes()->whereIn('id', $ids->reject(fn ($id) => $id === (int) $contratista->id))->get();
        if ($miembros->count() !== $ids->reject(fn ($id) => $id === (int) $contratista->id)->count()) {
            return 'Alguno de los técnicos ya no existe.';
        }
        foreach ($miembros as $m) {
            if (!self::esDeSuCuadrilla($contratista, $m) || $m->approval_status !== 'approved' || !$m->is_active) {
                return trim("{$m->first_name} {$m->last_name}") . ' no está activo en tu cuadrilla.';
            }
            if (!self::perfilListo($m)) {
                return trim("{$m->first_name} {$m->last_name}") . ' todavía no tiene su perfil profesional validado.';
            }
        }

        $antes = self::equipoDeOrden((int) $orden->id);
        $despues = $ids->all();
        $agregados = array_values(array_diff($despues, $antes));
        $quitados = array_values(array_diff($antes, $despues));

        DB::transaction(function () use ($orden, $agregados, $quitados) {
            if (!empty($quitados)) {
                DB::table('work_order_technician')->where('work_order_id', $orden->id)->whereIn('technician_id', $quitados)->delete();
            }
            foreach ($agregados as $id) {
                DB::table('work_order_technician')->insertOrIgnore([
                    'work_order_id' => $orden->id, 'technician_id' => $id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $trabajo = self::nombreTrabajo($orden);
        $cuando = !empty($orden->scheduled_at) ? ' para el ' . \Illuminate\Support\Carbon::parse($orden->scheduled_at)->format('d/m/Y H:i') : '';
        $jefe = trim("{$contratista->first_name} {$contratista->last_name}");
        foreach ($agregados as $id) {
            if ($id === (int) $contratista->id) continue;
            self::avisar($id, '🛠️ Tu contratista te asignó un trabajo', "{$jefe} te asignó \"{$trabajo}\"{$cuando}. Lo encuentras en tu tablero.", '/mercado-trabajos', 'cuadrilla_asignado', (int) $orden->id);
        }
        foreach ($quitados as $id) {
            if ($id === (int) $contratista->id) continue;
            self::avisar($id, 'Cambio en tus trabajos', "{$jefe} ya no te tiene asignado \"{$trabajo}\".", '/mercado-trabajos', 'cuadrilla_desasignado', (int) $orden->id);
        }

        return ['agregados' => $agregados, 'quitados' => $quitados];
    }

    // ─────────────────────────────── Salir de una cuadrilla ───────────────────────────────

    /**
     * Lo saca de la cuadrilla sin borrar nada. El 8 queda sin cuadrilla (desactivado, con su historial intacto);
     * el 9 ya paga la red, así que pasa a técnico independiente. Devuelve 'sin_cuadrilla' o 'independiente'.
     */
    public static function quitar(User $tecnico): string
    {
        if ((int) $tecnico->role_id === self::ROL_CUADRILLA_CON_RED) {
            self::hacerIndependiente($tecnico);
            return 'independiente';
        }
        DB::table('users')->where('id', $tecnico->id)->update([
            'is_active' => 0,
            'approval_status' => self::SIN_CUADRILLA,
            'updated_at' => now(),
        ]);
        $tecnico->tokens()->delete();
        return self::SIN_CUADRILLA;
    }

    /** Rechaza un registro pendiente. Nunca trabajó con el contratista, así que se desliga de su empresa. */
    public static function rechazar(User $tecnico): void
    {
        DB::table('users')->where('id', $tecnico->id)->update([
            'is_active' => 0,
            'approval_status' => self::RECHAZADO,
            'tenant_id' => null,
            'updated_at' => now(),
        ]);
        $tecnico->tokens()->delete();
    }

    /** Técnico independiente (6): sin empresa, activo y con su perfil profesional de siempre. */
    public static function hacerIndependiente(User $tecnico): void
    {
        DB::table('users')->where('id', $tecnico->id)->update([
            'role_id' => self::ROL_INDEPENDIENTE,
            'tenant_id' => null,
            'approval_status' => 'approved',
            'is_active' => 1,
            'updated_at' => now(),
        ]);
        self::cancelarSolicitudes((int) $tecnico->id);
        $tecnico->tokens()->delete();
    }

    /** Sin cuadrilla o con el registro rechazado: solo puede unirse a otra o pedir ser independiente. */
    public static function estaSinCuadrilla(?User $user): bool
    {
        return $user && in_array($user->approval_status, [self::SIN_CUADRILLA, self::RECHAZADO], true);
    }

    // ─────────────────────────────── Solicitudes para unirse ───────────────────────────────

    public static function haySolicitudes(): bool
    {
        return Schema::hasTable('solicitudes_cuadrilla');
    }

    public static function solicitudPendiente(int $userId, ?int $tenantId = null): ?object
    {
        if (!self::haySolicitudes()) {
            return null;
        }
        return DB::table('solicitudes_cuadrilla')
            ->where('user_id', $userId)->where('estado', 'pendiente')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('id')->first();
    }

    /** Ids de técnicos con una solicitud pendiente para esta cuadrilla. */
    public static function usuariosConSolicitud(int $tenantId): array
    {
        if (!self::haySolicitudes()) {
            return [];
        }
        return DB::table('solicitudes_cuadrilla')->where('tenant_id', $tenantId)->where('estado', 'pendiente')
            ->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Contratista dueño de un código de cuadrilla, o null. */
    public static function cuadrillaPorCodigo(string $codigo): ?Tenant
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            return null;
        }
        $tenant = Tenant::where('code', $codigo)->orWhere('code', strtoupper($codigo))->first();
        if (!$tenant || $tenant->membership_type !== 'market_contractor') {
            return null;
        }
        $dueno = $tenant->owner_user_id ? User::withoutGlobalScopes()->find($tenant->owner_user_id) : null;
        return ($dueno && (int) $dueno->role_id === self::ROL_CONTRATISTA) ? $tenant : null;
    }

    /**
     * Un técnico con cuenta pide unirse a una cuadrilla. Puede hacerlo el independiente (6) sin trabajos propios
     * en curso, y el de cuadrilla que quedó sin cuadrilla. Devuelve la solicitud o un texto de error.
     */
    public static function solicitarUnirse(User $tecnico, string $codigo): object|string
    {
        if (!self::haySolicitudes()) {
            return 'Por ahora no se pueden enviar solicitudes. Intenta más tarde.';
        }
        $rol = (int) $tecnico->role_id;
        $puede = ($rol === self::ROL_INDEPENDIENTE && $tecnico->approval_status === 'approved')
            || (self::esRolMiembro($rol) && self::estaSinCuadrilla($tecnico));
        if (!$puede) {
            return 'Tu cuenta no puede pedir unirse a una cuadrilla.';
        }

        $tenant = self::cuadrillaPorCodigo($codigo);
        if (!$tenant) {
            return 'Ese código no es de ningún contratista. Pídeselo a tu contratista.';
        }
        if ((int) $tenant->id === (int) $tecnico->tenant_id && !self::estaSinCuadrilla($tecnico)) {
            return 'Ya estás en esta cuadrilla.';
        }

        if ($rol === self::ROL_INDEPENDIENTE && ($enCurso = self::trabajosPropiosEnCurso($tecnico)) > 0) {
            return "Tienes {$enCurso} " . ($enCurso === 1 ? 'trabajo en curso' : 'trabajos en curso')
                . '. Termínalos o cancélalos antes de unirte a una cuadrilla.';
        }

        // Una sola solicitud viva a la vez: la nueva reemplaza a la anterior
        self::cancelarSolicitudes((int) $tecnico->id);
        $id = DB::table('solicitudes_cuadrilla')->insertGetId([
            'user_id' => $tecnico->id, 'tenant_id' => $tenant->id, 'estado' => 'pendiente',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $nombre = trim("{$tecnico->first_name} {$tecnico->last_name}");
        self::avisar((int) $tenant->owner_user_id, '👷 Quieren unirse a tu cuadrilla', "{$nombre} pidió unirse a tu cuadrilla. Revísalo en la Sala de espera.", '/sala-espera-tecnicos', 'cuadrilla_solicitud', 0);

        return DB::table('solicitudes_cuadrilla')->find($id);
    }

    /** El contratista acepta: entra como técnico de cuadrilla (8). Conserva su perfil y sus calificaciones. */
    public static function aceptarSolicitud(object $solicitud): void
    {
        $tecnico = User::withoutGlobalScopes()->find($solicitud->user_id);
        if (!$tecnico) {
            return;
        }

        DB::transaction(function () use ($solicitud, $tecnico) {
            DB::table('users')->where('id', $tecnico->id)->update([
                'role_id' => self::ROL_CUADRILLA,
                'tenant_id' => $solicitud->tenant_id,
                'approval_status' => 'approved',
                'is_active' => 1,
                'updated_at' => now(),
            ]);
            DB::table('solicitudes_cuadrilla')->where('id', $solicitud->id)->update([
                'estado' => 'aceptada', 'respondida_at' => now(), 'updated_at' => now(),
            ]);
            // Ya no cotiza por su cuenta: retira las cotizaciones que tenía esperando respuesta
            if (Schema::hasColumn('network_quotes', 'retirada_at')) {
                DB::table('network_quotes')->where('technician_id', $tecnico->id)
                    ->whereNotIn('status', ['accepted', 'rejected'])->whereNull('retirada_at')
                    ->update(['retirada_at' => now(), 'updated_at' => now()]);
            }
        });
        $tecnico->tokens()->delete();

        self::avisar((int) $tecnico->id, '✅ Ya eres parte de una cuadrilla', 'Tu contratista aceptó tu solicitud. Vuelve a iniciar sesión para ver los trabajos que te asigne.', '/', 'cuadrilla_aceptado', 0);
    }

    /** El contratista rechaza: la cuenta queda como estaba. */
    public static function rechazarSolicitud(object $solicitud): void
    {
        DB::table('solicitudes_cuadrilla')->where('id', $solicitud->id)->update([
            'estado' => 'rechazada', 'respondida_at' => now(), 'updated_at' => now(),
        ]);
        self::avisar((int) $solicitud->user_id, 'Solicitud de cuadrilla', 'El contratista no aceptó tu solicitud para unirte a su cuadrilla.', '/', 'cuadrilla_rechazado', 0);
    }

    public static function cancelarSolicitudes(int $userId): void
    {
        if (self::haySolicitudes()) {
            DB::table('solicitudes_cuadrilla')->where('user_id', $userId)->where('estado', 'pendiente')
                ->update(['estado' => 'cancelada', 'updated_at' => now()]);
        }
    }

    // ─────────────────────────────── Avisos ───────────────────────────────

    public static function nombreTrabajo(object $orden): string
    {
        return trim(($orden->type ?? 'Trabajo') . (!empty($orden->equipment) ? ' - ' . $orden->equipment : ''));
    }

    /** Aviso en la campanita (el push sale solo con PushForDatabaseNotifications). */
    public static function avisar(int $userId, string $titulo, string $mensaje, string $url, string $tipo, int $workOrderId): void
    {
        try {
            $destino = $userId ? User::withoutGlobalScopes()->find($userId) : null;
            $destino?->notify(new MovimientoNotificacion([
                'titulo' => $titulo,
                'mensaje' => $mensaje,
                'url' => $url,
                'alert_type' => $tipo,
                'extra' => $workOrderId ? ['work_order_id' => $workOrderId] : [],
            ]));
        } catch (\Throwable $e) {
            Log::warning("Cuadrilla: no se pudo avisar al usuario {$userId}: " . $e->getMessage());
        }
    }

    /** Avisa a los técnicos de cuadrilla que van a esta orden (no al titular ni a los de $excluir). */
    public static function avisarEquipo(object $orden, string $titulo, string $mensaje, array $excluir = []): void
    {
        $titular = self::titularDe($orden);
        $excluir = array_map('intval', array_merge($excluir, $titular ? [$titular] : []));
        foreach (self::equipoDeOrden((int) $orden->id) as $id) {
            if (in_array($id, $excluir, true)) continue;
            self::avisar($id, $titulo, $mensaje, '/mercado-trabajos', 'cuadrilla_trabajo', (int) $orden->id);
        }
    }

    /** Avisa a Root (cambios de rol que hace a mano mientras los cobros están en pausa). */
    public static function avisarRoot(string $titulo, string $mensaje, string $tipo): void
    {
        foreach (User::withoutGlobalScopes()->where('role_id', 0)->pluck('id') as $rootId) {
            self::avisar((int) $rootId, $titulo, $mensaje, '/usuarios', $tipo, 0);
        }
    }
}
