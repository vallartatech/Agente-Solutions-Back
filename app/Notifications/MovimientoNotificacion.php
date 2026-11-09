<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso genérico para la campanita (solo base de datos).
 * La push se envía aparte con App\Services\OneSignalPush, como el resto del sistema.
 *
 * Datos: titulo, mensaje, url y, opcionales, alert_type y extra (datos adicionales).
 */
class MovimientoNotificacion extends Notification
{
    use Queueable;

    protected $datosMovimiento;

    public function __construct($datosMovimiento)
    {
        $this->datosMovimiento = $datosMovimiento;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    // La campanita lee title, message, url y alert_type
    public function toArray($notifiable)
    {
        $d = $this->datosMovimiento;

        return array_merge($d['extra'] ?? [], [
            'alert_type' => $d['alert_type'] ?? 'movimiento',
            'title' => $d['titulo'] ?? 'Nuevo movimiento',
            'message' => $d['mensaje'] ?? '',
            'url' => $d['url'] ?? null,
        ]);
    }
}
