<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso al cliente de la Red cuando el técnico modifica el horario de visita ya agendado.
 * Es una modificación del trabajo, no un mensaje de chat: el cliente debe verlo claramente.
 */
class HorarioModificadoRedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected $workOrder,
        protected string $tecnicoName,
        protected $horarioAnterior = null,
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
        $nuevo = Carbon::parse($wo->scheduled_at)->format('d/m/Y h:i A');
        $antes = $this->horarioAnterior ? Carbon::parse($this->horarioAnterior)->format('d/m/Y h:i A') : null;

        return [
            'work_order_id' => $wo->id,
            'alert_type' => 'red_horario_modificado',
            'title' => 'Modificación del trabajo: cambio de horario',
            'message' => "{$this->tecnicoName} cambió el horario de la visita de: {$trabajo} (folio #{$wo->id})."
                . ($antes ? " Antes: {$antes}." : '') . " Ahora: {$nuevo}."
                . ($this->nota ? " Nota: \"{$this->nota}\"" : ''),
            'tecnico_name' => $this->tecnicoName,
            'trabajo' => $trabajo,
            'horario_anterior' => $this->horarioAnterior,
            'horario_nuevo' => $wo->scheduled_at,
            'nota' => $this->nota,
            'url' => '/red-autonomos?trabajo=' . $wo->id,
        ];
    }
}
