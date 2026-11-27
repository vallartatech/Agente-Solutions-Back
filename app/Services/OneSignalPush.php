<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OneSignalPush
{
    /**
     * Envía una notificación push a varios usuarios (por external_id = user id) en una sola petición.
     * Nunca lanza excepción: devuelve el resultado para poder informarlo al frontend.
     *
     * Con $sendAfter, OneSignal la guarda y la entrega a esa hora (alarmas programadas, sin cron).
     *
     * @return array{sent: bool, recipients: int, subscribed: int|null, id: string|null, error: string|null}
     */
    public static function sendToUsers(array $userIds, string $title, string $body, ?string $path = null, array $data = [], ?\DateTimeInterface $sendAfter = null): array
    {
        $userIds = array_values(array_unique(array_map('strval', $userIds)));
        $result = ['sent' => false, 'recipients' => count($userIds), 'subscribed' => null, 'id' => null, 'error' => null];

        $appId = config('services.onesignal.app_id');
        $apiKey = config('services.onesignal.rest_api_key');

        if (!$appId || !$apiKey) {
            $result['error'] = 'OneSignal no está configurado en el servidor.';
            return $result;
        }
        if (empty($userIds)) {
            $result['error'] = 'No hay destinatarios.';
            return $result;
        }

        $payload = [
            'app_id' => $appId,
            'target_channel' => 'push',
            'include_aliases' => ['external_id' => $userIds],
            'headings' => ['en' => $title, 'es' => $title],
            'contents' => ['en' => $body, 'es' => $body],
        ];

        // Íconos de la notificación web (viven en public/ del frontend):
        // badge = silueta blanca de la barra de estado; icon = logo a color a la derecha
        $frontend = rtrim(config('services.onesignal.frontend_url'), '/');
        $payload['chrome_web_badge'] = $frontend . '/push-badge.png';
        $payload['chrome_web_icon'] = $frontend . '/Logo_192.png';
        $payload['firefox_icon'] = $frontend . '/Logo_192.png';

        if ($path) {
            // Rutas del frontend ("/vista-cotizaciones?filtro=Por Pagar") o URL completas
            $url = preg_match('#^https?://#i', $path)
                ? $path
                : $frontend . '/' . ltrim($path, '/');
            $payload['url'] = str_replace(' ', '%20', $url);
        }
        if (!empty($data)) {
            $payload['data'] = $data;
        }
        if ($sendAfter) {
            // Formato que pide OneSignal: "2026-10-27 10:00:00 GMT-0600"
            $payload['send_after'] = $sendAfter->format('Y-m-d H:i:s \G\M\TO');
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Key ' . $apiKey])
                ->acceptJson()
                ->timeout(10)
                ->post('https://api.onesignal.com/notifications', $payload);

            $json = $response->json() ?? [];

            if (!$response->successful()) {
                $result['error'] = 'OneSignal respondió HTTP ' . $response->status() . ': ' . json_encode($json['errors'] ?? $json);
                Log::error('[OneSignal] ' . $result['error']);
                return $result;
            }

            // OneSignal responde 200 con id vacío cuando ningún destinatario tiene suscripción activa
            $errors = $json['errors'] ?? null;
            $invalid = is_array($errors) ? count($errors['invalid_aliases']['external_id'] ?? []) : 0;

            if (empty($json['id'])) {
                $result['subscribed'] = 0;
                $result['error'] = 'Ningún dispositivo del equipo está vinculado a notificaciones (cada persona debe tocar "Activar" en la campanita; si ya lo hizo, usar "Revisar mi dispositivo").';
                // Lo que respondió OneSignal, para distinguir "nadie suscrito" de "suscrito pero sin vincular al usuario"
                Log::info('[OneSignal] Sin suscriptores', ['users' => $userIds, 'respuesta' => $json]);
                return $result;
            }

            $result['sent'] = true;
            $result['id'] = $json['id'];
            $result['subscribed'] = count($userIds) - $invalid;
            return $result;
        } catch (\Throwable $e) {
            $result['error'] = 'No se pudo contactar a OneSignal: ' . $e->getMessage();
            Log::error('[OneSignal] ' . $result['error']);
            return $result;
        }
    }

    /**
     * Dispositivos con push vinculados a un usuario (external_id) en OneSignal.
     * Sirve para saber si "Activar" realmente dejó el dispositivo ligado a su cuenta.
     *
     * @return array{ok: bool, vinculado: bool, activos: int, dispositivos: array, error: string|null}
     */
    public static function estadoUsuario(string $externalId): array
    {
        $result = ['ok' => false, 'vinculado' => false, 'activos' => 0, 'dispositivos' => [], 'error' => null];

        $appId = config('services.onesignal.app_id');
        $apiKey = config('services.onesignal.rest_api_key');
        if (!$appId || !$apiKey) {
            $result['error'] = 'OneSignal no está configurado en el servidor.';
            return $result;
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Key ' . $apiKey])
                ->acceptJson()
                ->timeout(10)
                ->get('https://api.onesignal.com/apps/' . urlencode($appId) . '/users/by/external_id/' . urlencode($externalId));

            // 404: OneSignal no tiene ningún dispositivo ligado a este usuario
            if ($response->status() === 404) {
                $result['ok'] = true;
                return $result;
            }
            if (!$response->successful()) {
                $result['error'] = 'OneSignal respondió HTTP ' . $response->status();
                return $result;
            }

            $push = collect($response->json('subscriptions') ?? [])
                ->filter(fn ($s) => str_ends_with((string) ($s['type'] ?? ''), 'Push'));

            $result['ok'] = true;
            $result['vinculado'] = true;
            $result['activos'] = $push->filter(fn ($s) => !empty($s['enabled']))->count();
            $result['dispositivos'] = $push->map(fn ($s) => [
                'tipo' => $s['type'] ?? null,
                'activo' => !empty($s['enabled']),
                'equipo' => trim(($s['device_model'] ?? '') . ' ' . ($s['device_os'] ?? '')) ?: null,
            ])->values()->all();
            return $result;
        } catch (\Throwable $e) {
            $result['error'] = 'No se pudo contactar a OneSignal: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * Cancela una push programada que todavía no se envía (ej. el aviso se eliminó o se completó).
     * Nunca lanza excepción.
     */
    public static function cancel(?string $notificationId): bool
    {
        $appId = config('services.onesignal.app_id');
        $apiKey = config('services.onesignal.rest_api_key');
        if (!$notificationId || !$appId || !$apiKey) {
            return false;
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Key ' . $apiKey])
                ->acceptJson()
                ->timeout(10)
                ->delete('https://api.onesignal.com/notifications/' . urlencode($notificationId) . '?app_id=' . urlencode($appId));
            // 400/404 = ya se envió o ya no existe: no hay nada que cancelar
            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('[OneSignal] No se pudo cancelar la push programada: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Borra en OneSignal al usuario (external_id = user id) con todas sus suscripciones.
     * Devuelve 'borrado', 'no_existe', 'sin_configurar' o 'error'. Nunca lanza excepción.
     */
    public static function borrarUsuario(string $externalId): string
    {
        $appId = config('services.onesignal.app_id');
        $apiKey = config('services.onesignal.rest_api_key');
        if (!$appId || !$apiKey) {
            return 'sin_configurar';
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Key ' . $apiKey])
                ->acceptJson()
                ->timeout(10)
                ->delete('https://api.onesignal.com/apps/' . urlencode($appId) . '/users/by/external_id/' . urlencode($externalId));
            if ($response->status() === 404) return 'no_existe';
            return $response->successful() ? 'borrado' : 'error';
        } catch (\Throwable $e) {
            return 'error';
        }
    }

    /**
     * Da de alta el token APNs de la app de iPhone como suscripción push del usuario (external_id).
     * La app no lleva el SDK de OneSignal: el token llega del plugin de Capacitor y aquí se registra.
     * "Crear usuario" de OneSignal busca al usuario por el alias y, si ya existe, le agrega la suscripción
     * (si el token era de otro usuario, pasa a este). Nunca lanza excepción.
     *
     * @return array{ok: bool, subscription_id: string|null, error: string|null}
     */
    public static function registrarIOS(string $externalId, string $token): array
    {
        $result = ['ok' => false, 'subscription_id' => null, 'error' => null];

        $appId = config('services.onesignal.app_id');
        $apiKey = config('services.onesignal.rest_api_key');
        if (!$appId || !$apiKey) {
            $result['error'] = 'OneSignal no está configurado en el servidor.';
            return $result;
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Key ' . $apiKey])
                ->acceptJson()
                ->timeout(10)
                ->post('https://api.onesignal.com/apps/' . urlencode($appId) . '/users', [
                    'identity' => ['external_id' => $externalId],
                    'subscriptions' => [[
                        'type' => 'iOSPush',
                        'token' => $token,
                        'enabled' => true,
                        'device_os' => 'iOS',
                    ]],
                ]);

            if (!$response->successful()) {
                $result['error'] = 'OneSignal respondió HTTP ' . $response->status();
                Log::warning('[OneSignal] No se registró el iPhone del usuario ' . $externalId . ': ' . $response->body());
                return $result;
            }

            $suscripcion = collect($response->json('subscriptions') ?? [])
                ->first(fn ($s) => ($s['type'] ?? null) === 'iOSPush' && ($s['token'] ?? null) === $token);

            $result['ok'] = true;
            $result['subscription_id'] = $suscripcion['id'] ?? null;
            return $result;
        } catch (\Throwable $e) {
            $result['error'] = 'No se pudo contactar a OneSignal: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * Borra una suscripción push del usuario (al cerrar sesión en el iPhone), solo si es suya.
     * Nunca lanza excepción.
     */
    public static function bajaSuscripcion(string $externalId, string $subscriptionId): bool
    {
        $appId = config('services.onesignal.app_id');
        $apiKey = config('services.onesignal.rest_api_key');
        if (!$appId || !$apiKey) {
            return false;
        }

        try {
            $cliente = Http::withHeaders(['Authorization' => 'Key ' . $apiKey])->acceptJson()->timeout(10);

            // Solo se borra si la suscripción pertenece a este usuario
            $usuario = $cliente->get('https://api.onesignal.com/apps/' . urlencode($appId) . '/users/by/external_id/' . urlencode($externalId));
            $esSuya = $usuario->successful() && collect($usuario->json('subscriptions') ?? [])
                ->contains(fn ($s) => ($s['id'] ?? null) === $subscriptionId);
            if (!$esSuya) {
                return false;
            }

            return $cliente->delete('https://api.onesignal.com/apps/' . urlencode($appId) . '/subscriptions/' . urlencode($subscriptionId))
                ->successful();
        } catch (\Throwable $e) {
            Log::warning('[OneSignal] No se pudo dar de baja la suscripción: ' . $e->getMessage());
            return false;
        }
    }
}
