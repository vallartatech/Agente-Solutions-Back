<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// QR de acceso: respaldo del borrado automático al finalizar el trabajo
Schedule::command('acceso-qr:purgar')->hourly();

// Eliminación de cuentas: anonimiza las que llevan 30 días pendientes.
// En Railway el scheduler no corre: se ejecuta con un servicio Cron (docs/eliminacion-cuentas.md).
Schedule::command('cuentas:purgar-eliminadas')->dailyAt('03:00');
