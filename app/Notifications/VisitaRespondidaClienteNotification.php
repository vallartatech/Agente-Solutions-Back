<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso al técnico de la red cuando el cliente responde el horario de visita que propuso:
 * lo confirma o pide otro horario (con su nota).
 */
class VisitaRespondidaClienteNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected $workOrder,
        protected string $clientName,
        protected bool $confirmado,
        protected ?string $nota = null
    ) {}

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        $wo = $this->workOrder;
        $trabajo = $wo->type . ($wo->equipment ? ' - ' . $wo->equipment : '');

        if ($this->confirmado) {
            $titulo = '✅ Visita confirmada';
            $mensaje = "{$this->clientName} confirmó el horario de visita de: {$trabajo} (folio #{$wo->id}).";
        } else {
            $titulo = '🗓️ El cliente pidió otro horario';
            $mensaje = "{$this->clientName} pidió cambiar la visita de: {$trabajo} (folio #{$wo->id})."
                . ($this->nota ? " Nota: \"{$this->nota}\"" : '');
        }

        return [
            'work_order_id' => $wo->id,
            'alert_type' => $this->confirmado ? 'visit_confirmed' : 'visit_reschedule_requested',
            'title' => $titulo,
            'message' => $mensaje,
            'url' => '/mercado-trabajos?trabajo=' . $wo->id,
        ];
    }
}
