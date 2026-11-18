<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Solo aplicar si hay un usuario autenticado en la petición (API o Web)
        if (auth()->check()) {
            $user = auth()->user();
            
            // Rol 0 es Root (Pedro): No se aplica filtro, tiene visión global de todo el sistema.
            // Si el usuario es de cualquier otro rol (1 Admin, 2 Técnico, 3 Cliente, 4 Autónomo)
            // y tiene un tenant_id asignado, filtramos estrictamente por ese tenant_id.
            if ($user && $user->role_id !== 0 && !is_null($user->tenant_id)) {
                $table = $model->getTable();

                // Propiedades compartidas con el usuario (pueden ser de otro tenant): las usa como dueño,
                // así que también ve la propiedad, sus órdenes, servicios, cotizaciones y al cliente dueño.
                $compartidas = \App\Services\AccesoPropiedad::compartidasCon($user);
                if (empty($compartidas)) {
                    $builder->where($table . '.tenant_id', $user->tenant_id);
                    return;
                }

                $builder->where(function ($q) use ($table, $user, $compartidas) {
                    $q->where($table . '.tenant_id', $user->tenant_id);
                    switch ($table) {
                        case 'properties':
                            $q->orWhereIn($table . '.id', $compartidas);
                            break;
                        case 'work_orders':
                        case 'services':
                            $q->orWhereIn($table . '.property_id', $compartidas);
                            break;
                        case 'quotes':
                            $q->orWhereIn($table . '.service_id', \Illuminate\Support\Facades\DB::table('services')->whereIn('property_id', $compartidas)->select('id'))
                              ->orWhereIn($table . '.work_order_id', \Illuminate\Support\Facades\DB::table('work_orders')->whereIn('property_id', $compartidas)->select('id'));
                            break;
                        case 'clients':
                            $q->orWhereIn($table . '.id', \Illuminate\Support\Facades\DB::table('properties')->whereIn('id', $compartidas)->select('client_id'));
                            break;
                    }
                });
            }
        }
    }
}
