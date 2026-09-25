<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QuoteRequoteRequestedNotification extends Notification
{
    use Queueable;

    protected $quote;
    protected $clientName;

    public function __construct($quote, $clientName = 'Cliente')
    {
        $this->quote = $quote;
        $this->clientName = $clientName;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        $baseId = $this->quote->parent_id ?? $this->quote->id;
        $folio = 'COT-' . str_pad($baseId, 3, '0', STR_PAD_LEFT);

        return [
            'quote_id'      => $this->quote->id,
            'cotizacion_id' => $this->quote->id,
            'alert_type'    => 'solicitud_recotizacion',
            'type'          => 'solicitud_recotizacion',
            'title'         => "🔄 Solicitud de Recotización - {$folio}",
            'titulo'        => "Solicitud de Recotización - {$folio}",
            'message'       => "El cliente {$this->clientName} solicitó recotizar el servicio ({$folio}) por encontrarse vencida (> 15 días).",
            'mensaje'       => "El cliente {$this->clientName} solicitó recotizar el servicio ({$folio}) por encontrarse vencida (> 15 días).",
            'url'           => "/vista-cotizaciones?quoteId={$this->quote->id}&filtro=Recotizaciones",
            'created_at'    => now()->toIso8601String(),
        ];
    }
}