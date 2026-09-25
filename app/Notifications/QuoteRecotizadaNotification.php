<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QuoteRecotizadaNotification extends Notification
{
    use Queueable;

    protected $quote;

    public function __construct($quote)
    {
        $this->quote = $quote;
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
            'alert_type'    => 'recotizacion_lista',
            'type'          => 'recotizacion_lista',
            'title'         => "✅ ¡Tu cotización ha sido actualizada!",
            'titulo'        => "¡Tu cotización ha sido actualizada!",
            'message'       => "El administrador ha actualizado los costos de tu cotización {$folio}. Cuenta con 15 días nuevos de vigencia y está lista por pagar.",
            'mensaje'       => "El administrador ha actualizado los costos de tu cotización {$folio}. Cuenta con 15 días nuevos de vigencia y está lista por pagar.",
            'url'           => "/vista-cotizaciones?quoteId={$this->quote->id}&filtro=Por Pagar",
            'created_at'    => now()->toIso8601String(),
        ];
    }
}