<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TechnicianArrivedNotification extends Notification
{
    use Queueable;

    protected $technician;
    protected $item;
    protected $propertyName;
    protected $esRed;

    public function __construct($technician, $item, $propertyName, $esRed = false)
    {
        $this->technician = $technician;
        $this->item = $item;
        $this->propertyName = $propertyName;
        $this->esRed = (bool) $esRed;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        $techName = $this->technician ? trim($this->technician->first_name . ' ' . $this->technician->last_name) : 'El Técnico';
        $prop = $this->propertyName ?: 'la propiedad';
        $workId = $this->item->id;

        $datos = [
            'type' => 'technician_arrived',
            'title' => '📍 Técnico en el Lugar de Trabajo',
            'message' => "El Técnico {$techName} ha llegado al servicio de la propiedad {$prop} (Trabajo #{$workId}).",
            'work_order_id' => $workId,
            'technician_id' => $this->technician ? $this->technician->id : null,
            'arrived_at' => now()->toDateTimeString()
        ];

        // Red: el cliente abre el detalle del trabajo (ahí confirma la llegada)
        if ($this->esRed) {
            $datos['es_red'] = true;
            $datos['url'] = '/red-autonomos?trabajo=' . $workId;
        }

        return $datos;
    }
}
