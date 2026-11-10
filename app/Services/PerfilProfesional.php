<?php

namespace App\Services;

use App\Models\TechnicianProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reglas del perfil profesional de los técnicos de la red (roles 6 y 8).
 *
 * - Un técnico nuevo (perfil obligatorio) no puede usar su cuenta hasta que Admin/Root lo apruebe.
 *   Después de la primera aprobación ya no se bloquea, aunque vuelva a revisión por un cambio.
 * - Los técnicos que ya existían (obligatorio = false) nunca se bloquean.
 */
class PerfilProfesional
{
    public const ROLES_RED = [6, 8];
    public const ROLES_REVISORES = [0, 1];

    public const NIVELES_ESTUDIOS = [
        'sin_estudios' => 'Sin estudios formales',
        'primaria' => 'Primaria',
        'secundaria' => 'Secundaria',
        'preparatoria' => 'Preparatoria / Bachillerato',
        'tecnico' => 'Carrera técnica',
        'licenciatura' => 'Licenciatura / Ingeniería',
        'posgrado' => 'Posgrado',
    ];

    // Estados de una orden en los que el técnico ya fue contratado (para ver su RFC y CLABE)
    public const ESTADOS_CONTRATADO = ['Asignado', 'En Progreso', 'Por aprobar', 'Terminado', 'Listo', 'Finalizado', 'Completado'];

    public static function esTecnicoRed(?User $user): bool
    {
        return $user && in_array((int) $user->role_id, self::ROLES_RED, true);
    }

    public static function esRevisor(?User $user): bool
    {
        return $user && in_array((int) $user->role_id, self::ROLES_REVISORES, true);
    }

    /**
     * Si el código se sube antes de correr la migración, el candado no actúa
     * (en vez de responder error 500 a todos los técnicos de la red).
     */
    private static ?bool $tablaLista = null;

    public static function tablaLista(): bool
    {
        // Solo se recuerda cuando ya existe; mientras no exista se vuelve a revisar
        if (self::$tablaLista) {
            return true;
        }
        return self::$tablaLista = \Illuminate\Support\Facades\Schema::hasTable('technician_profiles');
    }

    public static function perfilDe(User $user): ?TechnicianProfile
    {
        return TechnicianProfile::where('user_id', $user->id)->first();
    }

