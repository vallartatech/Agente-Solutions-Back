<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\TeamCalendarNoticeNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Str;

/**
 * Cada notificación que se guarda en la campanita (canal "database") se envía también
 * como push de OneSignal al mismo usuario, con su título, mensaje y enlace.
 *
 * El envío se hace después de responder la petición (afterResponse): no hace más lenta
 * la app ni necesita un worker de colas. Si OneSignal falla, la campanita no se afecta.
 *
 * Se registra en AppServiceProvider (no en app/Listeners, para evitar el autodescubrimiento
 * y que se registre dos veces).
 */
class PushForDatabaseNotifications
{
    // Notificaciones que ya envían su propio push (en lote) y no deben duplicarse
    private const SKIP = [
        TeamCalendarNoticeNotification::class,
    ];

    // Títulos para las notificaciones que no guardan uno
    private const DEFAULT_TITLES = [
        \App\Notifications\TechnicianQuoteSubmitted::class => 'Nueva cotización de técnico',
        \App\Notifications\TechnicianQuoteUpdated::class => 'Cotización actualizada',
    ];

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        $class = get_class($event->notification);
        if (in_array($class, self::SKIP, true) || !($event->notifiable instanceof User)) {
            return;
        }

        $data = $event->response->data ?? null;
        if (!is_array($data)) {
            return;
        }

        $title = $this->clean($data['title'] ?? $data['titulo'] ?? self::DEFAULT_TITLES[$class] ?? 'Agente Solutions', 80);
        $body = $this->clean($data['message'] ?? $data['mensaje'] ?? '', 180);
        $url = $data['url'] ?? null;
        $userId = $event->notifiable->id;
        $extra = array_filter([
            'notification_id' => $event->response->id ?? null,
            'alert_type' => $data['alert_type'] ?? $data['type'] ?? null,
        ]);

        dispatch(function () use ($userId, $title, $body, $url, $extra) {
            OneSignalPush::sendToUsers([$userId], $title, $body !== '' ? $body : $title, $url, $extra);
        })->afterResponse();
    }

    private function clean($text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        return Str::limit($text, $limit, '…');
    }
}
