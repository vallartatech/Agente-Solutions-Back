<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkQuote extends Model
{
    protected $guarded = [];

    protected $casts = [
        'chat_history' => 'array',
        'price' => 'decimal:2',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    // El técnico de la red casi nunca es del tenant del cliente: sin quitar el TenantScope
    // la relación salía null y no se le notificaba (chat, aceptar/rechazar, horario).
    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id')->withoutGlobalScopes();
    }

    // Ajustes de precio propuestos después de aceptarla (v2, v3…)
    public function ajustes()
    {
        return $this->hasMany(NetworkQuoteAjuste::class, 'network_quote_id')->orderBy('version');
    }
}