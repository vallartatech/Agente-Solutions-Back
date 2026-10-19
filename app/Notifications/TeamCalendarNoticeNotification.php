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
        $icon = ($event->type ?? null) === 'importante' ? '🔴' : '📅';
        // "📅 Junta · Revisión mensual" (la etiqueta va primero si existe)
        $this->title = $title ?: ($icon . ' ' . (!empty($event->label) ? "{$event->label} · " : '') . $event->title);
        $this->message = $message ?: $this->buildMessage($event);
    }

    // "Viernes 16 de octubre · 09:00 a 11:00 · Detalles": el mismo formato de la vista previa del frontend
    protected function buildMessage($event): string
    {
        $fecha = \Carbon\Carbon::parse($event->event_date)->locale('es')->isoFormat('dddd D [de] MMMM');
        $texto = mb_strtoupper(mb_substr($fecha, 0, 1)) . mb_substr($fecha, 1);
        if ($event->start_time && $event->end_time) {
            $texto .= ' · ' . substr($event->start_time, 0, 5) . ' a ' . substr($event->end_time, 0, 5);
        } elseif ($event->start_time) {
            $texto .= ' a las ' . substr($event->start_time, 0, 5);
        }
        if ($event->description) {
            $texto .= ' · ' . $event->description;
        }
        // Seguimiento: "… · Sigue de: Visita a cliente"
        if ($event->parent_id && $event->parent) {
            $texto .= ' · Sigue de: ' . $event->parent->title;
        }
        return $texto;
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