    /** Perfil del técnico; si no existe, lo crea como obligatorio (técnico nuevo). */
    public static function perfilOCrear(User $user): TechnicianProfile
    {
        return TechnicianProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['obligatorio' => true, 'estado' => 'incompleto']
        );
    }

    /** ¿Debe quedarse en "Completa tu perfil" hasta que lo aprueben? */
    public static function estaBloqueado(?User $user): bool
    {
        if (!self::esTecnicoRed($user) || !self::tablaLista()) {
            return false;
        }
        $perfil = self::perfilDe($user);
        if (!$perfil) {
            return true; // técnico nuevo que aún no empieza
        }
        return $perfil->obligatorio && !$perfil->aprobado_at;
    }

    /**
     * CLABE interbancaria: 18 dígitos y dígito verificador oficial
     * (ponderaciones 3, 7, 1 sobre los primeros 17 dígitos, módulo 10).
     */
    public static function clabeValida(?string $clabe): bool
    {
        if (!$clabe || !preg_match('/^\d{18}$/', $clabe)) {
            return false;
        }
        $pesos = [3, 7, 1];
        $suma = 0;
        for ($i = 0; $i < 17; $i++) {
            $suma += ((int) $clabe[$i] * $pesos[$i % 3]) % 10;
        }
        $verificador = (10 - ($suma % 10)) % 10;
        return $verificador === (int) $clabe[17];
    }

    /** RFC de persona física (13) o moral (12), con homoclave. */
    public static function rfcValido(?string $rfc): bool
    {
        return (bool) preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', (string) $rfc);
    }

    /**
     * Lo que le falta para poder enviarlo a revisión: [clave => texto].
     * Cursos y empleos anteriores son opcionales.
     */
    /** Mayor de edad y fecha razonable (1930 en adelante). */
    public static function fechaNacimientoValida($fecha): bool
    {
        if (!$fecha) return false;
        try {
            $f = \Illuminate\Support\Carbon::parse($fecha);
        } catch (\Throwable $e) {
            return false;
        }
        return $f->year >= 1930 && $f->lte(now()->subYears(18));
    }

    public static function edad($fecha): ?int
    {
        if (!$fecha) return null;
        try {
            return \Illuminate\Support\Carbon::parse($fecha)->age;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function faltantes(TechnicianProfile $perfil, User $user): array
    {
        $f = [];
        if (!$perfil->consentimiento_datos_at) $f['consentimiento_datos'] = 'Consentimiento para datos bancarios y de identificación';
        if ($perfil->emite_factura === null) $f['emite_factura'] = '¿Emites factura?';
        if ($perfil->emite_factura) {
            if (!self::rfcValido($perfil->rfc)) $f['rfc'] = 'RFC';
            if (!trim((string) $perfil->razon_social)) $f['razon_social'] = 'Razón social';
        }
        if (!trim((string) $perfil->banco)) $f['banco'] = 'Banco';
        if (!trim((string) $perfil->titular_cuenta)) $f['titular_cuenta'] = 'Titular de la cuenta';
        if (!self::clabeValida($perfil->clabe)) $f['clabe'] = 'CLABE interbancaria';
        if ($perfil->acepta_efectivo === null) $f['acepta_efectivo'] = '¿Aceptas efectivo?';
        if ($perfil->tiene_terminal === null) $f['tiene_terminal'] = '¿Tienes terminal para tarjeta?';
        if ($user->specialties()->count() === 0) $f['especialidades'] = 'Al menos una habilidad';
        if ($perfil->anios_experiencia === null) $f['anios_experiencia'] = 'Años de experiencia';
        if (!array_key_exists((string) $perfil->nivel_estudios, self::NIVELES_ESTUDIOS)) $f['nivel_estudios'] = 'Nivel de estudios';
        if (mb_strlen(trim((string) $perfil->presentacion)) < 20) $f['presentacion'] = 'Presentación (mínimo 20 caracteres)';
        if (!preg_match('#^https?://#i', (string) $user->profile_picture)) $f['foto'] = 'Foto de perfil';
        if (!self::fechaNacimientoValida($user->birth_date ?? null)) $f['fecha_nacimiento'] = 'Fecha de nacimiento';
        if (!$perfil->ine_frente_path) $f['ine_frente'] = 'INE (frente)';
        if (!$perfil->ine_reverso_path) $f['ine_reverso'] = 'INE (reverso)';
        if (!$perfil->comprobante_domicilio_path) $f['comprobante_domicilio'] = 'Comprobante de domicilio';
        if (!$perfil->antecedentes_penales_path) $f['antecedentes_penales'] = 'Antecedentes no penales';
        return $f;
    }

    /**
     * Un cambio en datos que Admin/Root verificó (INE o estudios) quita la insignia y,
     * si el perfil ya estaba aprobado, lo regresa a revisión. No bloquea la cuenta.
     */
    public static function marcarCambio(TechnicianProfile $perfil, string $que): void
    {
        if ($que === 'identidad') {
            $perfil->identidad_verificada_at = null;
        } elseif ($que === 'estudios') {
            $perfil->estudios_verificados_at = null;
        }
        if ($perfil->estado === 'aprobado') {
            $perfil->estado = 'en_revision';
            $perfil->enviado_at = now();
            $perfil->save();
            self::avisarRevisores($perfil->user, true);
            return;
        }
        $perfil->save();
    }

    /** ¿Este usuario ya contrató al técnico? (tiene una orden asignada a él) */
    public static function loContrato(User $viewer, int $tecnicoId): bool
    {
        // Consulta directa (sin los scopes de tenant): la orden es del cliente si la propiedad es suya
        // o si la orden/propiedad es de su empresa
        return DB::table('work_orders as wo')
            ->leftJoin('properties as p', 'p.id', '=', 'wo.property_id')
            ->leftJoin('clients as c', 'c.id', '=', 'p.client_id')
            ->where('wo.tecnico_id', $tecnicoId)
            ->whereIn('wo.status', self::ESTADOS_CONTRATADO)
            ->where(function ($q) use ($viewer) {
                $q->where('c.user_id', $viewer->id);
                if (!empty($viewer->tenant_id)) {
                    $q->orWhere('wo.tenant_id', $viewer->tenant_id)
                        ->orWhere('p.tenant_id', $viewer->tenant_id);
                }
            })
            ->exists();
    }

    /**
     * Aviso de "perfil por revisar": solo a los Root (rol 0). Los Admin (rol 1) pueden validar
     * desde la lista, pero no reciben la notificación. Un fallo de push no detiene el flujo.
     */
    public static function avisarRevisores(?User $tecnico, bool $esCambio = false): void
    {
        if (!$tecnico) {
            return;
        }
        $revisores = User::withoutGlobalScopes()
            ->where('role_id', 0)
            ->where('is_active', 1)
            ->get();
        $nombre = trim("{$tecnico->first_name} {$tecnico->last_name}") ?: 'Un técnico';

        self::notificar(
            $revisores,
            $esCambio ? '🛡️ Técnico actualizó su perfil' : '🛡️ Técnico por validar',
            $esCambio
                ? "{$nombre} cambió datos verificados de su perfil. Revísalos."
                : "{$nombre} envió su perfil profesional para revisión.",
            '/sala-espera-tecnicos?validar=' . $tecnico->id,
            ['tecnico_id' => $tecnico->id]
        );
    }

    /** Aviso al técnico del resultado de la revisión. */
    public static function avisarTecnico(User $tecnico, bool $aprobado, ?string $motivo = null): void
    {
        self::notificar(
            collect([$tecnico]),
            $aprobado ? '✅ ¡Perfil aprobado!' : '⚠️ Revisa tu perfil profesional',
            $aprobado
                ? 'Tu perfil fue validado. Ya puedes usar tu cuenta y cotizar en la red.'
                : 'Tu perfil necesita correcciones: ' . $motivo,
            $aprobado ? '/mercado-trabajos' : '/completar-perfil',
            ['tecnico_id' => $tecnico->id, 'aprobado' => $aprobado]
        );
    }

    /**
     * Campanita (BD) + push (OneSignal por external_id). Cada parte falla por separado
     * sin detener el flujo de revisión.
     */
    private static function notificar($usuarios, string $titulo, string $mensaje, string $url, array $extra = []): void
    {
        if ($usuarios->isEmpty()) {
            return;
        }

        foreach ($usuarios as $usuario) {
            try {
                $usuario->notify(new \App\Notifications\MovimientoNotificacion([
                    'alert_type' => 'perfil_profesional',
                    'titulo' => $titulo,
                    'mensaje' => $mensaje,
                    'url' => $url,
                    'extra' => $extra,
                ]));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $push = OneSignalPush::sendToUsers(
            $usuarios->pluck('id')->all(),
            $titulo,
            $mensaje,
            $url,
            array_merge(['alert_type' => 'perfil_profesional'], $extra)
        );
        if (!$push['sent']) {
            \Illuminate\Support\Facades\Log::warning('Push de perfil profesional no enviada', $push);
        }
    }
}
