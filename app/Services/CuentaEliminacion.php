<?php

namespace App\Services;

use App\Models\User;
use Cloudinary\Cloudinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Eliminación de cuentas (política de Google Play).
 *
 * 1. solicitar():  el titular pide eliminar su cuenta → "eliminación pendiente" 30 días, sin acceso.
 * 2. cancelar():   soporte la reactiva dentro de esos 30 días.
 * 3. anonimizar(): borra archivos y datos personales. La fila de users/clients NUNCA se borra
 *                  (las llaves foráneas en cascada se llevarían propiedades, órdenes y pagos):
 *                  se queda como "Usuario eliminado" para que órdenes, propiedades y pagos sigan ligados.
 *
 * Los pagos conservan solo lo fiscal (monto, fecha, estado, folio de MercadoPago).
 * El log guarda solo el ID interno y la fecha.
 */
class CuentaEliminacion
{
    public const DIAS_GRACIA = 30;
    public const PENDIENTE = 'pending_deletion';
    public const ANONIMIZADA = 'anonymized';
    public const MENSAJE_PENDIENTE = 'Tu cuenta se eliminará definitivamente en 30 días. Si fue un error, contacta a soporte.';
    public const NOMBRE = 'Usuario';
    public const APELLIDO = 'eliminado';

    private const DISCO_KYC = 'local';    // storage/app/private/kyc/{id}
    private const DISCO_PUBLICO = 'public';

    /** Roles con permisos de administración dentro de un tenant. */
    private const ROLES_ADMIN_TENANT = [1, 4, 5, 7];

    // ─────────────────────────── 1. Solicitud ───────────────────────────

    public static function solicitar(User $user): void
    {
        DB::transaction(function () use ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'approval_status' => self::PENDIENTE,
                'is_active' => 0,
                'deletion_requested_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('clients')->where('user_id', $user->id)->update(['is_active' => 0, 'updated_at' => now()]);

            // Cerrar todas las sesiones al instante
            $user->tokens()->delete();
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        $roots = User::withoutGlobalScopes()->where('role_id', 0)->get();
        if ($roots->isNotEmpty()) {
            Notification::send($roots, new \App\Notifications\UserAccountDeletedNotification(
                $user,
                self::tenantSeQuedaSinAdmin($user)
            ));
        }
    }

    /** ¿El tenant del usuario se queda sin ningún otro admin activo? (soporte debe atenderlo en los 30 días) */
    public static function tenantSeQuedaSinAdmin(User $user): bool
    {
        if (!$user->tenant_id || !in_array((int) $user->role_id, self::ROLES_ADMIN_TENANT, true)) {
            return false;
        }

        return !User::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $user->id)
            ->whereIn('role_id', self::ROLES_ADMIN_TENANT)
            ->where('is_active', 1)
            ->whereNotIn('approval_status', [self::PENDIENTE, self::ANONIMIZADA, 'deleted_by_user'])
            ->exists();
    }

    // ─────────────────────────── 2. Cancelación ───────────────────────────

