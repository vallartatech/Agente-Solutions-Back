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
            'fecha_nacimiento' => ['sometimes', 'nullable', 'date', 'after_or_equal:1930-01-01', 'before_or_equal:' . now()->subYears(18)->toDateString()],
        ], [
            'fecha_nacimiento.before_or_equal' => 'Debes ser mayor de edad para trabajar en la red.',
            'fecha_nacimiento.after_or_equal' => 'Revisa tu fecha de nacimiento.',
            'fecha_nacimiento.date' => 'Revisa tu fecha de nacimiento.',
        ]);

        $perfil = PerfilProfesional::perfilOCrear($user);

        // Datos financieros solo con consentimiento expreso
        $financieros = collect($datos)->only(['rfc', 'razon_social', 'banco', 'titular_cuenta', 'clabe'])->filter(fn ($v) => $v !== null && $v !== '');
        if ($financieros->isNotEmpty() && ($r = $this->sinConsentimiento($perfil))) return $r;

        DB::transaction(function () use ($datos, $perfil, $user) {
            $campos = collect($datos)->except(['especialidades', 'experiencias', 'fecha_nacimiento'])->all();
            if (array_key_exists('fecha_nacimiento', $datos)) {
                // La fecha vive en users (la usa también el calendario de cumpleaños)
                $user->forceFill(['birth_date' => $datos['fecha_nacimiento']])->save();
            }
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

    /**
     * Consentimiento expreso para tratar datos financieros (CLABE, RFC) y de identificación
     * (INE, comprobante de domicilio, antecedentes). Se guarda la fecha y hora de aceptación.
     */
    public function consentir(Request $request)
    {
        $user = $request->user();
        if (!PerfilProfesional::esTecnicoRed($user)) {
            return response()->json(['message' => 'Solo para técnicos de la red.'], 403);
        }
        $request->validate(['acepto' => 'accepted'], ['acepto.accepted' => 'Debes marcar la casilla para continuar.']);

        $perfil = PerfilProfesional::perfilOCrear($user);
        if (!$perfil->consentimiento_datos_at) {
            $perfil->forceFill(['consentimiento_datos_at' => now()])->save();
        }
        return response()->json(['success' => true, 'data' => $this->serializar($perfil->fresh(), $user, 'propio')]);
    }

    private function sinConsentimiento(TechnicianProfile $perfil)
    {
        return $perfil->consentimiento_datos_at ? null : response()->json([
            'message' => 'Primero acepta el consentimiento para el tratamiento de tus datos bancarios y de identificación.',
            'requiere_consentimiento' => true,
        ], 422);
    }

    /** Foto de perfil (obligatoria): Cloudinary, igual que en "Mi perfil". Permitida con la cuenta bloqueada. */
    public function subirFoto(Request $request)
    {
        $user = $request->user();
        if (!PerfilProfesional::esTecnicoRed($user)) {
            return response()->json(['message' => 'Solo para técnicos de la red.'], 403);
        }
        $request->validate(['foto' => 'required|image|mimes:jpeg,png,jpg,webp|max:10240'], [
            'foto.required' => 'Elige una foto.',
            'foto.image' => 'La foto debe ser una imagen (JPG, PNG o WEBP).',
            'foto.mimes' => 'La foto debe ser una imagen (JPG, PNG o WEBP).',
            'foto.max' => 'La foto pesa más de 10 MB.',
        ]);

        try {
            $cloudinary = new \Cloudinary\Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
            $subida = $cloudinary->uploadApi()->upload($request->file('foto')->getRealPath(), ['folder' => 'agente_perfiles']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'No se pudo subir la foto. Intenta de nuevo.'], 500);
        }

        $user->forceFill(['profile_picture' => $subida['secure_url']])->save();
        $perfil = PerfilProfesional::perfilOCrear($user);

        return response()->json(['success' => true, 'url' => $subida['secure_url'], 'data' => $this->serializar($perfil, $user->fresh(), 'propio')]);
    }

    // Documentos privados que se suben por separado (tipo => columna)
    private const DOCUMENTOS = [
        'comprobante_domicilio' => 'comprobante_domicilio_path',
        'antecedentes_penales' => 'antecedentes_penales_path',
    ];

    /** Comprobante de domicilio o antecedentes no penales (foto o PDF, privado). */
    public function subirDocumento(Request $request, $tipo)
    {
        $user = $request->user();
        if (!PerfilProfesional::esTecnicoRed($user)) {
            return response()->json(['message' => 'Solo para técnicos de la red.'], 403);
        }
        if (!isset(self::DOCUMENTOS[$tipo])) {
            return response()->json(['message' => 'Documento no válido.'], 422);
        }
        $request->validate(['archivo' => 'required|' . self::ARCHIVO_REGLA], [
            'archivo.required' => 'Elige el archivo del documento.',
            'archivo.mimes' => 'El documento debe ser una foto (JPG, PNG, WEBP, HEIC) o un PDF.',
            'archivo.max' => 'El archivo pesa más de 8 MB.',
        ]);

        $perfil = PerfilProfesional::perfilOCrear($user);
        if ($r = $this->sinConsentimiento($perfil)) return $r;
        $columna = self::DOCUMENTOS[$tipo];
        $anterior = $perfil->{$columna};
        $perfil->{$columna} = $request->file('archivo')->store("kyc/{$user->id}", self::DISCO);
        if ($anterior) Storage::disk(self::DISCO)->delete($anterior);
        // Un documento nuevo se vuelve a revisar (si ya estaba aprobado, sin bloquear la cuenta)
        PerfilProfesional::marcarCambio($perfil, 'identidad');

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
        if ($r = $this->sinConsentimiento($perfil)) return $r;
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
        } elseif (isset(self::DOCUMENTOS[$tipo])) {
            $ruta = TechnicianProfile::where('user_id', $userId)->value(self::DOCUMENTOS[$tipo]);
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

    // Perfil a nivel "público" (sin RFC, CLABE, contacto ni documentos), para el QR y "Ver detalles"
    public function datosPublicos(User $tecnico): array
    {
        $perfil = PerfilProfesional::perfilDe($tecnico) ?? new TechnicianProfile(['user_id' => $tecnico->id, 'estado' => 'incompleto']);
        return $this->serializar($perfil, $tecnico, 'publico');
    }

    /**
     * Resumen corto de varios técnicos para las tarjetas de cotización del cliente.
     * GET /tecnicos/resumenes?ids=1,2,3  →  { data: { "1": {...}, "2": {...} } }
     */
    public function resumenes(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) str_replace('u_', '', trim($id)))
            ->filter()
            ->unique()
            ->take(30)
            ->values();
        if ($ids->isEmpty()) {
            return response()->json(['success' => true, 'data' => (object) []]);
        }

        $usuarios = User::withoutGlobalScopes()->whereIn('id', $ids)->get();
        $perfiles = TechnicianProfile::whereIn('user_id', $ids)->get()->keyBy('user_id');
        $terminados = DB::table('work_orders')
            ->whereIn('tecnico_id', $ids)
            ->whereIn('status', ['Terminado', 'Listo', 'Finalizado', 'Completado'])
            ->selectRaw('tecnico_id, COUNT(*) as total')
            ->groupBy('tecnico_id')
            ->pluck('total', 'tecnico_id');
        $especialidades = DB::table('technician_specialties as ts')
            ->join('specialties as s', 's.id', '=', 'ts.specialty_id')
            ->whereIn('ts.user_id', $ids)
            ->orderBy('s.name')
            ->get(['ts.user_id', 's.id', 's.name'])
            ->groupBy('user_id');

        $data = [];
        foreach ($usuarios as $u) {
            $perfil = $perfiles->get($u->id);
            $resenas = (int) ($u->total_reviews_count ?? 0);
            $esps = ($especialidades->get($u->id) ?? collect())->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->values();
            $data[(string) $u->id] = [
                'user_id' => $u->id,
                'nombre' => trim("{$u->first_name} {$u->last_name}"),
                'foto' => $u->profile_picture,
                'verificado' => (bool) ($perfil?->identidad_verificada_at),
                'estudios_verificados' => (bool) ($perfil?->estudios_verificados_at),
                'calificacion' => $resenas ? round((float) $u->rating_stars_avg, 1) : null,
                'puntualidad' => $resenas ? round((float) $u->rating_time_avg, 1) : null,
                'total_resenas' => $resenas,
                'anios_experiencia' => $perfil?->anios_experiencia,
                'trabajos_terminados' => (int) ($terminados[$u->id] ?? 0),
                'especialidades' => $esps->take(3)->values(),
                'mas_especialidades' => max(0, $esps->count() - 3),
            ];
        }

        return response()->json(['success' => true, 'data' => (object) $data]);
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
            'foto' => $tecnico->profile_picture,
            'rol' => (int) $tecnico->role_id === 8 ? 'Técnico de cuadrilla' : 'Técnico independiente',
            'miembro_desde' => optional($tecnico->created_at)->toDateString(),
            'edad' => PerfilProfesional::edad($tecnico->birth_date ?? null),
            'calificacion' => $tecnico->total_reviews_count ? round((float) $tecnico->rating_stars_avg, 1) : null,
            'puntualidad' => $tecnico->total_reviews_count ? round((float) $tecnico->rating_time_avg, 1) : null,
            'total_resenas' => (int) ($tecnico->total_reviews_count ?? 0),
            'trabajos_terminados' => DB::table('work_orders')
                ->where('tecnico_id', $tecnico->id)
                ->whereIn('status', ['Terminado', 'Listo', 'Finalizado', 'Completado'])
                ->count(),
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
                'consentimiento_datos_at' => optional($perfil->consentimiento_datos_at)->toIso8601String(),
                'enviado_at' => $perfil->enviado_at,
                'revisado_at' => $perfil->revisado_at,
                'fecha_nacimiento' => $tecnico->birth_date ? \Illuminate\Support\Carbon::parse($tecnico->birth_date)->toDateString() : null,
                'sin_cursos' => (bool) $perfil->sin_cursos,
                'sin_empleos' => (bool) $perfil->sin_empleos,
                'tiene_ine_frente' => (bool) $perfil->ine_frente_path,
                'tiene_ine_reverso' => (bool) $perfil->ine_reverso_path,
                'tiene_comprobante_domicilio' => (bool) $perfil->comprobante_domicilio_path,
                'tiene_antecedentes_penales' => (bool) $perfil->antecedentes_penales_path,
                'faltantes' => PerfilProfesional::faltantes($perfil, $tecnico),
                'niveles_estudios' => PerfilProfesional::NIVELES_ESTUDIOS,
            ];
        }

        if ($nivel === 'revisor') {
            $data += [
                'email' => $tecnico->email,
                'telefono' => $tecnico->phone_number,
                'role_id' => (int) $tecnico->role_id,
                'registrado_at' => optional($tecnico->created_at)->toIso8601String(),
            ];
        }

        return $data;
    }
}
