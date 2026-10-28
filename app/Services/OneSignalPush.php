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
}
