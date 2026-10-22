<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Llegada / salida de una persona a una propiedad registrada (detectada por GPS), para la campanita.
 * La push se envía en lote desde PlaceVisitService con App\Services\OneSignalPush.
 */
class PlaceVisitNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected $visit,
        protected string $title,
        protected string $message,
        protected string $tipo // 'llegada' | 'salida'
    ) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function pushTitle(): string
    {
        return $this->title;
    }

    public function pushBody(): string
    {
        return $this->message;
    }

    public function toDatabase($notifiable)
    {
        return [
            'titulo' => $this->title,
            'title' => $this->title,
            'mensaje' => $this->message,
            'message' => $this->message,
            'user_id' => $this->visit->user_id,
            'property_id' => $this->visit->property_id,
            'tipo' => $this->tipo,
            'alert_type' => 'place_visit',
            'url' => '/mapa-equipo',
        ];
    }
}
