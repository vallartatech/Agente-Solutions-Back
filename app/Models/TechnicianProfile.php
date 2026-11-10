<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Perfil profesional de un técnico de la red (roles 6 y 8).
 * Ver App\Services\PerfilProfesional para las reglas de bloqueo y revisión.
 */
class TechnicianProfile extends Model
{
    public const ESTADOS = ['incompleto', 'en_revision', 'aprobado', 'rechazado'];

    protected $fillable = [
        'user_id', 'obligatorio', 'estado', 'motivo_rechazo', 'enviado_at', 'aprobado_at',
        'revisado_por', 'revisado_at', 'identidad_verificada_at', 'estudios_verificados_at',
        'emite_factura', 'rfc', 'razon_social', 'banco', 'titular_cuenta', 'clabe',
        'acepta_efectivo', 'tiene_terminal',
        'anios_experiencia', 'nivel_estudios', 'presentacion', 'sin_cursos', 'sin_empleos',
        'ine_frente_path', 'ine_reverso_path', 'comprobante_domicilio_path', 'antecedentes_penales_path',
        'consentimiento_datos_at',
    ];

    // Nunca se mandan las rutas internas de los archivos privados
    protected $hidden = ['ine_frente_path', 'ine_reverso_path', 'comprobante_domicilio_path', 'antecedentes_penales_path'];

    protected $casts = [
        'obligatorio' => 'boolean',
        'emite_factura' => 'boolean',
        'acepta_efectivo' => 'boolean',
        'tiene_terminal' => 'boolean',
        'sin_cursos' => 'boolean',
        'sin_empleos' => 'boolean',
        'anios_experiencia' => 'integer',
        'rfc' => 'encrypted',
        'clabe' => 'encrypted',
        'enviado_at' => 'datetime',
        'aprobado_at' => 'datetime',
        'revisado_at' => 'datetime',
        'identidad_verificada_at' => 'datetime',
        'estudios_verificados_at' => 'datetime',
        'consentimiento_datos_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    public function cursos()
    {
        return $this->hasMany(TechnicianCourse::class, 'user_id', 'user_id')->orderByDesc('anio');
    }

    public function experiencias()
    {
        return $this->hasMany(TechnicianExperience::class, 'user_id', 'user_id')->orderByDesc('desde_anio');
    }
}