    public static function cancelar(User $user): bool
    {
        if ($user->approval_status !== self::PENDIENTE) {
            return false;
        }

        DB::transaction(function () use ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'approval_status' => 'approved',
                'is_active' => 1,
                'deletion_requested_at' => null,
                'updated_at' => now(),
            ]);
            DB::table('clients')->where('user_id', $user->id)->update(['is_active' => 1, 'updated_at' => now()]);
        });

        return true;
    }

    // ─────────────────────────── 3. Anonimización ───────────────────────────

    /** IDs de cuentas con más de 30 días en eliminación pendiente. */
    public static function vencidas(): array
    {
        return DB::table('users')
            ->where('approval_status', self::PENDIENTE)
            ->whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<=', now()->subDays(self::DIAS_GRACIA))
            ->where('role_id', '!=', 0)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * Borra los datos personales de un usuario. Con $dryRun solo cuenta lo que haría.
     * Devuelve conteos (sin datos personales) para el reporte del comando.
     *
     * @throws \RuntimeException si el usuario no existe, es ROOT o ya estaba anonimizado
     */
    public static function anonimizar(int $userId, bool $dryRun = false): array
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) throw new \RuntimeException('no_existe');
        if ((int) $user->role_id === 0) throw new \RuntimeException('es_root');
        if ($user->approval_status === self::ANONIMIZADA) throw new \RuntimeException('ya_anonimizada');

        $clientes = DB::table('clients')->where('user_id', $userId)->get(['id', 'profile_picture']);
        $archivos = self::archivosDelUsuario($user, $clientes);

        $r = [
            'user_id' => $userId,
            'archivos_nube' => count($archivos['nube']),
            'archivos_disco' => count($archivos['disco']),
            'kyc' => $archivos['kyc'] ? 1 : 0,
            'filas' => [],
            'errores_archivos' => 0,
            'onesignal' => null,
        ];

        if ($dryRun) {
            $r['filas'] = self::contarFilas($user);
            return $r;
        }

        $r['filas'] = DB::transaction(fn () => self::borrarDatos($user, $clientes));

        // Después del commit: archivos y servicios externos (no se pueden deshacer)
        $r['errores_archivos'] = self::borrarArchivos($archivos);
        $r['onesignal'] = self::borrarRemoto()
            ? OneSignalPush::borrarUsuario((string) $userId)
            : 'omitido (ACCOUNT_PURGE_REMOTE_DELETE=false)';

        Log::info('[CuentaEliminacion] cuenta anonimizada', [
            'user_id' => $userId,
            'fecha' => now()->toDateTimeString(),
            'errores_archivos' => $r['errores_archivos'],
            'onesignal' => $r['onesignal'],
        ]);

        return $r;
    }

    /** Cliente (tabla clients) sin cuenta de acceso: se anonimiza la fila; sus propiedades y órdenes se conservan. */
    public static function anonimizarCliente(int $clientId): void
    {
        $c = DB::table('clients')->where('id', $clientId)->first();
        if (!$c) throw new \RuntimeException('no_existe');
        if ($c->user_id) {
            self::anonimizar((int) $c->user_id);
            return;
        }

        $foto = $c->profile_picture ?? null;
        DB::table('clients')->where('id', $clientId)->update(self::columnasExistentes('clients', [
            'name' => self::NOMBRE . ' ' . self::APELLIDO,
            'email' => "eliminado-c{$clientId}@eliminado.invalid",
            'phone' => null,
            'birth_date' => null,
            'profile_picture' => null,
            'address' => null,
            'is_active' => 0,
            'updated_at' => now(),
        ]));

        if ($foto) {
            $esNube = preg_match('#^https?://#i', $foto);
            self::borrarArchivos([
                'kyc' => null,
                'disco' => $esNube ? [] : [ltrim(preg_replace('#^/?storage/#', '', $foto), '/')],
                'nube' => $esNube && str_contains($foto, 'res.cloudinary.com') && !self::otroUsuarioUsa($foto, 0) ? [$foto] : [],
            ]);
        }

        Log::info('[CuentaEliminacion] cliente anonimizado', ['client_id' => $clientId, 'fecha' => now()->toDateTimeString()]);
    }

    // ─────────────────────────── Internos ───────────────────────────

    /** ¿Borrar también en servicios externos (Cloudinary, OneSignal)? ACCOUNT_PURGE_REMOTE_DELETE, por defecto true. */
    private static function borrarRemoto(): bool
    {
        return (bool) config('services.cuentas.purge_remote_delete', true);
    }

    private static function borrarDatos(object $user, $clientes): array
    {
        $id = (int) $user->id;
        $n = [];

        // Usuario: la fila se conserva como "Usuario eliminado"
        $n['users'] = DB::table('users')->where('id', $id)->update(self::columnasExistentes('users', [
            'first_name' => self::NOMBRE,
            'last_name' => self::APELLIDO,
            'email' => "eliminado-{$id}@eliminado.invalid",
            'phone_number' => null,
            'birth_date' => null,
            'profile_picture' => null,
            'cover_picture' => null,
            'password' => Hash::make(Str::random(64)),
            'remember_token' => null,
            'email_verified_at' => null,
            'billing_company' => null,
            'chat_wallpaper' => null,
            'location_consent_at' => null,
            'qr_codigo' => null,   // QR público del técnico (deja de resolver a su perfil)
            'is_active' => 0,
            'approval_status' => self::ANONIMIZADA,
            'anonymized_at' => now(),
            'updated_at' => now(),
        ]));

        foreach ($clientes as $c) {
            DB::table('clients')->where('id', $c->id)->update(self::columnasExistentes('clients', [
                'name' => self::NOMBRE . ' ' . self::APELLIDO,
                'email' => "eliminado-c{$c->id}@eliminado.invalid",
                'phone' => null,
                'birth_date' => null,
                'profile_picture' => null,
                'address' => null,
                'is_active' => 0,
                'updated_at' => now(),
            ]));
        }
        $n['clients'] = count($clientes);

        // Sesiones, tokens y notificaciones propias
        $n['tokens'] = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)->where('tokenable_id', $id)->delete();
        $n['sessions'] = self::borrarDonde('sessions', 'user_id', $id);
        $n['password_resets'] = Schema::hasTable('password_reset_tokens')
            ? DB::table('password_reset_tokens')->where('email', $user->email)->delete() : 0;
        $n['notificaciones'] = DB::table('notifications')
            ->where('notifiable_type', User::class)->where('notifiable_id', $id)->delete();
        $n['notificaciones_sobre_usuario'] = self::limpiarNotificacionesSobre($id);

        // Perfil profesional (RFC, CLABE, INE, currículum) y especialidades
        foreach (['technician_profiles', 'technician_courses', 'technician_experiences', 'technician_specialties'] as $t) {
            $n[$t] = self::borrarDonde($t, 'user_id', $id);
        }

        // Ubicaciones
        foreach (['technician_locations', 'user_locations', 'user_place_visits'] as $t) {
            $n[$t] = self::borrarDonde($t, 'user_id', $id);
        }
        $n['location_access_logs'] = Schema::hasTable('location_access_logs')
            ? DB::table('location_access_logs')->where('viewer_id', $id)->orWhere('viewed_user_id', $id)->delete() : 0;

        // Relaciones personales
        $n['client_technician_relations'] = Schema::hasTable('client_technician_relations')
            ? DB::table('client_technician_relations')->where('client_user_id', $id)->orWhere('technician_id', $id)->delete() : 0;
        $n['shared_technicians'] = self::borrarDonde('shared_technicians', 'technician_user_id', $id);

        // Mensajes y publicaciones
        $n['chat_stickers'] = Schema::hasTable('chat_stickers')
            ? DB::table('chat_stickers')->where('user_id', $id)->where('is_team', 0)->delete() : 0;
        $n['chat_messages'] = Schema::hasTable('chat_messages')
            ? DB::table('chat_messages')->where('user_id', $id)->update([
                'body' => null,
                'attachment_url' => null,
                'deleted_at' => DB::raw('COALESCE(deleted_at, NOW())'),
            ]) : 0;
        $n['chat_history'] = self::limpiarChatHistory('quotes', $id) + self::limpiarChatHistory('network_quotes', $id);
        $n['mensajes_cotizacion'] = self::anularDonde('network_quotes', 'technician_id', $id, 'message')
            + self::anularDonde('job_quotes', 'technician_id', $id, 'message');
        $n['comentarios_resenas'] = 0;
        foreach (['technician_reviews', 'client_reviews'] as $t) {
            if (Schema::hasTable($t)) {
                $n['comentarios_resenas'] += DB::table($t)
                    ->where(fn ($q) => $q->where('client_id', $id)->orWhere('technician_id', $id))
                    ->whereNotNull('comment')
                    ->update(['comment' => null]);
            }
        }
        $n['notas_calendario'] = self::anularDonde('calendar_events', 'completed_by', $id, 'completion_note');

        // Pagos: solo lo fiscal (monto, fecha, estado, folio)
        $n['pagos_minimizados'] = self::minimizarPagos((string) $user->email);

        // La empresa sigue existiendo, pero sin el correo/teléfono personal del titular
        $n['tenants'] = 0;
        if ($user->email) {
            $n['tenants'] += DB::table('tenants')->where('email', $user->email)->update(['email' => null]);
        }
        if ($user->phone_number) {
            $n['tenants'] += DB::table('tenants')->where('phone', $user->phone_number)->update(['phone' => null]);
        }

        return $n;
    }

    private static function contarFilas(object $user): array
    {
        $id = (int) $user->id;
        $contar = fn (string $t, string $col) => Schema::hasTable($t) ? DB::table($t)->where($col, $id)->count() : 0;

        return [
            'clients' => DB::table('clients')->where('user_id', $id)->count(),
            'technician_profiles' => $contar('technician_profiles', 'user_id'),
            'technician_courses' => $contar('technician_courses', 'user_id'),
            'technician_specialties' => $contar('technician_specialties', 'user_id'),
            'ubicaciones' => $contar('technician_locations', 'user_id') + $contar('user_locations', 'user_id') + $contar('user_place_visits', 'user_id'),
            'chat_messages' => $contar('chat_messages', 'user_id'),
            'ordenes_conservadas' => $contar('work_orders', 'tecnico_id'),
        ];
    }

    /** Archivos a borrar: Cloudinary (URL), disco público (ruta relativa) y la carpeta KYC privada. */
    private static function archivosDelUsuario(object $user, $clientes): array
    {
        $id = (int) $user->id;
        $urls = [$user->profile_picture ?? null, $user->cover_picture ?? null, $user->chat_wallpaper ?? null];
        foreach ($clientes as $c) $urls[] = $c->profile_picture;

        if (Schema::hasTable('chat_messages')) {
            $urls = array_merge($urls, DB::table('chat_messages')->where('user_id', $id)
                ->whereNotNull('attachment_url')->where('type', '!=', 'sticker')->pluck('attachment_url')->all());
        }
        if (Schema::hasTable('chat_stickers')) {
            $urls = array_merge($urls, DB::table('chat_stickers')->where('user_id', $id)->where('is_team', 0)->pluck('url')->all());
        }

        $nube = [];
        $disco = [];
        foreach (array_unique(array_filter($urls)) as $u) {
            if (preg_match('#^https?://#i', $u)) {
                // Solo si ningún otro usuario usa la misma imagen (ej. un avatar por defecto)
                if (str_contains($u, 'res.cloudinary.com') && !self::otroUsuarioUsa($u, $id)) $nube[] = $u;
            } elseif (!str_starts_with($u, 'kyc/')) {
                $disco[] = ltrim(preg_replace('#^/?storage/#', '', $u), '/');
            }
        }

        return [
            'nube' => $nube,
            'disco' => $disco,
            'kyc' => Storage::disk(self::DISCO_KYC)->exists("kyc/{$id}") ? "kyc/{$id}" : null,
        ];
    }

    private static function otroUsuarioUsa(string $url, int $id): bool
    {
        return DB::table('users')->where('id', '!=', $id)
                ->where(fn ($q) => $q->where('profile_picture', $url)->orWhere('cover_picture', $url))->exists()
            || DB::table('clients')->where('user_id', '!=', $id)->where('profile_picture', $url)->exists();
    }

    /** Devuelve cuántos archivos no se pudieron borrar. */
    private static function borrarArchivos(array $archivos): int
    {
        $errores = 0;

        if ($archivos['kyc']) {
            try {
                if (!Storage::disk(self::DISCO_KYC)->deleteDirectory($archivos['kyc'])) $errores++;
            } catch (\Throwable $e) {
                $errores++;
            }
        }

        foreach ($archivos['disco'] as $ruta) {
            try {
                Storage::disk(self::DISCO_PUBLICO)->delete($ruta);
            } catch (\Throwable $e) {
                $errores++;
            }
        }

        // Con ACCOUNT_PURGE_REMOTE_DELETE=false no se toca Cloudinary (no cuenta como error)
        if ($archivos['nube'] && self::borrarRemoto()) {
            $cloudUrl = env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url');
            if (!$cloudUrl) return $errores + count($archivos['nube']);

            $cloudinary = new Cloudinary($cloudUrl);
            foreach ($archivos['nube'] as $url) {
                $recurso = self::recursoCloudinary($url);
                if (!$recurso) {
                    $errores++;
                    continue;
                }
                try {
                    $res = $cloudinary->uploadApi()->destroy($recurso['public_id'], [
                        'resource_type' => $recurso['tipo'],
                        'invalidate' => true,
                    ]);
                    // 'not found' = ya no existía: cuenta como borrado
                    if (!in_array($res['result'] ?? null, ['ok', 'not found'], true)) $errores++;
                } catch (\Throwable $e) {
                    $errores++;
                }
            }
        }

        return $errores;
    }

    /** https://res.cloudinary.com/{cloud}/{image|video|raw}/upload/[transformaciones/][v123/]{public_id}.{ext} */
    public static function recursoCloudinary(string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path || !preg_match('#^/[^/]+/(image|video|raw)/upload/(.+)$#', $path, $m)) return null;

        $resto = $m[2];
        if (preg_match('#(?:^|/)v\d+/(.+)$#', $resto, $v)) $resto = $v[1];
        $resto = rawurldecode($resto);
        $publicId = $m[1] === 'raw' ? $resto : preg_replace('#\.[A-Za-z0-9]+$#', '', $resto);

        return $publicId ? ['tipo' => $m[1], 'public_id' => $publicId] : null;
    }

    /** Mensajes del usuario dentro de chat_history (JSON) de cotizaciones. */
    private static function limpiarChatHistory(string $tabla, int $id): int
    {
        if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, 'chat_history')) return 0;

        $cambiadas = 0;
        DB::table($tabla)->whereNotNull('chat_history')->where('chat_history', 'like', '%sender_id%')
            ->select(['id', 'chat_history'])
            ->chunkById(200, function ($filas) use ($tabla, $id, &$cambiadas) {
                foreach ($filas as $f) {
                    $historial = json_decode($f->chat_history, true);
                    if (!is_array($historial)) continue;
                    $cambio = false;
                    foreach ($historial as &$m) {
                        if (is_array($m) && (int) ($m['sender_id'] ?? 0) === $id) {
                            $m['sender_name'] = self::NOMBRE . ' ' . self::APELLIDO;
                            $m['message'] = '[Mensaje eliminado]';
                            foreach (['attachment', 'attachment_url', 'image', 'file'] as $k) unset($m[$k]);
                            $cambio = true;
                        }
                    }
                    unset($m);
                    if ($cambio) {
                        DB::table($tabla)->where('id', $f->id)->update(['chat_history' => json_encode($historial, JSON_UNESCAPED_UNICODE)]);
                        $cambiadas++;
                    }
                }
            });

        return $cambiadas;
    }

    /** Avisos a otros usuarios que mencionan al eliminado (ej. "El cliente X (correo) pidió…"). */
    private static function limpiarNotificacionesSobre(int $id): int
    {
        $cambiadas = 0;
        DB::table('notifications')
            ->where(fn ($q) => $q->where('data', 'like', "%\"user_id\":{$id},%")->orWhere('data', 'like', "%\"user_id\":{$id}}%"))
            ->select(['id', 'data'])
            ->chunkById(200, function ($filas) use ($id, &$cambiadas) {
                foreach ($filas as $f) {
                    $data = json_decode($f->data, true);
                    if (!is_array($data) || (int) ($data['user_id'] ?? 0) !== $id) continue;
                    DB::table('notifications')->where('id', $f->id)->update(['data' => json_encode([
                        'alert_type' => $data['alert_type'] ?? null,
                        'user_id' => $id,
                        'title' => $data['title'] ?? 'Cuenta eliminada',
                        'message' => "Datos de la cuenta #{$id} eliminados.",
                        'url' => $data['url'] ?? null,
                    ], JSON_UNESCAPED_UNICODE)]);
                    $cambiadas++;
                }
            });

        return $cambiadas;
    }

    /** Quita nombre del tarjetahabiente, correo y últimos 4 dígitos de los pagos de MercadoPago del usuario. */
    private static function minimizarPagos(string $email): int
    {
        if (!$email || !str_contains($email, '@')) return 0;

        $columnas = array_values(array_filter(
            ['mp_payment_data', 'advance_mp_data', 'remaining_mp_data'],
            fn ($c) => Schema::hasColumn('quotes', $c)
        ));
        if (!$columnas) return 0;

        $cambiadas = 0;
        $like = '%' . addcslashes(strtolower($email), '%_\\') . '%';
        DB::table('quotes')
            ->where(function ($q) use ($columnas, $like) {
                foreach ($columnas as $c) $q->orWhereRaw("LOWER({$c}) LIKE ?", [$like]);
            })
            ->select(array_merge(['id'], $columnas))
            ->chunkById(200, function ($filas) use ($columnas, $email, &$cambiadas) {
                foreach ($filas as $f) {
                    $cambios = [];
                    foreach ($columnas as $c) {
                        $d = json_decode((string) $f->{$c}, true);
                        if (!is_array($d) || strcasecmp((string) ($d['payer_email'] ?? ''), $email) !== 0) continue;
                        $cambios[$c] = json_encode(array_intersect_key($d, array_flip([
                            'mp_payment_id', 'status', 'amount', 'currency', 'payment_type', 'date_approved', 'date_created',
                        ])), JSON_UNESCAPED_UNICODE);
                    }
                    if ($cambios) {
                        DB::table('quotes')->where('id', $f->id)->update($cambios);
                        $cambiadas++;
                    }
                }
            });

        return $cambiadas;
    }

    private static function borrarDonde(string $tabla, string $col, int $id): int
    {
        return Schema::hasTable($tabla) ? DB::table($tabla)->where($col, $id)->delete() : 0;
    }

    private static function anularDonde(string $tabla, string $col, int $id, string $campo): int
    {
        if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, $campo)) return 0;
        return DB::table($tabla)->where($col, $id)->whereNotNull($campo)->update([$campo => null]);
    }

    /** Solo las columnas que existen (la base de producción puede ir una migración atrás). */
    private static function columnasExistentes(string $tabla, array $valores): array
    {
        $existentes = array_flip(Schema::getColumnListing($tabla));
        return array_intersect_key($valores, $existentes);
    }
}
