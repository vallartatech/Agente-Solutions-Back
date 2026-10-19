<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Avisos del equipo de levantamiento (agregado al equipo, levantamiento finalizado).
 * Se guarda en la campanita; el push sale automáticamente (PushForDatabaseNotifications).
 */
class SurveyTeamNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $title,
        protected string $message,
        protected int $propertyId,
        protected ?int $serviceId = null,
        protected string $url = '/trabajos-asignados'
    ) {
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'alert_type' => 'survey_team',
            'property_id' => $this->propertyId,
            'service_id' => $this->serviceId,
            'url' => $this->url,
        ];
    }
}
