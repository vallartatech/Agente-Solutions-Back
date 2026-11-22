<?php

namespace App\Services;

/**
 * Roles de Agente Market que trabajan como técnicos: reciben trabajos, chatean del lado del técnico
 * y llenan el perfil profesional.
 *
 * - 6 Técnico independiente: trabaja por su cuenta en la red.
 * - 7 Contratista / Líder: técnico con una cuadrilla a su cargo (ver App\Services\Cuadrilla).
 * - 8 Técnico de cuadrilla: solo recibe lo que le asigna su contratista; no ve la red.
 * - 9 Técnico de cuadrilla con acceso a la red: como el 8, y además cotiza por su cuenta (paga un extra).
 */
final class RolesRed
{
    public const TECNICOS = [6, 7, 8, 9];

    /** Ven el Mercado abierto, cotizan, reciben avisos de trabajos nuevos y tienen QR para que los contraten. */
    public const QUE_COTIZAN = [6, 7, 9];

    /** Los técnicos de la red más el Técnico Agente interno (2), para lo que comparten los dos entornos. */
    public const TODOS_LOS_TECNICOS = [2, 6, 7, 8, 9];

    /** Técnicos con clientes propios ("Mis clientes"): todos menos el de cuadrilla, cuyos clientes son de su contratista. */
    public const TECNICOS_CON_CLIENTES = [2, 6, 7, 9];

    /** Sin datos de cobro en su perfil: su contratista les paga por fuera de la app. */
    public const SIN_COBRO = [8];

    public static function esTecnico($roleId): bool
    {
        return in_array((int) $roleId, self::TECNICOS, true);
    }

    public static function esCualquierTecnico($roleId): bool
    {
        return in_array((int) $roleId, self::TODOS_LOS_TECNICOS, true);
    }

    public static function cotiza($roleId): bool
    {
        return in_array((int) $roleId, self::QUE_COTIZAN, true);
    }

    public static function sinCobro($roleId): bool
    {
        return in_array((int) $roleId, self::SIN_COBRO, true);
    }
}
