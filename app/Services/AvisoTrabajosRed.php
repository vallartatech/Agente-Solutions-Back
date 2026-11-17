<?php

namespace App\Services;

use App\Http\Controllers\MisClientesController;
use App\Models\ClientTechnicianRelation;
use App\Models\Specialty;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\MovimientoNotificacion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Red: "Hay un trabajo de Electricidad disponible. ¡Míralo ahora!"
 *
 * Cuando un trabajo queda disponible en la red (se publica o se reenvía), se avisa a los técnicos
 * que tienen su habilidad (si el cliente eligió "No estoy seguro", a todos). Para no mandar una
 * notificación por cada trabajo, cada técnico lleva un contador:
 *  - se le avisa si pasaron VENTANA_MIN desde su último aviso, o si ya juntó LOTE trabajos sin avisar;
 *  - si no, el trabajo se suma y entra en el siguiente aviso ("Hay 3 trabajos de tu especialidad…");
 *  - al abrir el mercado, el contador vuelve a cero (ya los vio).
 * No necesita el scheduler: todo se decide cuando llega un trabajo nuevo.
 */
class AvisoTrabajosRed
{
    public const LOTE = 3;
    public const VENTANA_MIN = 30;
    private const ROLES_TECNICO = [6, 8];

    private static ?bool $tablaLista = null;

    public static function tablaLista(): bool
    {
        if (self::$tablaLista) {
            return true;
        }
        return self::$tablaLista = Schema::hasTable('avisos_trabajos_red');
    }

    /** Un trabajo quedó disponible en la red. Nunca detiene el flujo: si falla, solo se registra. */
    public static function nuevoTrabajo(WorkOrder $orden, ?int $publicadoPor = null): void
    {
        try {
            if (!self::tablaLista()) return;
            // Solo trabajos abiertos a toda la red: el dirigido a un favorito ya avisa a ese técnico
            if (!$orden->publish_network || $orden->tecnico_id || $orden->tecnico_dirigido_id) return;

            $especialidadId = Schema::hasColumn('work_orders', 'specialty_id') ? $orden->specialty_id : null;
            $nombre = $especialidadId ? (string) Specialty::whereKey($especialidadId)->value('name') : '';

            foreach (self::destinatarios($orden, $especialidadId, $publicadoPor) as $tecnicoId) {
                self::sumar((int) $tecnicoId, $nombre);
            }
        } catch (\Throwable $e) {
            Log::warning("Aviso de trabajos de la red (orden {$orden->id}): " . $e->getMessage());
        }
    }

