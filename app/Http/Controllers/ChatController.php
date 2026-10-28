<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatSticker;
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
    private const MAX_MEDIA_KB = 12288; // 12 MB (límite de subida del servidor)
    private const EN_LINEA_SEGUNDOS = 60;
    // Fondos predefinidos válidos (el frontend los dibuja); también se acepta la URL de una imagen subida
    private const FONDOS = ['default', 'noche', 'atardecer', 'oceano', 'bosque', 'naranja', 'claro', 'puntos'];

    // Mis chats: título, último mensaje visible y no leídos
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$this->esDelEquipo($user)) {
            return response()->json(['error' => 'El chat es solo para el equipo.'], 403);
        }

        $participaciones = ChatParticipant::with(['chat.participants.user:id,first_name,last_name,profile_picture,role_id,chat_last_seen_at'])
            ->where('user_id', $user->id)->get();

        $this->tocarPresencia($user);

        $chats = $participaciones->map(function (ChatParticipant $p) use ($user) {
            $ultimo = $this->visibles($p)->orderByDesc('id')->first();
            // "Eliminar chat": oculto hasta que llegue un mensaje nuevo
            if (!$ultimo && $p->cleared_until_message_id > 0) {
                return null;
            }
            return array_merge($this->formatoChat($p->chat, $user), [
                'activo' => $p->isActive(),
                'no_leidos' => $this->noLeidosDe($p, $user),
                'ultimo_mensaje' => $ultimo ? $this->formatoMensaje($ultimo, $p->chat) : null,
                'orden' => $ultimo?->id ?? 0,
            ]);
        })->filter()->sortByDesc('orden')->values();

        return response()->json($chats);
    }

    // Total de mensajes sin leer (contador del encabezado)
    public function noLeidos(Request $request)
    {
        $user = $request->user();
        $this->tocarPresencia($user); // el encabezado consulta esto cada 20 s: sirve para "En línea"
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
        $this->tocarPresencia($user);

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

        $chat->load('participants.user:id,first_name,last_name,profile_picture,role_id,chat_last_seen_at');

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
            // Mensajes eliminados hace poco: quien ya tiene el chat abierto los quita sin recargar
            'eliminados' => $this->visibles($p)->where('deleted_at', '>=', now()->subMinutes(15))->pluck('id'),
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

        // media: foto o video (también se acepta "image" de la versión anterior); sticker_id: uno de mis stickers
        $validated = $this->validar($request, [
            'body' => 'nullable|string|max:4000',
            'media' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif,heic,heif,mp4,mov,webm,3gp,m4v|max:' . self::MAX_MEDIA_KB,
            'image' => 'nullable|file|mimes:jpeg,png,jpg,webp,gif,heic,heif|max:' . self::MAX_MEDIA_KB,
            'sticker_id' => 'nullable|integer',
        ], [
            'media.max' => 'El archivo pesa demasiado (máximo 12 MB).',
            'media.mimes' => 'Solo se pueden enviar fotos o videos.',
        ]);
        if ($validated instanceof \Illuminate\Http\JsonResponse) return $validated;

        $texto = trim((string) ($validated['body'] ?? ''));
        $archivo = $request->file('media') ?? $request->file('image');
        // Sticker: uno mío o uno del paquete del equipo
        $sticker = !empty($validated['sticker_id'])
            ? (ChatSticker::where('user_id', $user->id)->where('is_team', false)->find($validated['sticker_id'])
                ?? $this->stickersEquipo($user)->find($validated['sticker_id']))
            : null;
        if (!empty($validated['sticker_id']) && !$sticker) {
            return response()->json(['error' => 'Ese sticker ya no existe.'], 422);
        }
        if ($texto === '' && !$archivo && !$sticker) {
            return response()->json(['error' => 'Escribe un mensaje o agrega una foto o video.'], 422);
        }

        $tipo = 'text';
        $url = null;
        if ($sticker) {
            $tipo = 'sticker';
            $url = $sticker->url;
            $texto = '';
        } elseif ($archivo) {
            $esVideo = str_starts_with((string) $archivo->getMimeType(), 'video/');
            try {
                $url = $this->subir($archivo->getRealPath(), $esVideo ? 'video' : 'image', 'agente_chats');
            } catch (\Throwable $e) {
                Log::error('Error subiendo archivo del chat: ' . $e->getMessage());
                return response()->json(['error' => 'No se pudo subir el archivo. Intenta de nuevo.'], 500);
            }
            $tipo = $esVideo ? 'video' : 'image';
        }

        $mensaje = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'type' => $tipo,
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
                $texto !== '' ? mb_strimwidth($texto, 0, 140, '…') : (['video' => '🎥 Video', 'sticker' => '💟 Sticker'][$tipo] ?? '📷 Foto'),
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

    // Eliminar un mensaje propio para todos ("Se eliminó este mensaje")
    public function eliminarMensaje(Request $request, $id, $mensajeId)
    {
        $user = $request->user();
        [$chat, $p] = $this->participacion($id, $user);
        if (!$p || !$p->isActive()) {
            return response()->json(['error' => 'Ya no participas en este chat.'], 403);
        }

        $mensaje = $this->visibles($p)->whereKey((int) $mensajeId)->first();
        if (!$mensaje || (int) $mensaje->user_id !== (int) $user->id || $mensaje->type === 'system') {
            return response()->json(['error' => 'Solo puedes eliminar tus propios mensajes.'], 403);
        }

        $mensaje->update(['body' => null, 'attachment_url' => null, 'deleted_at' => now()]);

        return response()->json(['mensaje' => $this->formatoMensaje($mensaje->fresh(), $chat)]);
    }

    // "Eliminar chat": lo quita de MI lista (los demás lo conservan). En un grupo, además sale de él.
    // Si después llega un mensaje nuevo, el chat reaparece solo con lo nuevo.
    public function eliminarChat(Request $request, $id)
    {
        $user = $request->user();
        [$chat, $p] = $this->participacion($id, $user);
        if (!$p) {
            return response()->json(['error' => 'No perteneces a este chat.'], 403);
        }

        DB::transaction(function () use ($chat, $user, $p) {
            if ($chat->is_group && $p->isActive()) {
                $aviso = $this->sistema($chat, $this->nombre($user) . ' salió del grupo');
                $p->update(['left_at' => now(), 'until_message_id' => $aviso->id]);
            }
            $p->update([
                'cleared_until_message_id' => (int) ($chat->messages()->max('id') ?? 0),
                'last_read_message_id' => (int) ($chat->messages()->max('id') ?? 0),
            ]);
        });

        return response()->json(['success' => true]);
    }

    // Stickers: los del equipo (todos los usan) y los míos (subidos o guardados)
    public function stickers(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'equipo' => $this->stickersEquipo($user)->latest('id')->get(['id', 'url']),
            'mios' => ChatSticker::where('user_id', $user->id)->where('is_team', false)->latest('id')->get(['id', 'url']),
            'puede_administrar' => (bool) $user->can_manage_stickers,
        ]);
    }

    // Subir un sticker (personal o del equipo con equipo=1). Solo el encargado (can_manage_stickers)
    // puede subir stickers; los demás solo usan los del equipo y guardan los que les envían.
    public function crearSticker(Request $request)
    {
        $user = $request->user();
        if (!$user->can_manage_stickers) {
            return response()->json(['error' => 'Solo el encargado puede subir stickers. Puedes guardar los que te envíen.'], 403);
        }
        $validated = $this->validar($request, [
            'image' => 'required|file|mimes:jpeg,png,jpg,webp,gif|max:4096',
            'equipo' => 'nullable|boolean',
        ], [
            'image.required' => 'Elige una imagen para el sticker.',
            'image.max' => 'La imagen del sticker debe pesar menos de 4 MB.',
        ]);
        if ($validated instanceof \Illuminate\Http\JsonResponse) return $validated;

        $delEquipo = (bool) ($validated['equipo'] ?? false);
        if ($error = $this->limiteStickers($user, $delEquipo)) {
            return response()->json(['error' => $error], 422);
        }

        try {
            $url = $this->subir($request->file('image')->getRealPath(), 'image', 'agente_chat_stickers');
        } catch (\Throwable $e) {
            Log::error('Error subiendo sticker: ' . $e->getMessage());
            return response()->json(['error' => 'No se pudo subir el sticker.'], 500);
        }

        $sticker = ChatSticker::create([
            'user_id' => $user->id,
            'is_team' => $delEquipo,
            'tenant_id' => $delEquipo ? $user->tenant_id : null,
            'url' => $url,
        ]);
        return response()->json(['id' => $sticker->id, 'url' => $sticker->url, 'equipo' => $delEquipo], 201);
    }

    // Guardar en mis stickers uno que me enviaron (mensaje_id) o uno del equipo (sticker_id)
    public function guardarSticker(Request $request)
    {
        $user = $request->user();
        $validated = $this->validar($request, [
            'mensaje_id' => 'nullable|integer',
            'sticker_id' => 'nullable|integer',
        ]);
        if ($validated instanceof \Illuminate\Http\JsonResponse) return $validated;

        $url = null;
        if (!empty($validated['mensaje_id'])) {
            $mensaje = ChatMessage::where('type', 'sticker')->whereNull('deleted_at')->find($validated['mensaje_id']);
            $p = $mensaje ? ChatParticipant::where('chat_id', $mensaje->chat_id)->where('user_id', $user->id)->first() : null;
            // Solo si ese mensaje es visible para mí
            if ($p && $this->visibles($p)->whereKey($mensaje->id)->exists()) {
                $url = $mensaje->attachment_url;
            }
        } elseif (!empty($validated['sticker_id'])) {
            $url = $this->stickersEquipo($user)->whereKey($validated['sticker_id'])->value('url');
        }
        if (!$url) {
            return response()->json(['error' => 'Ese sticker ya no está disponible.'], 404);
        }

        $existente = ChatSticker::where('user_id', $user->id)->where('is_team', false)->where('url', $url)->first();
        if ($existente) {
            return response()->json(['id' => $existente->id, 'url' => $existente->url, 'ya_estaba' => true]);
        }
        if ($error = $this->limiteStickers($user, false)) {
            return response()->json(['error' => $error], 422);
        }

        // Misma imagen (no se vuelve a subir): se usa igual que los propios
        $sticker = ChatSticker::create(['user_id' => $user->id, 'is_team' => false, 'url' => $url]);
        return response()->json(['id' => $sticker->id, 'url' => $sticker->url], 201);
    }

    // Borrar: los míos, o los del equipo si soy el encargado
    public function borrarSticker(Request $request, $stickerId)
    {
        $user = $request->user();
        $sticker = ChatSticker::find((int) $stickerId);
        $puede = $sticker && (
            (!$sticker->is_team && (int) $sticker->user_id === (int) $user->id)
            || ($sticker->is_team && $user->can_manage_stickers && $this->stickersEquipo($user)->whereKey($sticker->id)->exists())
        );
        if (!$puede) {
            return response()->json(['error' => 'Sticker no encontrado.'], 404);
        }

        $sticker->delete();
        return response()->json(['success' => true]);
    }

    // Stickers del equipo del usuario (tenant nulo y tenant 1 son Agente Solutions)
    private function stickersEquipo($user)
    {
        $q = ChatSticker::where('is_team', true);
        if (!empty($user->tenant_id) && (int) $user->tenant_id !== 1) {
            return $q->where('tenant_id', $user->tenant_id);
        }
        return $q->where(fn ($w) => $w->whereNull('tenant_id')->orWhere('tenant_id', 1));
    }

    private function limiteStickers($user, bool $delEquipo): ?string
    {
        if ($delEquipo) {
            return $this->stickersEquipo($user)->count() >= 300 ? 'El paquete del equipo llegó a 300 stickers. Borra alguno para agregar otro.' : null;
        }
        return ChatSticker::where('user_id', $user->id)->where('is_team', false)->count() >= 100
            ? 'Llegaste al máximo de 100 stickers. Borra alguno para agregar otro.'
            : null;
    }

    // Preferencias del chat del usuario (por ahora: el fondo)
    public function preferencias(Request $request)
    {
        return response()->json(['fondo' => $request->user()->chat_wallpaper ?: 'default']);
    }

    // Fondo del chat: uno predefinido (fondo=noche...) o una imagen subida (imagen=archivo)
    public function cambiarFondo(Request $request)
    {
        $user = $request->user();
        $validated = $this->validar($request, [
            'fondo' => 'nullable|string|in:' . implode(',', self::FONDOS),
            'imagen' => 'nullable|file|mimes:jpeg,png,jpg,webp|max:8192',
        ], [
            'imagen.max' => 'La imagen de fondo debe pesar menos de 8 MB.',
        ]);
        if ($validated instanceof \Illuminate\Http\JsonResponse) return $validated;

        if ($request->hasFile('imagen')) {
            try {
                $fondo = $this->subir($request->file('imagen')->getRealPath(), 'image', 'agente_chat_fondos');
            } catch (\Throwable $e) {
                Log::error('Error subiendo fondo de chat: ' . $e->getMessage());
                return response()->json(['error' => 'No se pudo subir la imagen.'], 500);
            }
        } else {
            $fondo = $validated['fondo'] ?? 'default';
        }

        DB::table('users')->where('id', $user->id)->update(['chat_wallpaper' => $fondo === 'default' ? null : $fondo]);
        return response()->json(['fondo' => $fondo]);
    }

    // ── Ayudantes ──

    private function validar(Request $request, array $reglas, array $mensajes = [])
    {
        try {
            return $request->validate($reglas, $mensajes);
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

    // Sube a Cloudinary (imagen o video) y devuelve la URL segura
    private function subir(string $ruta, string $tipo, string $carpeta): string
    {
        $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
        return $cloudinary->uploadApi()->upload($ruta, ['folder' => $carpeta, 'resource_type' => $tipo])['secure_url'];
    }

    // "En línea": se actualiza como máximo cada 30 s (sin tocar updated_at)
    private function tocarPresencia($user): void
    {
        if (!$user->chat_last_seen_at || $user->chat_last_seen_at->lt(now()->subSeconds(30))) {
            DB::table('users')->where('id', $user->id)->update(['chat_last_seen_at' => now()]);
        }
    }

    private function presencia($u): array
    {
        $visto = $u?->chat_last_seen_at;
        return [
            'en_linea' => (bool) ($visto && $visto->gte(now()->subSeconds(self::EN_LINEA_SEGUNDOS))),
            'ultima_vez' => $visto?->toIso8601String(),
        ];
    }

    // Mensajes que este participante puede ver (desde que entró o desde que "eliminó el chat")
    private function visibles(ChatParticipant $p)
    {
        $desde = max((int) $p->from_message_id, (int) $p->cleared_until_message_id);
        $q = ChatMessage::where('chat_id', $p->chat_id)->where('id', '>', $desde);
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
        $chat->loadMissing('participants.user:id,first_name,last_name,profile_picture,role_id,chat_last_seen_at');
        $otro = $chat->is_group ? null : $chat->participants->first(fn ($x) => (int) $x->user_id !== (int) $user->id)?->user;

        return [
            'id' => $chat->id,
            'es_grupo' => (bool) $chat->is_group,
            'titulo' => $this->tituloChat($chat, $user),
            'nombre_grupo' => $chat->title,
            'foto' => $otro?->profile_picture,
            'otro_usuario_id' => $otro?->id,
            // Chats de 2: "En línea" / "últ. vez" de la otra persona
            'presencia' => $otro ? $this->presencia($otro) : null,
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
        $chat->loadMissing('participants.user:id,first_name,last_name,profile_picture,role_id,chat_last_seen_at');
        $autor = $m->user_id ? $chat->participants->firstWhere('user_id', $m->user_id)?->user : null;

        $eliminado = !is_null($m->deleted_at);
        return [
            'id' => $m->id,
            'tipo' => $eliminado ? 'deleted' : $m->type,
            'texto' => $eliminado ? null : $m->body,
            // imagen / video / sticker comparten la URL del archivo
            'imagen' => !$eliminado && in_array($m->type, ['image', 'sticker'], true) ? $m->attachment_url : null,
            'video' => !$eliminado && $m->type === 'video' ? $m->attachment_url : null,
            'autor_id' => $m->user_id ? (int) $m->user_id : null,
            'autor' => $autor ? $this->nombre($autor) : null,
            'autor_foto' => $autor?->profile_picture,
            'fecha' => $m->created_at?->toIso8601String(),
        ];
    }
}
