<?php

namespace App\Http\Controllers;

use App\Models\Specialty;
use App\Models\User;
use App\Notifications\MovimientoNotificacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Habilidades sugeridas por los técnicos.
 *
 * El catálogo de habilidades es fijo: solo Root/Admin lo cambian. Si a un técnico le falta una,
 * la sugiere; Root/Admin la aprueban (pueden corregir el nombre), la unen a una que ya existe o la
 * rechazan. Al resolverla, la habilidad se agrega al técnico (y a todos los que sugirieron la misma)
 * y se les avisa. El filtro de trabajos por habilidad depende de que el catálogo no tenga duplicados.
 */
class SugerenciaEspecialidadController extends Controller
{
    public const REVISORES = [0, 1];
    private const MAX_PENDIENTES = 5;

    // ───────────────────────── Ayudas (también las usan perfil, registro y especialidades) ─────────────────────────

    /** "  aire   ACONDICIONADO " → "aire acondicionado" (sin acentos) para comparar nombres. */
    public static function normalizar(?string $nombre): string
    {
        return Str::ascii(mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $nombre))));
    }

    /** Espacios limpios y primera letra en mayúscula. */
    public static function limpiarNombre(?string $nombre): string
    {
        $n = trim(preg_replace('/\s+/u', ' ', (string) $nombre));
        return $n === '' ? '' : mb_strtoupper(mb_substr($n, 0, 1)) . mb_substr($n, 1);
    }

    public static function buscarEnCatalogo(?string $nombre): ?Specialty
    {
        $n = self::normalizar($nombre);
        if ($n === '') return null;
        return Specialty::all(['id', 'name', 'icon'])->first(fn ($e) => self::normalizar($e->name) === $n);
    }

    public static function esRevisor(?User $user): bool
    {
        return $user && in_array((int) $user->role_id, self::REVISORES, true);
    }

    /**
     * Convierte lo que manda una pantalla (ids del catálogo o nombres) en ids del catálogo.
     * Un nombre que ya existe se usa tal cual; uno nuevo NO se agrega al catálogo: queda como sugerencia
     * del técnico (salvo que quien lo guarda sea Root/Admin, que sí puede crear habilidades).
     */
    public static function idsDesdeEntrada(array $items, ?User $tecnico, ?User $actor = null): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (is_numeric($item)) {
                if (Specialty::whereKey((int) $item)->exists()) $ids[] = (int) $item;
                continue;
            }
            $nombre = self::limpiarNombre(is_string($item) ? $item : '');
            if ($nombre === '' || mb_strlen($nombre) > 60) continue;

            if ($existente = self::buscarEnCatalogo($nombre)) {
                $ids[] = $existente->id;
            } elseif (self::esRevisor($actor)) {
                $ids[] = Specialty::create(['name' => $nombre, 'icon' => '🔧', 'category' => 'General'])->id;
            } elseif ($tecnico) {
                self::registrar($tecnico, $nombre);
            }
        }
        return array_values(array_unique($ids));
    }

    /** Guarda la sugerencia (si no estaba ya pendiente) y avisa a los revisores. Devuelve [fila, esNueva]. */
    private static function registrar(User $tecnico, string $nombre, ?string $comentario = null): array
    {
        if (!Schema::hasTable('specialty_suggestions')) {
            return [null, false];
        }
        $n = self::normalizar($nombre);
        $previa = DB::table('specialty_suggestions')
            ->where('user_id', $tecnico->id)->where('status', 'pendiente')->get()
            ->first(fn ($s) => self::normalizar($s->name) === $n);
        if ($previa) {
            return [$previa, false];
        }

        $id = DB::table('specialty_suggestions')->insertGetId([
            'user_id' => $tecnico->id,
            'name' => $nombre,
            'comentario' => $comentario ?: null,
            'status' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $quien = trim("{$tecnico->first_name} {$tecnico->last_name}") ?: 'Un técnico';
        $revisores = User::withoutGlobalScopes()->whereIn('role_id', self::REVISORES)->where('is_active', 1)->get();
        self::avisar($revisores, '💡 Habilidad sugerida', "{$quien} sugirió agregar «{$nombre}» a la lista de habilidades.",
            '/sala-espera-tecnicos?pestana=habilidades', ['sugerencia_id' => $id]);

        return [DB::table('specialty_suggestions')->find($id), true];
    }

    private static function avisar($usuarios, string $titulo, string $mensaje, string $url, array $extra = []): void
    {
        foreach ($usuarios as $usuario) {
            try {
                // El push sale solo: PushForDatabaseNotifications lo envía por cada aviso de la campanita
                $usuario->notify(new MovimientoNotificacion([
                    'titulo' => $titulo,
                    'mensaje' => $mensaje,
                    'url' => $url,
                    'alert_type' => 'habilidad_sugerida',
                    'extra' => $extra,
                ]));
            } catch (\Throwable $e) {
                Log::warning("Habilidad sugerida: no se pudo avisar al usuario {$usuario->id}: " . $e->getMessage());
            }
        }
    }

    private function formato($s, array $tecnicos = [], int $veces = 1): array
    {
        return [
            'id' => $s->id,
            'nombre' => $s->name,
            'comentario' => $s->comentario,
            'estado' => $s->status,
            'specialty_id' => $s->specialty_id,
            'motivo' => $s->motivo,
            'tecnico' => $tecnicos[$s->user_id] ?? null,
            'veces' => $veces,
            'creada' => $s->created_at ? \Carbon\Carbon::parse($s->created_at)->toIso8601String() : null,
        ];
    }

    // ───────────────────────── Técnico ─────────────────────────

    // POST /especialidades/sugerencias  { nombre, comentario? }
    public function store(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|min:3|max:60',
            'comentario' => 'nullable|string|max:300',
        ], [
            'nombre.required' => 'Escribe la habilidad que quieres sugerir.',
            'nombre.min' => 'Escribe al menos 3 letras.',
            'nombre.max' => 'Usa máximo 60 caracteres.',
        ]);
        $user = $request->user();
        $nombre = self::limpiarNombre($datos['nombre']);

        if ($existente = self::buscarEnCatalogo($nombre)) {
            return response()->json([
                'success' => true,
                'ya_existe' => true,
                'specialty' => $existente,
                'message' => "«{$existente->name}» ya está en la lista; la marcamos por ti.",
            ]);
        }

        $pendientes = DB::table('specialty_suggestions')->where('user_id', $user->id)->where('status', 'pendiente')->get(['name']);
        $yaLaPidio = $pendientes->contains(fn ($s) => self::normalizar($s->name) === self::normalizar($nombre));
        if (!$yaLaPidio && $pendientes->count() >= self::MAX_PENDIENTES) {
            return response()->json(['message' => 'Ya tienes ' . self::MAX_PENDIENTES . ' sugerencias en revisión. Espera a que las revisemos.'], 422);
        }
        [$fila, $nueva] = self::registrar($user, $nombre, $datos['comentario'] ?? null);

        return response()->json([
            'success' => true,
            'ya_existe' => false,
            'sugerencia' => $this->formato($fila),
            'message' => $nueva
                ? "¡Gracias! Revisaremos «{$nombre}» y te avisaremos cuando esté en la lista."
                : "Ya habías sugerido «{$fila->name}»; sigue en revisión.",
        ], $nueva ? 201 : 200);
    }

    // GET /especialidades/sugerencias/mias — las que siguen en revisión
    public function mias(Request $request)
    {
        $filas = DB::table('specialty_suggestions')
            ->where('user_id', $request->user()->id)->where('status', 'pendiente')
            ->orderBy('created_at')->get();
        return response()->json(['success' => true, 'data' => $filas->map(fn ($s) => $this->formato($s))->values()]);
    }

    // ───────────────────────── Root / Admin ─────────────────────────

    // GET /admin/especialidades/sugerencias?estado=pendiente|resueltas
    public function index(Request $request)
    {
        if (!self::esRevisor($request->user())) {
            return response()->json(['message' => 'Solo Root y Admin revisan las habilidades sugeridas.'], 403);
        }

        $pendientes = $request->input('estado', 'pendiente') === 'pendiente';
        $filas = DB::table('specialty_suggestions')
            ->when($pendientes, fn ($q) => $q->where('status', 'pendiente'), fn ($q) => $q->where('status', '!=', 'pendiente'))
            ->orderBy($pendientes ? 'created_at' : 'reviewed_at', $pendientes ? 'asc' : 'desc')
            ->limit(200)->get();

        $tecnicos = User::withoutGlobalScopes()->whereIn('id', $filas->pluck('user_id')->unique())
            ->get(['id', 'first_name', 'last_name', 'role_id', 'profile_picture'])
            ->mapWithKeys(fn ($u) => [$u->id => [
                'id' => $u->id,
                'nombre' => trim("{$u->first_name} {$u->last_name}") ?: $u->name,
                'foto' => $u->profile_picture,
            ]])->all();

        // Cuántos técnicos piden lo mismo (ayuda a decidir)
        $porNombre = DB::table('specialty_suggestions')->where('status', 'pendiente')->get(['name'])
            ->countBy(fn ($s) => self::normalizar($s->name));

        return response()->json([
            'success' => true,
            'data' => $filas->map(fn ($s) => $this->formato($s, $tecnicos, (int) ($porNombre[self::normalizar($s->name)] ?? 1)))->values(),
        ]);
    }

    // POST /admin/especialidades/sugerencias/{id}/aprobar  { nombre?, icono? }
    public function aprobar(Request $request, int $id)
    {
        [$sugerencia, $error] = $this->pendiente($request, $id);
        if ($error) return $error;

        $datos = $request->validate([
            'nombre' => 'nullable|string|min:3|max:60',
            'icono' => 'nullable|string|max:16',
        ]);
        $nombre = self::limpiarNombre($datos['nombre'] ?? $sugerencia->name);

        // Si con el nombre corregido ya existe, se une a esa en lugar de duplicarla
        $especialidad = self::buscarEnCatalogo($nombre)
            ?? Specialty::create(['name' => $nombre, 'icon' => $datos['icono'] ?? '🔧', 'category' => 'General']);

        $total = $this->resolver($sugerencia, $especialidad, $request->user());
        return response()->json([
            'success' => true,
            'specialty' => $especialidad,
            'message' => "«{$especialidad->name}» ya está en la lista" . ($total > 1 ? " y se agregó a {$total} técnicos." : ' y se agregó al técnico.'),
        ]);
    }

    // POST /admin/especialidades/sugerencias/{id}/unir  { specialty_id }
    public function unir(Request $request, int $id)
    {
        [$sugerencia, $error] = $this->pendiente($request, $id);
        if ($error) return $error;

        $datos = $request->validate(['specialty_id' => 'required|integer'], ['specialty_id.required' => 'Elige la habilidad con la que se une.']);
        $especialidad = Specialty::find($datos['specialty_id']);
        if (!$especialidad) {
            return response()->json(['message' => 'Esa habilidad no existe.'], 422);
        }

        $total = $this->resolver($sugerencia, $especialidad, $request->user());
        return response()->json([
            'success' => true,
            'specialty' => $especialidad,
            'message' => "Se agregó «{$especialidad->name}» " . ($total > 1 ? "a {$total} técnicos." : 'al técnico.'),
        ]);
    }

    // POST /admin/especialidades/sugerencias/{id}/rechazar  { motivo? }
    public function rechazar(Request $request, int $id)
    {
        [$sugerencia, $error] = $this->pendiente($request, $id);
        if ($error) return $error;

        $motivo = trim((string) ($request->validate(['motivo' => 'nullable|string|max:300'])['motivo'] ?? ''));
        DB::table('specialty_suggestions')->where('id', $sugerencia->id)->update([
            'status' => 'rechazada',
            'motivo' => $motivo ?: null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        $tecnico = User::withoutGlobalScopes()->find($sugerencia->user_id);
        if ($tecnico) {
            self::avisar(collect([$tecnico]), 'Habilidad sugerida',
                "No agregamos «{$sugerencia->name}» a la lista" . ($motivo ? ": {$motivo}" : '.') . ' Puedes elegir la más parecida en tu perfil.',
                '/mi-perfil', ['sugerencia_id' => $sugerencia->id]);
        }

        return response()->json(['success' => true, 'message' => 'Sugerencia rechazada; se le avisó al técnico.']);
    }

    /** [sugerencia, null] o [null, respuesta de error]. */
    private function pendiente(Request $request, int $id): array
    {
        if (!self::esRevisor($request->user())) {
            return [null, response()->json(['message' => 'Solo Root y Admin revisan las habilidades sugeridas.'], 403)];
        }
        $s = DB::table('specialty_suggestions')->find($id);
        if (!$s) {
            return [null, response()->json(['message' => 'Sugerencia no encontrada.'], 404)];
        }
        if ($s->status !== 'pendiente') {
            return [null, response()->json(['message' => 'Esta sugerencia ya se revisó.'], 422)];
        }
        return [$s, null];
    }

    /**
     * Resuelve esta sugerencia y las pendientes con el mismo nombre: la habilidad se agrega a cada
     * técnico que la pidió y se le avisa. Devuelve cuántos técnicos la recibieron.
     */
    private function resolver($sugerencia, Specialty $especialidad, User $revisor): int
    {
        $n = self::normalizar($sugerencia->name);
        $mismas = DB::table('specialty_suggestions')->where('status', 'pendiente')->get()
            ->filter(fn ($s) => (int) $s->id === (int) $sugerencia->id || self::normalizar($s->name) === $n);

        DB::table('specialty_suggestions')->whereIn('id', $mismas->pluck('id'))->update([
            'status' => self::normalizar($especialidad->name) === $n ? 'aprobada' : 'unida',
            'specialty_id' => $especialidad->id,
            'reviewed_by' => $revisor->id,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        $tecnicos = User::withoutGlobalScopes()->whereIn('id', $mismas->pluck('user_id')->unique())->get();
        foreach ($tecnicos as $tecnico) {
            $tecnico->specialties()->syncWithoutDetaching([$especialidad->id]);
        }

        $mensaje = self::normalizar($especialidad->name) === $n
            ? "Agregamos «{$especialidad->name}» a la lista y ya está en tus habilidades."
            : "Tu sugerencia «{$sugerencia->name}» se agregó a tus habilidades como «{$especialidad->name}».";
        self::avisar($tecnicos, '✅ Habilidad agregada', $mensaje, '/mi-perfil', ['specialty_id' => $especialidad->id]);

        return $tecnicos->count();
    }
}
