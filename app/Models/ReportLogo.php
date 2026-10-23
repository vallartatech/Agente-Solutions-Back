<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Galería de logos que se pueden poner en el encabezado del reporte oficial
class ReportLogo extends Model
{
    protected $fillable = [
        'url',
        'tenant_id',
        'uploaded_by',
    ];
}
