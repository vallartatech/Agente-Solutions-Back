<?php

namespace App\Http\Middleware;

use App\Services\PropiedadRed;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * El Root consulta, pero no modifica, el levantamiento de una casa de la Red:
 * bloquea zonas, áreas, categorías, componentes y el cierre del levantamiento.
 * Uso: ->middleware('solo-lectura-red')
 */
class SoloLecturaRootRed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (int) $user->role_id === 0) {
            $propertyId = $this->propiedadDeLaPeticion($request);
            if ($propertyId && PropiedadRed::esRed($propertyId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta propiedad es de la Red: puedes consultar su levantamiento, pero no modificarlo.',
                ], 403);
            }
        }

        return $next($request);
    }

    private function propiedadDeLaPeticion(Request $request)
    {
        $id = $request->route('id');

        // /properties/{id}/...
        if ($request->is('api/properties/*')) {
            return $id;
        }

        // /property-areas/{id}
        if ($id && $request->is('api/property-areas/*')) {
            return PropiedadRed::propiedadDeArea($id);
        }

        // /property-categories/{id}
        if ($id && $request->is('api/property-categories/*')) {
            $areaId = DB::table('property_maintenance_categories')->where('id', $id)->value('property_area_id');
            return PropiedadRed::propiedadDeArea($areaId);
        }

        // /property-components/{id}
        if ($id && $request->is('api/property-components/*')) {
            $areaId = DB::table('property_components')->where('id', $id)->value('property_area_id');
            return PropiedadRed::propiedadDeArea($areaId);
        }

        // Altas: la propiedad o la zona vienen en el cuerpo
        if ($request->filled('property_id')) {
            return $request->input('property_id');
        }
        if ($request->filled('property_area_id')) {
            return PropiedadRed::propiedadDeArea($request->input('property_area_id'));
        }
        if ($request->filled('parent_id')) {
            return PropiedadRed::propiedadDeArea($request->input('parent_id'));
        }

        return null;
    }
}
