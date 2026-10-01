<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\DatabaseMessage;
use App\Models\NetworkQuote;

class NetworkQuoteReceived extends Notification
{
    use Queueable;

    public $quote;
    public $technicianName;
    public $propertyName;

    public function __construct(NetworkQuote $quote, $technicianName, $propertyName)
    {
        $this->quote = $quote;
        $this->technicianName = $technicianName;
        $this->propertyName = $propertyName;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'type' => 'network_quote_received',
            'alert_type' => 'network_quote_received',
            'title' => 'Nueva Cotización en la Red',
            'message' => "{$this->technicianName} te ha enviado una cotización de \${$this->quote->price} para tu trabajo en {$this->propertyName}.",
            'work_order_id' => $this->quote->work_order_id,
            'quote_id' => $this->quote->id,
            'url' => "/red-autonomos"
        ];
    }
}
