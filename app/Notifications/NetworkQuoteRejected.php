<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\NetworkQuote;

class NetworkQuoteRejected extends Notification
{
    use Queueable;

    public $quote;

    public function __construct(NetworkQuote $quote)
    {
        $this->quote = $quote;
    }

    public function via($notifiable)
    {
        return ['database']; 
    }

    public function toDatabase($notifiable)
    {
        return [
            'title' => 'Cotización Rechazada',
            'message' => !empty($this->quote->motivo_rechazo)
                ? 'Tu cotización fue rechazada. Motivo: ' . $this->quote->motivo_rechazo
                : 'Tu cotización fue rechazada. Por favor, edita tu propuesta y envíala de nuevo.',
            'type' => 'network_quote_rejected',
            'quote_id' => $this->quote->id,
            'work_order_id' => $this->quote->work_order_id,
            // Abre directo la edición de su cotización
            'url' => "/mercado-trabajos?trabajo={$this->quote->work_order_id}&editar=1"
        ];
    }
}
