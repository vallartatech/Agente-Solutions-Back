<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// QR de acceso a una propiedad para un trabajo. Ver App\Services\AccesoQr.
class AccessQr extends Model
{
    protected $table = 'access_qrs';

    protected $fillable = [
        'tenant_id',
        'property_id',
        'work_order_id',
        'service_id',
        'batch_id',
        'slot',
        'label',
        'note',
        'valid_until',
        'mime',
        'image',
        'uploaded_by',
    ];

    // La imagen nunca sale en un JSON: solo se entrega por el endpoint autenticado de imagen
    protected $hidden = ['image'];

    protected $casts = [
        'slot' => 'integer',
        'valid_until' => 'datetime',
    ];

    public function vencido(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }
}
