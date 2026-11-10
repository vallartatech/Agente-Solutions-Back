<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Calificación que el técnico de la red le da al cliente al terminar un trabajo. */
class ClientReview extends Model
{
    protected $fillable = [
        'client_id',
        'technician_id',
        'work_order_id',
        'rating_trato',
        'rating_pago',
        'comment',
    ];

    protected $casts = [
        'rating_trato' => 'integer',
        'rating_pago' => 'integer',
    ];
}
