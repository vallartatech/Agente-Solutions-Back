<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuadrilla de un contratista (rol 7) en Agente Market.
 *
 * - Sus técnicos (rol 8) viven en la empresa (tenant) del contratista.
 * - El contratista solo da de alta, aprueba, rechaza o quita técnicos de su cuadrilla; no cambia roles.
 * - Cupo por contratista: tenants.max_cuadrilla (7 al empezar; Root lo amplía).
 * - Quitar a un técnico no borra nada: se desactiva y queda "sin cuadrilla", ligado a la misma
 *   empresa para que sus trabajos anteriores sigan mostrando su nombre.
 */
class Cuadrilla
{
    public const ROL_CONTRATISTA = 7;
    /** Roles que forman parte de una cuadrilla (el rol 9 se suma cuando exista). */
    public const ROLES_MIEMBRO = [8];
    public const LIMITE_INICIAL = 7;

    /** Técnico que su contratista quitó: sin acceso hasta unirse a otra cuadrilla o volverse independiente. */
    public const SIN_CUADRILLA = 'sin_cuadrilla';
    /** Solicitud de unirse que el contratista rechazó. */
    public const RECHAZADO = 'rechazado_cuadrilla';

    /** Estados de una orden en curso: no se puede quitar al técnico mientras tenga alguna. */
    private const ESTADOS_EN_CURSO = ['asignado', 'en progreso', 'en proceso', 'en_sitio', 'por aprobar'];

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

    /** Órdenes en curso donde el técnico es el titular o parte del equipo. */
    public static function trabajosEnCurso(User $tecnico): int
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
            ->count();
    }

    /** Lo saca de la cuadrilla sin borrar nada: desactivado, sin sesiones abiertas y con su historial intacto. */
    public static function quitar(User $tecnico): void
    {
        DB::table('users')->where('id', $tecnico->id)->update([
            'is_active' => 0,
            'approval_status' => self::SIN_CUADRILLA,
            'updated_at' => now(),
        ]);
        $tecnico->tokens()->delete();
    }

    /** Rechaza una solicitud pendiente. Nunca trabajó con el contratista, así que se desliga de su empresa. */
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
}
