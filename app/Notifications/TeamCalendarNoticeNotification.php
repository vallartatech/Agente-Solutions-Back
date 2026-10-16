<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\OneSignal\OneSignalChannel;
use NotificationChannels\OneSignal\OneSignalMessage;

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
        $channels = ['database'];
        if (config('services.onesignal.app_id') && config('services.onesignal.rest_api_key')) {
            $channels[] = OneSignalChannel::class;
        }
        return $channels;
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

    public function toOneSignal($notifiable)
    {
        return OneSignalMessage::create()
            ->setSubject($this->title)
            ->setBody($this->message)
            ->setUrl(url('/calendario'));
    }
}
