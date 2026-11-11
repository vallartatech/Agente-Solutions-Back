<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso al técnico de la red cuando el dueño de la propiedad confirma que ya llegó:
 * desde ese momento puede comenzar el trabajo.
 */
class LlegadaConfirmadaClienteNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected $workOrder,
        protected string $clientName
    ) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        $wo = $this->workOrder;
        $trabajo = $wo->type . ($wo->equipment ? ' - ' . $wo->equipment : '');

        return [
            'work_order_id' => $wo->id,
            'alert_type' => 'arrival_confirmed_by_client',
            'title' => '✅ El cliente confirmó tu llegada',
            'message' => "{$this->clientName} confirmó que ya estás en el lugar para: {$trabajo} (folio #{$wo->id}). Ya puedes comenzar el trabajo.",
            'url' => '/mercado-trabajos?trabajo=' . $wo->id,
        ];
    }
}
