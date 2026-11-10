<?php

namespace App\Notifications;

use App\Services\CuentaEliminacion;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso a ROOT: un usuario pidió eliminar su cuenta (se anonimiza en 30 días).
 * Al anonimizarse, CuentaEliminacion reemplaza este texto por uno sin datos personales.
 */
class UserAccountDeletedNotification extends Notification
{
    use Queueable;

    protected $user;
    protected bool $tenantSinAdmin;

    public function __construct($user, bool $tenantSinAdmin = false)
    {
        $this->user = $user;
        $this->tenantSinAdmin = $tenantSinAdmin;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        $roleNombre = match((int)$this->user->role_id) {
            1 => 'Administrador',
            2 => 'Técnico',
            3 => 'Cliente',
            4 => 'Cliente de la red',
            5 => 'Agente inmobiliario',
            6 => 'Técnico de la red',
            7 => 'Encargado de cuadrilla',
            8 => 'Técnico de cuadrilla',
            default => 'Usuario'
        };

        $urlDestino = ((int)$this->user->role_id === 2) ? '/vista-tecnicos' : '/usuarios';
        $dias = CuentaEliminacion::DIAS_GRACIA;

        $mensaje = "El {$roleNombre} {$this->user->name} ({$this->user->email}) pidió eliminar su cuenta. "
            . "Sus datos se borrarán definitivamente en {$dias} días; puedes cancelarlo desde Usuarios.";
        if ($this->tenantSinAdmin) {
            $mensaje .= ' ATENCIÓN: su empresa se queda sin ningún otro administrador activo; soporte debe atenderla.';
        }

        return [
            'alert_type'       => 'user_account_deleted',
            'user_id'          => $this->user->id,
            'role_id'          => $this->user->role_id,
            'tenant_id'        => $this->user->tenant_id,
            'tenant_sin_admin' => $this->tenantSinAdmin,
            'title'            => $this->tenantSinAdmin ? '⚠️ Eliminación de cuenta: empresa sin admin' : '⚠️ Solicitud de eliminación de cuenta',
            'message'          => $mensaje,
            'url'              => $urlDestino
        ];
    }
}
