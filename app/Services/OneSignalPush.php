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
     * @return array{sent: bool, recipients: int, subscribed: int|null, id: string|null, error: string|null}
     */
    public static function sendToUsers(array $userIds, string $title, string $body, ?string $path = null, array $data = []): array
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
        if ($path) {
            // Rutas del frontend ("/vista-cotizaciones?filtro=Por Pagar") o URL completas
            $url = preg_match('#^https?://#i', $path)
                ? $path
                : rtrim(config('services.onesignal.frontend_url'), '/') . '/' . ltrim($path, '/');
            $payload['url'] = str_replace(' ', '%20', $url);
        }
        if (!empty($data)) {
            $payload['data'] = $data;
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
                $result['error'] = 'Ningún dispositivo del equipo está suscrito a notificaciones (deben tocar "Activar" en la campanita).';
                // Normal: el usuario aún no activa notificaciones en su dispositivo
                Log::info('[OneSignal] Sin suscriptores', ['users' => $userIds]);
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
}
