<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Favorito o bloqueo de un técnico, por cliente. Sin TenantScope: el técnico debe poder
// consultar sus bloqueos aunque pertenezca a otro tenant.
class ClientTechnicianRelation extends Model
{
    public const FAVORITO = 'favorito';
    public const BLOQUEADO = 'bloqueado';

    protected $table = 'client_technician_relations';

    protected $fillable = ['client_user_id', 'technician_id', 'tenant_id', 'status'];
}
