<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Favorito o bloqueo entre un cliente y un técnico. set_by dice quién lo marcó:
// el cliente ("Mis técnicos") o el técnico ("Mis clientes"). Un bloqueo de cualquiera de los
// dos corta la relación en la red. Sin TenantScope: cada lado consulta lo del otro aunque
// pertenezcan a tenants distintos.
class ClientTechnicianRelation extends Model
{
    public const FAVORITO = 'favorito';
    public const BLOQUEADO = 'bloqueado';

    public const POR_CLIENTE = 'cliente';
    public const POR_TECNICO = 'tecnico';

    protected $table = 'client_technician_relations';

    protected $fillable = ['client_user_id', 'technician_id', 'tenant_id', 'status', 'set_by'];

    // El tenant 1 (o nulo) es Agente Solutions: bloquear por ese tenant cortaría a todo el equipo interno
    public static function tenantDeBloqueo($tenantId): ?int
    {
        return ($tenantId && (int) $tenantId !== 1) ? (int) $tenantId : null;
    }
}