    /** El técnico abrió el mercado: los trabajos que llevaba sin avisar ya los vio. */
    public static function visto(?User $user): void
    {
        if (!$user || !in_array((int) $user->role_id, self::ROLES_TECNICO, true) || !self::tablaLista()) return;
        try {
            DB::table('avisos_trabajos_red')->where('user_id', $user->id)->where('pendientes', '>', 0)
                ->update(['pendientes' => 0, 'especialidades' => '[]', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning("Aviso de trabajos de la red (visto {$user->id}): " . $e->getMessage());
        }
    }

    /** Técnicos de la red activos con esa habilidad, sin perfil bloqueado y sin bloqueo con el cliente. */
    private static function destinatarios(WorkOrder $orden, ?int $especialidadId, ?int $publicadoPor): array
    {
        $ids = User::withoutGlobalScopes()
            ->whereIn('role_id', self::ROLES_TECNICO)
            ->where('is_active', 1)
            ->when($especialidadId, fn ($q) => $q->whereHas('specialties', fn ($s) => $s->where('specialties.id', $especialidadId)))
            ->pluck('id')->map(fn ($i) => (int) $i);

        $dueno = MisClientesController::duenoDeOrden((int) $orden->id);
        $ids = $ids->reject(fn ($i) => $i === $dueno || $i === $publicadoPor);

        // Bloqueados con este cliente (en cualquier sentido): no ven el trabajo, no se les avisa
        $tenant = ClientTechnicianRelation::tenantDeBloqueo($orden->tenant_id);
        if ($ids->isNotEmpty() && Schema::hasTable('client_technician_relations')) {
            $bloqueados = ClientTechnicianRelation::where('status', ClientTechnicianRelation::BLOQUEADO)
                ->where(function ($q) use ($dueno, $tenant) {
                    $q->whereRaw('1 = 0');
                    if ($dueno) $q->orWhere('client_user_id', $dueno);
                    if ($tenant) $q->orWhere('tenant_id', $tenant);
                })
                ->pluck('technician_id')->map(fn ($i) => (int) $i)->all();
            $ids = $ids->reject(fn ($i) => in_array($i, $bloqueados, true));
        }

        // Perfil profesional pendiente (técnico nuevo sin aprobar): aún no puede cotizar
        if ($ids->isNotEmpty() && PerfilProfesional::tablaLista()) {
            $perfiles = DB::table('technician_profiles')->whereIn('user_id', $ids->all())
                ->get(['user_id', 'obligatorio', 'aprobado_at'])->keyBy('user_id');
            $ids = $ids->filter(function ($i) use ($perfiles) {
                $p = $perfiles->get($i);
                return $p && !($p->obligatorio && !$p->aprobado_at);
            });
        }

        return $ids->values()->all();
    }

    /** Suma el trabajo al técnico y, si toca, le manda el aviso con todo lo acumulado. */
    private static function sumar(int $tecnicoId, string $especialidad): void
    {
        $enviar = DB::transaction(function () use ($tecnicoId, $especialidad) {
            $fila = DB::table('avisos_trabajos_red')->where('user_id', $tecnicoId)->lockForUpdate()->first();
            if (!$fila) {
                DB::table('avisos_trabajos_red')->insert([
                    'user_id' => $tecnicoId, 'pendientes' => 0, 'especialidades' => '[]',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $fila = DB::table('avisos_trabajos_red')->where('user_id', $tecnicoId)->lockForUpdate()->first();
            }

            $pendientes = (int) $fila->pendientes + 1;
            $nombres = json_decode($fila->especialidades ?: '[]', true) ?: [];
            if (!in_array($especialidad, $nombres, true)) $nombres[] = $especialidad;

            $toca = !$fila->ultimo_aviso_at
                || Carbon::parse($fila->ultimo_aviso_at)->lte(now()->subMinutes(self::VENTANA_MIN))
                || $pendientes >= self::LOTE;

            DB::table('avisos_trabajos_red')->where('user_id', $tecnicoId)->update($toca
                ? ['pendientes' => 0, 'especialidades' => '[]', 'ultimo_aviso_at' => now(), 'updated_at' => now()]
                : ['pendientes' => $pendientes, 'especialidades' => json_encode($nombres, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

            return $toca ? [$pendientes, $nombres] : null;
        });

        if (!$enviar) return;

        [$cantidad, $nombres] = $enviar;
        [$titulo, $mensaje] = self::texto($cantidad, $nombres);
        $tecnico = User::withoutGlobalScopes()->find($tecnicoId);
        // El push sale solo: PushForDatabaseNotifications lo envía por cada aviso de la campanita
        $tecnico?->notify(new MovimientoNotificacion([
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'url' => '/mercado-trabajos',
            'alert_type' => 'trabajos_disponibles',
            'extra' => ['cantidad' => $cantidad],
        ]));
    }

    /** [título, mensaje] según cuántos trabajos son y de qué habilidad. */
    public static function texto(int $cantidad, array $nombres): array
    {
        $conNombre = array_values(array_filter($nombres, fn ($n) => $n !== ''));
        $sinNombre = count($conNombre) < count($nombres);
        // Una sola habilidad en todo el aviso: se dice cuál
        $una = (count($conNombre) === 1 && !$sinNombre) ? $conNombre[0] : null;
        $de = $una ? "de {$una}" : ($conNombre ? 'de tu especialidad' : 'nuevos en la red');

        if ($cantidad <= 1) {
            return [
                '🔔 Trabajo disponible',
                $una ? "Hay un trabajo de {$una} disponible. ¡Míralo ahora!" : ($conNombre ? 'Hay un trabajo de tu especialidad disponible. ¡Míralo ahora!' : 'Hay un trabajo nuevo disponible en la red. ¡Míralo ahora!'),
            ];
        }
        return [
            '🔔 Trabajos de tu especialidad',
            $conNombre ? "Hay {$cantidad} trabajos {$de} disponibles. ¡Míralos ahora!" : "Hay {$cantidad} trabajos nuevos disponibles en la red. ¡Míralos ahora!",
        ];
    }
}
