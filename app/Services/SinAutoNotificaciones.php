<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * Nadie recibe una notificación de lo que él mismo hizo.
 *
 * Ej.: el cliente le escribe al técnico y a él también le llegaba "Pedro Pech te envió un mensaje".
 * Si el destinatario es el mismo usuario que hizo la petición, la notificación se cancela
 * (ni campanita ni push; los correos sí salen). Solo aplica con sesión: los avisos del
 * scheduler o de comandos no se tocan.
 *
 * Si alguna notificación sí debe llegarle al autor, que defina: public bool $paraElAutor = true;
 * Se registra en AppServiceProvider.
 */
class SinAutoNotificaciones
{
    public function handle(NotificationSending $event)
    {
        // Los correos (verificar cuenta, restablecer contraseña) sí son para el propio usuario
        if ($event->channel === 'mail') {
            return null;
        }
        if (!($event->notifiable instanceof User) || !empty($event->notification->paraElAutor)) {
            return null;
        }

        try {
            $autorId = auth('sanctum')->id() ?? auth()->id();
        } catch (\Throwable $e) {
            return null; // Sin contexto de sesión (consola, colas)
        }

        if ($autorId && (int) $autorId === (int) $event->notifiable->getKey()) {
            return false; // Cancela el envío en este canal
        }

        return null;
    }
}
