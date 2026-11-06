<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Curso o certificación del currículum de un técnico de la red. El diploma es privado. */
class TechnicianCourse extends Model
{
    protected $fillable = ['user_id', 'nombre', 'institucion', 'anio', 'archivo_path'];

    protected $hidden = ['archivo_path'];

    protected $appends = ['tiene_archivo'];

    protected $casts = ['anio' => 'integer'];

    public function getTieneArchivoAttribute(): bool
    {
        return !empty($this->archivo_path);
    }
}
