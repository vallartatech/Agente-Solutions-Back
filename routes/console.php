<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// QR de acceso: respaldo del borrado automático al finalizar el trabajo
Schedule::command('acceso-qr:purgar')->hourly();
