<?php

namespace App\Http\Controllers;

use App\Models\ReportFooter;
use Illuminate\Http\Request;

// Pies de página que se pueden poner en el reporte oficial de trabajo
class ReportFooterController extends Controller
{
    // Root (rol 0) ve todos; los demás solo los de su tenant (sin tenant = Agente Solutions)
    private function piesVisibles($user)
    {
        $query = ReportFooter::query();

        if ((int) $user->role_id !== 0) {
            $tenantId = $user->tenant_id ?? 1;
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
                if ((int) $tenantId === 1) {
                    $q->orWhereNull('tenant_id');
                }
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->piesVisibles($request->user())->orderBy('empresa')->get(['id', 'empresa', 'telefono', 'email', 'leyenda']),
            200
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if ((int) $user->role_id === 3) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        try {
            $datos = $request->validate([
                'empresa' => 'required|string|max:255',
                'telefono' => 'nullable|string|max:255',
                'email' => 'nullable|string|max:255',
                'leyenda' => 'nullable|string|max:500',
            ], ['empresa.required' => 'Escribe el nombre de la empresa.']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => collect($e->errors())->flatten()->first()], 422);
        }

        $pie = ReportFooter::create($datos + [
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
        ]);

        return response()->json($pie->only(['id', 'empresa', 'telefono', 'email', 'leyenda']), 201);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array((int) $user->role_id, [0, 1])) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $pie = $this->piesVisibles($user)->find($id);
        if (!$pie) {
            return response()->json(['error' => 'Pie de página no encontrado'], 404);
        }

        // Los reportes ya guardados conservan su copia del texto; solo se quita de la lista
        $pie->delete();
        return response()->json(['success' => true], 200);
    }
}
