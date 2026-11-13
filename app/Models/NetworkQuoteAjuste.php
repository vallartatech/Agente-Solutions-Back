<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ajuste de precio de una cotización de la red ya aceptada.
 * Al aceptarlo, network_quotes.price pasa a ser el precio de este ajuste.
 */
class NetworkQuoteAjuste extends Model
{
    protected $table = 'network_quote_ajustes';

    protected $guarded = [];

    protected $casts = [
        'precio_anterior' => 'decimal:2',
        'precio' => 'decimal:2',
        'desglose' => 'array',
        'fotos' => 'array',
        'respondido_at' => 'datetime',
    ];

    public function quote()
    {
        return $this->belongsTo(NetworkQuote::class, 'network_quote_id');
    }
}
