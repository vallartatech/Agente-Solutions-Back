<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\OneSignalPush;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Chat interno del equipo (tipo WhatsApp).
 * - Solo admins y técnicos activos del mismo equipo (roles 0, 1, 2).
 * - Conversaciones de 2 personas o grupos; al agregar a una 3ª persona el chat se vuelve grupo.
 * - Cada participante ve los mensajes desde que entró (from_message_id) y, si lo sacaron
 *   o salió, hasta ese momento (until_message_id). Conserva lo anterior pero ya no ve lo nuevo.
 * - Agregar, sacar y renombrar: quien creó el chat y los admins (roles 0/1) que estén en él.
 * - Mensaje nuevo: push (OneSignal) a los demás participantes activos.
 */
class ChatController extends Controller
{
    private const TEAM_ROLES = [0, 1, 2];
    private const PAGE = 50;

    // Mis chats: título, último mensaje visible y no leídos
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$this->esDelEquipo($user)) {
            return response()->json(['error' => 'El chat es solo para el equipo.'], 403);
        }

        $participaciones = ChatParticipant::with(['chat.participants.user:id,first_name,last_name,profile_picture,role_id'])
            ->where('user_id', $user->id)->get();

        $chats = $participaciones->map(function (ChatParticipant $p) use ($user) {
            $ultimo = $this->visibles($p)->orderByDesc('id')->first();
            return array_merge($this->formatoChat($p->chat, $user), [
                'activo' => $p->isActive(),
                'no_leidos' => $this->noLeidosDe($p, $user),
                'ultimo_mensaje' => $ultimo ? $this->formatoMensaje($ultimo, $p->chat) : null,
                'orden' => $ultimo?->id ?? 0,
            ]);
        })->sortByDesc('orden')->values();

        return response()->json($chats);
    }

    // Total de mensajes sin leer (contador del encabezado)
    public function noLeidos(Request $request)
    {
        $user = $request->user();
        $total = ChatParticipant::where('user_id', $user->id)->get()
            ->sum(fn (ChatParticipant $p) => $this->noLeidosDe($p, $user));

        return response()->json(['total' => $total]);
    }

    // Personas del equipo con las que se puede chatear
    public function contactos(Request $request)
    {
        $user = $request->user();
        if (!$this->esDelEquipo($user)) {
            return response()->json([], 200);
        }

        $contactos = $this->equipo($user)->where('id', '!=', $user->id)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'profile_picture', 'role_id'])
            ->map(fn ($u) => $this->formatoUsuario($u));

        return response()->json($contactos);
    }

    // Nuevo chat: con 1 persona reutiliza el chat directo si ya existe; con 2+ (o con nombre) crea un grupo
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$this->esDelEquipo($user)) {
            return response()->json(['error' => 'El chat es solo para el equipo.'], 403);
        }

        $validated = $this->validar($request, [
            'user_ids' => 'required|array|min:1|max:100',
            'user_ids.*' => 'integer',
            'title' => 'nullable|string|max:80',
        ]);
        if ($validated instanceof \Illuminate\Http\JsonResponse) return $validated;

        $ids = $this->equipo($user)->whereIn('id', $validated['user_ids'])->where('id', '!=', $user->id)->pluck('id');
        if ($ids->isEmpty()) {
            return response()->json(['error' => 'Elige al menos a una persona de tu equipo.'], 422);
        }
        $titulo = trim((string) ($validated['title'] ?? ''));
        $esGrupo = $ids->count() > 1 || $titulo !== '';

        if (!$esGrupo) {
            $otro = $ids->first();
            $existente = Chat::where('is_group', false)
                ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
                ->whereHas('participants', fn ($q) => $q->where('user_id', $otro))
                ->first();
            if ($existente) {
                return response()->json(['chat' => $this->formatoChat($existente, $user)], 200);
            }
        }

        $chat = DB::transaction(function () use ($user, $ids, $esGrupo, $titulo) {
            $chat = Chat::create([
                'tenant_id' => $user->tenant_id,
                'title' => $esGrupo ? ($titulo ?: null) : null,
                'is_group' => $esGrupo,
                'created_by' => $user->id,
                'last_message_at' => now(),
            ]);
            foreach ($ids->prepend($user->id) as $id) {
                $chat->participants()->create(['user_id' => $id, 'added_by' => $user->id, 'from_message_id' => 0]);
            }
            if ($esGrupo) {
                $this->sistema($chat, $this->nombre($user) . ' creó el grupo' . ($titulo ? " «{$titulo}»" : ''));
            }
            return $chat;
        });

        return response()->json(['chat' => $this->formatoChat($chat->fresh(), $user)], 201);
    }

    // Mensajes de un chat (?after_id= para nuevos, ?before_id= para anteriores). Marca como leído.
    public function mensajes(Request $request, $id)
    {
        $user = $request->user();
        [$chat, $p] = $this->participacion($id, $user);
        if (!$p) {
            return response()->json(['error' => 'No perteneces a este chat.'], 403);
        }

        $query = $this->visibles($p);
        if ($request->filled('after_id')) {
            $mensajes = $query->where('id', '>', (int) $request->after_id)->orderBy('id')->limit(200)->get();
        } else {
            if ($request->filled('before_id')) {
                $query->where('id', '<', (int) $request->before_id);
            }
            $mensajes = $query->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();
        }

        // Marcar como leído hasta el último mensaje visible
        $ultimoVisible = $this->visibles($p)->max('id') ?? 0;
        if ($ultimoVisible > $p->last_read_message_id) {
            $p->last_read_message_id = $ultimoVisible;
            $p->save();
        }

        $chat->load('participants.user:id,first_name,last_name,profile_picture,role_id');

        return response()->json([
            'chat' => $this->formatoChat($chat, $user),
            'activo' => $p->isActive(),
            'puede_administrar' => $this->puedeAdministrar($chat, $user, $p),
            'participantes' => $chat->participants->filter->isActive()->map(fn ($x) => array_merge(
                $this->formatoUsuario($x->user),
                [
                    'es_creador' => (int) $x->user_id === (int) $chat->created_by,
                    'last_read_message_id' => (int) $x->last_read_message_id,
                ]
            ))->values(),
            'mensajes' => $mensajes->map(fn ($m) => $this->formatoMensaje($m, $chat)),
            'hay_anteriores' => $mensajes->isNotEmpty() && $this->visibles($p)->where('id', '<', $mensajes->first()->id)->exists(),
        ]);
    }

    // Enviar mensaje (texto y/o foto)
    public function enviar(Request $request, $id)
    {
        $user = $request->user();
        [$chat, $p] = $this->participacion($id, $user);
        if (!$p || !$p->isActive()) {
            return response()->json(['error' => 'Ya no participas en este chat.'], 403);
        }

        $validated = $this->validar($request, [
            'body' => 'nullable|string|max:4000',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp,heic,heif|max:10240',
        ]);
        if ($validated instanceof \Illuminate\Http\JsonResponse) return $validated;

        $texto = trim((string) ($validated['body'] ?? ''));
        if ($texto === '' && !$request->hasFile('image')) {
            return response()->json(['error' => 'Escribe un mensaje o agrega una foto.'], 422);
        }

        $url = null;
        if ($request->hasFile('image')) {
            try {
                $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
                $url = $cloudinary->uploadApi()->upload($request->file('image')->getRealPath(), ['folder' => 'agente_chats'])['secure_url'];
            } catch (\Throwable $e) {
                Log::error('Error subiendo foto del chat: ' . $e->getMessage());
                return response()->json(['error' => 'No se pudo subir la foto. Intenta de nuevo.'], 500);
            }
        }

        $mensaje = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'type' => $url ? 'image' : 'text',
            'body' => $texto !== '' ? $texto : null,
            'attachment_url' => $url,
        ]);
        $chat->update(['last_message_at' => now()]);
        $p->update(['last_read_message_id' => $mensaje->id]);

        // Push a los demás participantes activos
        $destinatarios = $chat->activeParticipants()->where('user_id', '!=', $user->id)->pluck('user_id')->all();
        if ($destinatarios) {
            $chat->load('participants.user:id,first_name,last_name');
            $titulo = $chat->is_group
                ? '💬 ' . $this->nombre($user) . ' · ' . $this->tituloChat($chat, $user)
                : '💬 ' . $this->nombre($user) . ' te envió un mensaje';
            OneSignalPush::sendToUsers(
                $destinatarios,
                $titulo,
                $texto !== '' ? mb_strimwidth($texto, 0, 140, '…') : '📷 Foto',
                "/chats/{$chat->id}",
                ['alert_type' => 'chat', 'chat_id' => $chat->id]
            );
        }

        return response()->json(['mensaje' => $this->formatoMensaje($mensaje, $chat)], 201);
    }

    // Agregar personas (el chat de 2 se vuelve grupo). No ven lo que se habló antes de entrar.
    public function agregar(Request $request, $id)
    {
        $user = $request->user();
        [$chat, $p] = $this->participacion($id, $user);
        if (!$p || !$this->puedeAdministrar($chat, $user, $p)) {
            return response()->json(['error' => 'Solo quien creó el chat o un admin puede agregar personas.'], 403);
        }

        $validated = $this->validar($request, ['user_ids' => 'required|array|min:1|max:100', 'user_ids.*' => 'integer']);
        if ($validated instanceof \Illuminate\Http\JsonResponse) return $validated;

        $nuevos = $this->equipo($user)->whereIn('id', $validated['user_ids'])->get(['id', 'first_name', 'last_name']);
        $agregados = collect();

        DB::transaction(function () use ($chat, $user, $nuevos, &$agregados) {
            $desde = (int) ($chat->messages()->max('id') ?? 0);
            foreach ($nuevos as $u) {
                $existente = $chat->participants()->where('user_id', $u->id)->first();
                if ($existente && $existente->isActive()) continue;
                if ($existente) {
                    $existente->update(['left_at' => null, 'until_message_id' => null, 'from_message_id' => $desde, 'added_by' => $user->id]);
                } else {
                    $chat->participants()->create(['user_id' => $u->id, 'added_by' => $user->id, 'from_message_id' => $desde]);
                }
                $agregados->push($u);
            }
            if ($agregados->isEmpty()) return;

            if (!$chat->is_group) {
                $chat->update(['is_group' => true]);
            }
            $this->sistema($chat, $this->nombre($user) . ' agregó a ' . $agregados->map(fn ($u) => $this->nombre($u))->join(', ', ' y '));
        });

        if ($agregados->isEmpty()) {
            return response()->json(['error' => 'Esas personas ya están en el chat.'], 422);
        }

        $chat->load('participants.user:id,first_name,last_name');
        OneSignalPush::sendToUsers(
            $agregados->pluck('id')->all(),
            '💬 ' . $this->tituloChat($chat, $user),
            $this->nombre($user) . ' te agregó al grupo',
            "/chats/{$chat->id}",
            ['alert_type' => 'chat', 'chat_id' => $chat->id]
        );

        return response()->json(['success' => true, 'chat' => $this->formatoChat($chat->fresh(), $user)]);
    }

    // Sacar a alguien (creador/admin) o salirse uno mismo. Conserva lo anterior, ya no ve lo nuevo.
    public function quitar(Request $request, $id, $userId)
    {
        $user = $request->user();
        [$chat, $p] = $this->participacion($id, $user);
        if (!$p || !$p->isActive()) {
            return response()->json(['error' => 'No participas en este chat.'], 403);
        }
        if (!$chat->is_group) {
            return response()->json(['error' => 'Solo se puede sacar personas de un grupo.'], 422);
        }

        $esUnoMismo = (int) $userId === (int) $user->id;
        if (!$esUnoMismo && !$this->puedeAdministrar($chat, $user, $p)) {
            return response()->json(['error' => 'Solo quien creó el grupo o un admin puede sacar personas.'], 403);
        }

        $objetivo = $chat->participants()->where('user_id', (int) $userId)->whereNull('left_at')->with('user:id,first_name,last_name')->first();
        if (!$objetivo) {
            return response()->json(['error' => 'Esa persona ya no está en el grupo.'], 404);
        }

        DB::transaction(function () use ($chat, $user, $objetivo, $esUnoMismo) {
            $aviso = $esUnoMismo
                ? $this->nombre($user) . ' salió del grupo'
                : $this->nombre($user) . ' sacó a ' . $this->nombre($objetivo->user);
            $mensaje = $this->sistema($chat, $aviso);
            // Ve hasta el aviso de su salida (incluido)
            $objetivo->update(['left_at' => now(), 'until_message_id' => $mensaje->id]);
        });

        return response()->json(['success' => true]);
    }

    // Renombrar un grupo
    public function renombrar(Request $request, $id)
    {
        $user = $request->user();
        [$chat, $p] = $this->participacion($id, $user);
        if (!$p || !$chat->is_group || !$this->puedeAdministrar($chat, $user, $p)) {
            return response()->json(['error' => 'Solo quien creó el grupo o un admin puede cambiar el nombre.'], 403);
        }

        $validated = $this->validar($request, ['title' => 'required|string|max:80']);
        if ($validated instanceof \Illuminate\Http\JsonResponse) return $validated;

        $titulo = trim($validated['title']);
        $chat->update(['title' => $titulo]);
        $this->sistema($chat, $this->nombre($user) . " cambió el nombre del grupo a «{$titulo}»");

        return response()->json(['success' => true, 'chat' => $this->formatoChat($chat->fresh(), $user)]);
    }

    // ── Ayudantes ──

    private function validar(Request $request, array $reglas)
    {
        try {
            return $request->validate($reglas);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => collect($e->errors())->flatten()->first()], 422);
        }
    }

    private function esDelEquipo($user): bool
    {
        return $user && in_array((int) $user->role_id, self::TEAM_ROLES, true);
    }

    // Admins y técnicos activos del mismo equipo (tenant nulo y tenant 1 son Agente Solutions)
    private function equipo($user)
    {
        $query = User::withoutGlobalScopes()->where('is_active', 1)->whereIn('role_id', self::TEAM_ROLES);
        if (!empty($user->tenant_id) && (int) $user->tenant_id !== 1) {
            return $query->where('tenant_id', $user->tenant_id);
        }
        return $query->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', 1));
    }

    private function participacion($chatId, $user): array
    {
        $chat = Chat::find($chatId);
        $p = $chat ? $chat->participants()->where('user_id', $user->id)->first() : null;
        return [$chat, $p];
    }

    // Mensajes que este participante puede ver
    private function visibles(ChatParticipant $p)
    {
        $q = ChatMessage::where('chat_id', $p->chat_id)->where('id', '>', $p->from_message_id);
        if (!is_null($p->until_message_id)) {
            $q->where('id', '<=', $p->until_message_id);
        }
        return $q;
    }

    private function noLeidosDe(ChatParticipant $p, $user): int
    {
        return $this->visibles($p)
            ->where('id', '>', $p->last_read_message_id)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->where('type', '!=', 'system')
            ->count();
    }

    private function puedeAdministrar(Chat $chat, $user, ?ChatParticipant $p): bool
    {
        return $p && $p->isActive()
            && ((int) $chat->created_by === (int) $user->id || in_array((int) $user->role_id, [0, 1], true));
    }

    private function sistema(Chat $chat, string $texto): ChatMessage
    {
        $mensaje = ChatMessage::create(['chat_id' => $chat->id, 'user_id' => null, 'type' => 'system', 'body' => $texto]);
        $chat->update(['last_message_at' => now()]);
        return $mensaje;
    }

    private function nombre($u): string
    {
        return $u ? (trim("{$u->first_name} {$u->last_name}") ?: 'Usuario') : 'Usuario';
    }

    // Grupo: su nombre o los nombres de los participantes. Directo: el nombre de la otra persona.
    private function tituloChat(Chat $chat, $user): string
    {
        $otros = $chat->participants->filter(fn ($x) => (int) $x->user_id !== (int) $user->id && $x->isActive());
        if ($chat->is_group) {
            return $chat->title ?: ($otros->map(fn ($x) => explode(' ', $this->nombre($x->user))[0])->take(4)->join(', ') ?: 'Grupo');
        }
        $otro = $chat->participants->first(fn ($x) => (int) $x->user_id !== (int) $user->id);
        return $this->nombre($otro?->user);
    }

    private function formatoChat(Chat $chat, $user): array
    {
        $chat->loadMissing('participants.user:id,first_name,last_name,profile_picture,role_id');
        $otro = $chat->is_group ? null : $chat->participants->first(fn ($x) => (int) $x->user_id !== (int) $user->id)?->user;

        return [
            'id' => $chat->id,
            'es_grupo' => (bool) $chat->is_group,
            'titulo' => $this->tituloChat($chat, $user),
            'nombre_grupo' => $chat->title,
            'foto' => $otro?->profile_picture,
            'otro_usuario_id' => $otro?->id,
            'miembros' => $chat->participants->filter->isActive()->count(),
            'creado_por' => (int) $chat->created_by,
        ];
    }

    private function formatoUsuario($u): array
    {
        return [
            'id' => $u->id,
            'nombre' => $this->nombre($u),
            'foto' => $u->profile_picture,
            'rol' => (int) $u->role_id === 2 ? 'Técnico' : 'Admin',
        ];
    }

    private function formatoMensaje(ChatMessage $m, Chat $chat): array
    {
        $chat->loadMissing('participants.user:id,first_name,last_name,profile_picture,role_id');
        $autor = $m->user_id ? $chat->participants->firstWhere('user_id', $m->user_id)?->user : null;

        return [
            'id' => $m->id,
            'tipo' => $m->type,
            'texto' => $m->body,
            'imagen' => $m->attachment_url,
            'autor_id' => $m->user_id ? (int) $m->user_id : null,
            'autor' => $autor ? $this->nombre($autor) : null,
            'autor_foto' => $autor?->profile_picture,
            'fecha' => $m->created_at?->toIso8601String(),
        ];
    }
}
