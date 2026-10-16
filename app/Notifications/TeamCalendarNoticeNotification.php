<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso de calendario para la campanita (base de datos).
 * La notificación push se envía en lote desde CalendarEventController con App\Services\OneSignalPush.
 */
class TeamCalendarNoticeNotification extends Notification
{
    use Queueable;

    protected $event;
    protected $title;
    protected $message;

    public function __construct($event, $title = null, $message = null)
    {
        $this->event = $event;
        $this->title = $title ?: ("📅 " . $event->title);
        $this->message = $message ?: ("Fecha: " . $event->event_date . ($event->start_time ? (" a las " . $event->start_time) : '') . ($event->description ? (" - " . $event->description) : ''));
    }

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
            'event_id' => $this->event->id ?? null,
            'event_date' => $this->event->event_date ?? null,
            'type' => $this->event->type ?? 'aviso',
            'alert_type' => 'calendar_event',
            'url' => '/calendario'
        ];
    }
}
