<?php

namespace App\Http\Controllers;

use App\Models\Specialty;
use App\Models\TechnicianCourse;
use App\Models\TechnicianExperience;
use App\Models\TechnicianProfile;
use App\Models\User;
use App\Services\PerfilProfesional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Perfil profesional de los técnicos de la red (roles 6 y 8).
 *
 * Técnico:   ver/guardar su perfil, subir INE y cursos, enviarlo a revisión.
 * Clientes:  perfil público por niveles (RFC y CLABE solo si ya lo contrató).
 * Admin/Root: lista de perfiles por validar, aprobar o rechazar con motivo.
 */
class PerfilProfesionalController extends Controller
{
    private const ARCHIVO_REGLA = 'file|mimes:jpg,jpeg,png,webp,heic,heif,pdf|max:8192';
    private const DISCO = 'local'; // storage/app/private: sin URL pública

    // ─────────────────────────── Técnico ───────────────────────────

    public function mio(Request $request)
    {
        $user = $request->user();
        if (!PerfilProfesional::esTecnicoRed($user)) {
            return response()->json(['message' => 'Solo para técnicos de la red.'], 403);
        }
        $perfil = PerfilProfesional::perfilOCrear($user);
        return response()->json(['success' => true, 'data' => $this->serializar($perfil, $user, 'propio')]);
    }

    public function guardar(Request $request)
    {
        $user = $request->user();
        if (!PerfilProfesional::esTecnicoRed($user)) {
            return response()->json(['message' => 'Solo para técnicos de la red.'], 403);
        }

        // Normalizar antes de validar: RFC en mayúsculas, CLABE solo dígitos
        if ($request->has('rfc')) {
            $request->merge(['rfc' => $request->rfc !== null ? strtoupper(preg_replace('/\s+/', '', (string) $request->rfc)) : null]);
        }
        if ($request->has('clabe')) {
            $request->merge(['clabe' => $request->clabe !== null ? preg_replace('/\D/', '', (string) $request->clabe) : null]);
        }

        $datos = $request->validate([
            'emite_factura' => 'sometimes|nullable|boolean',
            'rfc' => ['sometimes', 'nullable', 'string', function ($attr, $valor, $fail) {
                if ($valor && !PerfilProfesional::rfcValido($valor)) $fail('El RFC no tiene un formato válido.');
            }],
            'razon_social' => 'sometimes|nullable|string|max:191',
            'banco' => 'sometimes|nullable|string|max:100',
            'titular_cuenta' => 'sometimes|nullable|string|max:191',
            'clabe' => ['sometimes', 'nullable', 'string', function ($attr, $valor, $fail) {
                if ($valor && !PerfilProfesional::clabeValida($valor)) $fail('La CLABE no es válida: revisa los 18 dígitos.');
            }],
            'acepta_efectivo' => 'sometimes|nullable|boolean',
            'tiene_terminal' => 'sometimes|nullable|boolean',
            'anios_experiencia' => 'sometimes|nullable|integer|min:0|max:70',
            'nivel_estudios' => ['sometimes', 'nullable', Rule::in(array_keys(PerfilProfesional::NIVELES_ESTUDIOS))],
            'presentacion' => 'sometimes|nullable|string|max:1500',
            'sin_cursos' => 'sometimes|boolean',
            'sin_empleos' => 'sometimes|boolean',
            'especialidades' => 'sometimes|array|max:20',
            'especialidades.*' => 'required',
            'experiencias' => 'sometimes|array|max:10',
            'experiencias.*.empresa' => 'required|string|max:191',
            'experiencias.*.puesto' => 'nullable|string|max:191',
            'experiencias.*.desde_anio' => 'nullable|integer|min:1950|max:' . date('Y'),
            'experiencias.*.hasta_anio' => 'nullable|integer|min:1950|max:' . date('Y'),
            'experiencias.*.descripcion' => 'nullable|string|max:500',
        ]);

        $perfil = PerfilProfesional::perfilOCrear($user);

        DB::transaction(function () use ($datos, $perfil, $user) {
            $campos = collect($datos)->except(['especialidades', 'experiencias'])->all();
            $cambioEstudios = array_key_exists('nivel_estudios', $campos) && $campos['nivel_estudios'] !== $perfil->nivel_estudios;

            // Sin factura no se guardan datos fiscales
            if (array_key_exists('emite_factura', $campos) && $campos['emite_factura'] === false) {
                $campos['rfc'] = null;
                $campos['razon_social'] = null;
            }
            $perfil->fill($campos)->save();

            if (array_key_exists('especialidades', $datos)) {
                $user->specialties()->sync($this->idsEspecialidades($datos['especialidades']));
            }

            if (array_key_exists('experiencias', $datos)) {
                TechnicianExperience::where('user_id', $user->id)->delete();
                foreach ($datos['experiencias'] as $e) {
                    TechnicianExperience::create([
                        'user_id' => $user->id,
                        'empresa' => trim($e['empresa']),
                        'puesto' => $e['puesto'] ?? null,
                        'desde_anio' => $e['desde_anio'] ?? null,
                        'hasta_anio' => $e['hasta_anio'] ?? null,
                        'descripcion' => $e['descripcion'] ?? null,
                    ]);
                }
            }

            if ($cambioEstudios) {
                PerfilProfesional::marcarCambio($perfil, 'estudios');
            }
        });

        return response()->json(['success' => true, 'data' => $this->serializar($perfil->fresh(), $user, 'propio')]);
    }

