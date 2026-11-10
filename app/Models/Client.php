<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantScoped;

class Client extends Model
{
    use HasFactory, TenantScoped;

    protected $fillable = [
    'tenant_id',
    'name',
    'email',
    'phone',
    'address',
    'profile_picture'
];

    /**
     * Un cliente NUNCA se borra de la tabla: la cascada se llevaría sus propiedades,
     * órdenes de trabajo y pagos. Para eliminarlo usa App\Services\CuentaEliminacion.
     */
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \LogicException('Los clientes no se borran: usa CuentaEliminacion::anonimizarCliente().');
        });
    }
}