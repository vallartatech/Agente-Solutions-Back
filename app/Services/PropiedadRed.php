<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Propiedades de la Red (AgenteMarket) vistas desde la app interna.
 *
 * Una casa de la Red puede pedir un levantamiento: lo recibe el Root (rol 0), que asigna a un
 * técnico interno. El técnico asignado captura el levantamiento; el Root solo lo consulta.
 */
class PropiedadRed
{
    /** Roles de la Red (mismo listado que ROLES_RED en el frontend). */
    public const ROLES_RED = [4, 5, 6, 7, 8];

    /**
     * La propiedad es de la Red si el dueño de su empresa (tenant) o el usuario de su cliente
     * tiene un rol de la Red. Consultas sin scopes de tenant para que el Root y el técnico
     * interno obtengan la misma respuesta.
     */
    public static function esRed($propertyId): bool
    {
        if (!$propertyId) {
            return false;
        }

        $propiedad = DB::table('properties')
            ->where('id', $propertyId)
            ->select('tenant_id', 'client_id')
            ->first();
        if (!$propiedad) {
            return false;
        }

        if ($propiedad->tenant_id) {
            $rolDueno = DB::table('tenants')
                ->join('users', 'users.id', '=', 'tenants.owner_user_id')
                ->where('tenants.id', $propiedad->tenant_id)
                ->value('users.role_id');
            if ($rolDueno !== null && in_array((int) $rolDueno, self::ROLES_RED, true)) {
                return true;
            }
        }

        if ($propiedad->client_id) {
            $rolCliente = DB::table('clients')
                ->join('users', 'users.id', '=', 'clients.user_id')
                ->where('clients.id', $propiedad->client_id)
                ->value('users.role_id');
            if ($rolCliente !== null && in_array((int) $rolCliente, self::ROLES_RED, true)) {
                return true;
            }
        }

        return false;
    }

    /** El usuario está asignado (titular o en el equipo) a algún servicio de la propiedad. */
    public static function tecnicoAsignado($user, $propertyId): bool
    {
        if (!$user || !$propertyId) {
            return false;
        }

        return DB::table('services')
            ->leftJoin('service_technician', 'services.id', '=', 'service_technician.service_id')
            ->where('services.property_id', $propertyId)
            ->where(function ($q) use ($user) {
                $q->where('services.assigned_to', $user->id)
                    ->orWhere('service_technician.technician_id', $user->id);
            })
            ->exists();
    }

    /** El Root solo consulta los levantamientos de las casas de la Red. */
    public static function soloLecturaPara($user, $propertyId): bool
    {
        return $user && (int) $user->role_id === 0 && self::esRed($propertyId);
    }

    /** Propiedad a la que pertenece una zona/área. */
    public static function propiedadDeArea($areaId)
    {
        return $areaId ? DB::table('property_areas')->where('id', $areaId)->value('property_id') : null;
    }
}