    public function subirIne(Request $request)
    {
        $user = $request->user();
        if (!PerfilProfesional::esTecnicoRed($user)) {
            return response()->json(['message' => 'Solo para técnicos de la red.'], 403);
        }
        $request->validate([
            'frente' => 'required_without:reverso|' . self::ARCHIVO_REGLA,
            'reverso' => 'required_without:frente|' . self::ARCHIVO_REGLA,
        ], [
            'required_without' => 'Sube la foto del frente o del reverso de tu INE.',
            'mimes' => 'La INE debe ser una foto (JPG, PNG, WEBP, HEIC) o un PDF.',
            'max' => 'El archivo pesa más de 8 MB.',
        ]);

        $perfil = PerfilProfesional::perfilOCrear($user);
        foreach (['frente' => 'ine_frente_path', 'reverso' => 'ine_reverso_path'] as $campo => $columna) {
            if (!$request->hasFile($campo)) continue;
            $anterior = $perfil->{$columna};
            $perfil->{$columna} = $request->file($campo)->store("kyc/{$user->id}", self::DISCO);
            if ($anterior) Storage::disk(self::DISCO)->delete($anterior);
        }
        PerfilProfesional::marcarCambio($perfil, 'identidad');

        return response()->json(['success' => true, 'data' => $this->serializar($perfil->fresh(), $user, 'propio')]);
    }

    public function agregarCurso(Request $request)
    {
        $user = $request->user();
        if (!PerfilProfesional::esTecnicoRed($user)) {
            return response()->json(['message' => 'Solo para técnicos de la red.'], 403);
        }
        $datos = $request->validate([
            'nombre' => 'required|string|max:191',
            'institucion' => 'nullable|string|max:191',
            'anio' => 'nullable|integer|min:1950|max:' . date('Y'),
            'archivo' => 'nullable|' . self::ARCHIVO_REGLA,
        ]);
        if (TechnicianCourse::where('user_id', $user->id)->count() >= 20) {
            return response()->json(['message' => 'Puedes registrar hasta 20 cursos.'], 422);
        }

        $curso = TechnicianCourse::create([
            'user_id' => $user->id,
            'nombre' => trim($datos['nombre']),
            'institucion' => $datos['institucion'] ?? null,
            'anio' => $datos['anio'] ?? null,
            'archivo_path' => $request->hasFile('archivo') ? $request->file('archivo')->store("kyc/{$user->id}/cursos", self::DISCO) : null,
        ]);

        $perfil = PerfilProfesional::perfilOCrear($user);
        $perfil->sin_cursos = false;
        PerfilProfesional::marcarCambio($perfil, 'estudios');

        return response()->json(['success' => true, 'curso' => $curso, 'data' => $this->serializar($perfil->fresh(), $user, 'propio')], 201);
    }

