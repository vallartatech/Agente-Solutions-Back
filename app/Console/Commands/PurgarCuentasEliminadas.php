<?php

namespace App\Console\Commands;

use App\Services\CuentaEliminacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Anonimiza las cuentas con más de 30 días en "eliminación pendiente".
 *
 *   php artisan cuentas:purgar-eliminadas --dry-run   → solo muestra qué haría
 *   php artisan cuentas:purgar-eliminadas             → lo hace
 *   php artisan cuentas:purgar-eliminadas --user=123   → una cuenta específica (debe estar vencida)
 *
 * En Railway corre como servicio Cron diario (ver docs/eliminacion-cuentas.md).
 * La salida y el log solo llevan IDs internos y conteos: nunca datos personales.
 */
class PurgarCuentasEliminadas extends Command
{
    protected $signature = 'cuentas:purgar-eliminadas
                            {--dry-run : Solo muestra qué se borraría, sin cambiar nada}
                            {--user= : Procesar solo este ID de usuario (debe estar vencido)}
                            {--id= : Igual que --user}';

    protected $description = 'Borra los datos personales de las cuentas con más de 30 días en eliminación pendiente';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ids = CuentaEliminacion::vencidas();

        $soloId = $this->option('user') ?? $this->option('id');
        if ($soloId !== null) {
            $id = (int) $soloId;
            if (!in_array($id, $ids, true)) {
                $this->error("La cuenta #{$id} no está en eliminación pendiente con más de " . CuentaEliminacion::DIAS_GRACIA . ' días.');
                return self::FAILURE;
            }
            $ids = [$id];
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . 'Cuentas vencidas: ' . count($ids));

        $ok = 0;
        $fallidas = 0;
        foreach ($ids as $id) {
            try {
                $r = CuentaEliminacion::anonimizar($id, $dryRun);
                $ok++;
                $filas = collect($r['filas'])->filter()->map(fn ($n, $t) => "{$t}={$n}")->implode(', ');
                $this->line(sprintf(
                    '  #%d  nube=%d disco=%d kyc=%d  %s%s',
                    $id, $r['archivos_nube'], $r['archivos_disco'], $r['kyc'], $filas ?: 'sin filas',
                    $dryRun ? '' : "  errores_archivos={$r['errores_archivos']} onesignal={$r['onesignal']}"
                ));
            } catch (\Throwable $e) {
                $fallidas++;
                // Solo el ID y el tipo de error: el mensaje de una excepción SQL puede traer datos
                $this->error("  #{$id}  falló (" . class_basename($e) . ')');
                Log::error('[CuentaEliminacion] falló la anonimización', [
                    'user_id' => $id,
                    'fecha' => now()->toDateTimeString(),
                    'error' => class_basename($e),
                ]);
            }
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Procesadas: {$ok}  Fallidas: {$fallidas}");
        return $fallidas ? self::FAILURE : self::SUCCESS;
    }
}
