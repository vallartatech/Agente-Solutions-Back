<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// QR de acceso: respaldo del borrado automático al finalizar el trabajo
Schedule::command('acceso-qr:purgar')->hourly();

// Agente Market: horario en la cotización. En Railway el scheduler no corre: se dispara con un Cron externo
// contra POST /api/interno/mercado/tareas (docs/horario-mercado.md).
Schedule::command('mercado:tareas-horario')->everyMinute()->withoutOverlapping();

// Eliminación de cuentas: anonimiza las que llevan 30 días pendientes.
// En Railway el scheduler no corre: se ejecuta con un servicio Cron (docs/eliminacion-cuentas.md).
Schedule::command('cuentas:purgar-eliminadas')->dailyAt('03:00');