    public function borrarCurso(Request $request, $id)
    {
        $user = $request->user();
        $curso = TechnicianCourse::where('user_id', $user->id)->findOrFail($id);
        if ($curso->archivo_path) Storage::disk(self::DISCO)->delete($curso->archivo_path);
        $curso->delete();

        $perfil = PerfilProfesional::perfilOCrear($user);
        PerfilProfesional::marcarCambio($perfil, 'estudios');

        return response()->json(['success' => true, 'data' => $this->serializar($perfil->fresh(), $user, 'propio')]);
    }

    public function enviar(Request $request)
    {
        $user = $request->user();
        if (!PerfilProfesional::esTecnicoRed($user)) {
            return response()->json(['message' => 'Solo para técnicos de la red.'], 403);
        }
        $perfil = PerfilProfesional::perfilOCrear($user);

        $faltantes = PerfilProfesional::faltantes($perfil, $user);
        if ($faltantes) {
            return response()->json([
                'message' => 'Te falta completar: ' . implode(', ', $faltantes) . '.',
                'faltantes' => $faltantes,
            ], 422);
        }
        if ($perfil->estado === 'en_revision') {
            return response()->json(['success' => true, 'message' => 'Tu perfil ya está en revisión.', 'data' => $this->serializar($perfil, $user, 'propio')]);
        }

        $perfil->update(['estado' => 'en_revision', 'enviado_at' => now(), 'motivo_rechazo' => null]);
        PerfilProfesional::avisarRevisores($user);

        return response()->json([
            'success' => true,
            'message' => 'Enviamos tu perfil a revisión. Te avisaremos cuando esté validado.',
            'data' => $this->serializar($perfil->fresh(), $user, 'propio'),
        ]);
    }

    /** INE o diploma: solo el propio técnico y Admin/Root. */
    public function archivo(Request $request, $userId, $tipo, $cursoId = null)
    {
        $viewer = $request->user();
        $userId = (int) str_replace('u_', '', $userId);
        if ((int) $viewer->id !== $userId && !PerfilProfesional::esRevisor($viewer)) {
            return response()->json(['message' => 'No tienes permiso para ver este archivo.'], 403);
        }

        $ruta = null;
        if ($tipo === 'ine_frente' || $tipo === 'ine_reverso') {
            $ruta = TechnicianProfile::where('user_id', $userId)->value("{$tipo}_path");
        } elseif ($tipo === 'curso' && $cursoId) {
            $ruta = TechnicianCourse::where('user_id', $userId)->where('id', $cursoId)->value('archivo_path');
        }
        if (!$ruta || !Storage::disk(self::DISCO)->exists($ruta)) {
            return response()->json(['message' => 'Archivo no encontrado.'], 404);
        }

        return Storage::disk(self::DISCO)->response($ruta, null, ['Cache-Control' => 'private, no-store']);
    }

    // ─────────────────────────── Clientes ───────────────────────────

    public function publico(Request $request, $userId)
    {
        $viewer = $request->user();
        $userId = (int) str_replace('u_', '', $userId);
        $tecnico = User::withoutGlobalScopes()->find($userId);
        if (!$tecnico || !PerfilProfesional::esTecnicoRed($tecnico)) {
            return response()->json(['message' => 'Técnico no encontrado.'], 404);
        }
        $perfil = PerfilProfesional::perfilDe($tecnico) ?? new TechnicianProfile(['user_id' => $userId, 'estado' => 'incompleto']);

        $nivel = 'publico';
        if ((int) $viewer->id === $userId) $nivel = 'propio';
        elseif (PerfilProfesional::esRevisor($viewer)) $nivel = 'revisor';
        elseif (PerfilProfesional::loContrato($viewer, $userId)) $nivel = 'contratante';

        return response()->json(['success' => true, 'nivel' => $nivel, 'data' => $this->serializar($perfil, $tecnico, $nivel)]);
    }

    // ─────────────────────────── Admin / Root ───────────────────────────

