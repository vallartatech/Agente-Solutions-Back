<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class WorkOrderFinishedNotification extends Notification
{
    use Queueable;

    protected $workOrder;
    protected $technicianName;
    protected $propertyName;

    public function __construct($workOrder, $technicianName, $propertyName)
    {
        $this->workOrder = $workOrder;
        $this->technicianName = $technicianName;
        $this->propertyName = $propertyName;
    }

    public function via($notifiable)
    {
        // Enviar a base de datos para la campana y opcionalmente mail
        return ['database', 'mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Trabajo pendiente de aprobación')
            ->greeting('Hola Administrador,')
            ->line("El técnico **{$this->technicianName}** terminó el trabajo en **{$this->propertyName}** y está **pendiente de tu aprobación**.")
            ->line("Zona: **{$this->workOrder->zone}**")
            ->line("Descripción: {$this->workOrder->description}")
            ->action('Ver Tablero de Servicios', url('/tablero-servicios'))
            ->line('Gracias por usar Agente Solutions.');
    }

    public function toArray($notifiable)
    {
        return [
            'work_order_id' => $this->workOrder->id,
            'alert_type' => 'work_order_finished',
            'title' => 'Trabajo por aprobar',
            'message' => "{$this->technicianName} terminó el trabajo en {$this->propertyName}. Revísalo y apruébalo.",
            'url' => "/tablero-servicios"
        ];
    }
}
