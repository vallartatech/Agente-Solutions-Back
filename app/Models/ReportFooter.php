<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Pie de página guardado para el reporte oficial (empresa, teléfono, email y leyenda)
class ReportFooter extends Model
{
    protected $fillable = [
        'empresa',
        'telefono',
        'email',
        'leyenda',
        'tenant_id',
        'created_by',
    ];
}