    public function porValidar(Request $request)
    {
        $estado = $request->query('estado', 'en_revision');
        $query = TechnicianProfile::with('user')
            ->whereHas('user', fn ($q) => $q->withoutGlobalScopes()->whereIn('role_id', PerfilProfesional::ROLES_RED));
        if ($estado !== 'todos') {
            $query->where('estado', $estado);
        }

        $lista = $query->orderBy('enviado_at')->get()->map(fn ($p) => [
            'user_id' => $p->user_id,
            'nombre' => trim(($p->user->first_name ?? '') . ' ' . ($p->user->last_name ?? '')),
            'email' => $p->user->email ?? null,
            'telefono' => $p->user->phone_number ?? null,
            'foto' => $p->user->profile_picture ?? null,
            'role_id' => $p->user->role_id ?? null,
            'estado' => $p->estado,
            'obligatorio' => $p->obligatorio,
            'enviado_at' => $p->enviado_at,
            'ya_aprobado_antes' => (bool) $p->aprobado_at,
        ]);

        return response()->json(['success' => true, 'data' => $lista]);
    }

    public function detalleAdmin($userId)
    {
        $userId = (int) str_replace('u_', '', $userId);
        $tecnico = User::withoutGlobalScopes()->findOrFail($userId);
        $perfil = PerfilProfesional::perfilOCrear($tecnico);
        return response()->json(['success' => true, 'data' => $this->serializar($perfil, $tecnico, 'revisor')]);
    }

    public function aprobar(Request $request, $userId)
    {
        $datos = $request->validate(['estudios_verificados' => 'sometimes|boolean']);
        $userId = (int) str_replace('u_', '', $userId);
        $tecnico = User::withoutGlobalScopes()->findOrFail($userId);
        $perfil = PerfilProfesional::perfilOCrear($tecnico);

        if ($perfil->estado !== 'en_revision') {
            return response()->json(['message' => 'Este perfil no está en revisión.'], 422);
        }

        $ahora = now();
        $perfil->update([
            'estado' => 'aprobado',
            'motivo_rechazo' => null,
            'aprobado_at' => $perfil->aprobado_at ?? $ahora,
            'revisado_por' => $request->user()->id,
            'revisado_at' => $ahora,
            'identidad_verificada_at' => $ahora,
            'estudios_verificados_at' => ($datos['estudios_verificados'] ?? true) ? $ahora : null,
        ]);
        PerfilProfesional::avisarTecnico($tecnico, true);

        return response()->json(['success' => true, 'message' => 'Perfil aprobado.', 'data' => $this->serializar($perfil->fresh(), $tecnico, 'revisor')]);
    }

    public function rechazar(Request $request, $userId)
    {
        $datos = $request->validate(['motivo' => 'required|string|min:5|max:1000'], [
            'motivo.required' => 'Escribe qué debe corregir el técnico.',
            'motivo.min' => 'Escribe qué debe corregir el técnico.',
        ]);
        $userId = (int) str_replace('u_', '', $userId);
        $tecnico = User::withoutGlobalScopes()->findOrFail($userId);
        $perfil = PerfilProfesional::perfilOCrear($tecnico);

        if ($perfil->estado !== 'en_revision') {
            return response()->json(['message' => 'Este perfil no está en revisión.'], 422);
        }

        $perfil->update([
            'estado' => 'rechazado',
            'motivo_rechazo' => trim($datos['motivo']),
            'revisado_por' => $request->user()->id,
            'revisado_at' => now(),
        ]);
        PerfilProfesional::avisarTecnico($tecnico, false, $perfil->motivo_rechazo);

        return response()->json(['success' => true, 'message' => 'Se le pidió al técnico que corrija su perfil.', 'data' => $this->serializar($perfil->fresh(), $tecnico, 'revisor')]);
    }

    // ─────────────────────────── Ayudas ───────────────────────────

