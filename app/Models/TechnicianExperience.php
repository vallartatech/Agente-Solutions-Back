<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Empleo anterior del currículum de un técnico de la red. hasta_anio null = trabajo actual. */
class TechnicianExperience extends Model
{
    protected $fillable = ['user_id', 'empresa', 'puesto', 'desde_anio', 'hasta_anio', 'descripcion'];

    protected $casts = ['desde_anio' => 'integer', 'hasta_anio' => 'integer'];
}