    /** Ids del catálogo; un nombre nuevo se agrega al catálogo para todos los técnicos. */
    private function idsEspecialidades(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (is_numeric($item)) {
                if (Specialty::whereKey((int) $item)->exists()) $ids[] = (int) $item;
                continue;
            }
            $nombre = trim(preg_replace('/\s+/', ' ', (string) $item));
            if ($nombre === '' || mb_strlen($nombre) > 60) continue;
            $existente = Specialty::whereRaw('LOWER(name) = ?', [mb_strtolower($nombre)])->first();
            $ids[] = ($existente ?? Specialty::create(['name' => $nombre, 'icon' => '🔧', 'category' => 'Agregada por técnicos']))->id;
        }
        return array_values(array_unique($ids));
    }

    /**
     * Datos del perfil según quién mira:
     *  publico     → habilidades, experiencia, currículum, si factura y cómo cobra, insignias
     *  contratante → + RFC, razón social, banco, titular y CLABE (para pagarle)
     *  propio      → + estado de revisión, qué falta, si tiene INE y diplomas
     *  revisor     → todo lo anterior + datos de contacto
     */
    private function serializar(TechnicianProfile $perfil, User $tecnico, string $nivel): array
    {
        $cursos = TechnicianCourse::where('user_id', $tecnico->id)->orderByDesc('anio')->get();
        $experiencias = TechnicianExperience::where('user_id', $tecnico->id)->orderByDesc('desde_anio')->get();

        $data = [
            'user_id' => $tecnico->id,
            'nombre' => trim("{$tecnico->first_name} {$tecnico->last_name}"),
            'insignias' => [
                'identidad_verificada' => (bool) $perfil->identidad_verificada_at,
                'estudios_verificados' => (bool) $perfil->estudios_verificados_at,
            ],
            'emite_factura' => $perfil->emite_factura,
            'acepta_efectivo' => $perfil->acepta_efectivo,
            'tiene_terminal' => $perfil->tiene_terminal,
            'acepta_transferencia' => PerfilProfesional::clabeValida($perfil->clabe),
            'anios_experiencia' => $perfil->anios_experiencia,
            'nivel_estudios' => $perfil->nivel_estudios,
            'nivel_estudios_texto' => PerfilProfesional::NIVELES_ESTUDIOS[$perfil->nivel_estudios] ?? null,
            'presentacion' => $perfil->presentacion,
            'especialidades' => $tecnico->specialties()->get(['specialties.id', 'name', 'icon'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'icon' => $s->icon])->values(),
            'cursos' => $cursos->map(fn ($c) => [
                'id' => $c->id, 'nombre' => $c->nombre, 'institucion' => $c->institucion, 'anio' => $c->anio,
                'tiene_archivo' => in_array($nivel, ['propio', 'revisor'], true) ? $c->tiene_archivo : null,
            ])->values(),
            'experiencias' => $experiencias->map->only(['id', 'empresa', 'puesto', 'desde_anio', 'hasta_anio', 'descripcion'])->values(),
        ];

        if (in_array($nivel, ['contratante', 'propio', 'revisor'], true)) {
            $data += [
                'rfc' => $perfil->rfc,
                'razon_social' => $perfil->razon_social,
                'banco' => $perfil->banco,
                'titular_cuenta' => $perfil->titular_cuenta,
                'clabe' => $perfil->clabe,
            ];
        }

        if (in_array($nivel, ['propio', 'revisor'], true)) {
            $data += [
                'estado' => $perfil->estado,
                'obligatorio' => (bool) $perfil->obligatorio,
                'bloqueado' => PerfilProfesional::estaBloqueado($tecnico),
                'motivo_rechazo' => $perfil->motivo_rechazo,
                'enviado_at' => $perfil->enviado_at,
                'revisado_at' => $perfil->revisado_at,
                'sin_cursos' => (bool) $perfil->sin_cursos,
                'sin_empleos' => (bool) $perfil->sin_empleos,
                'tiene_ine_frente' => (bool) $perfil->ine_frente_path,
                'tiene_ine_reverso' => (bool) $perfil->ine_reverso_path,
                'faltantes' => PerfilProfesional::faltantes($perfil, $tecnico),
                'niveles_estudios' => PerfilProfesional::NIVELES_ESTUDIOS,
            ];
        }

        if ($nivel === 'revisor') {
            $data += [
                'email' => $tecnico->email,
                'telefono' => $tecnico->phone_number,
                'role_id' => (int) $tecnico->role_id,
            ];
        }

        return $data;
    }
}
