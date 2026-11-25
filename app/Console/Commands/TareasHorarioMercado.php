<?php

namespace App\Console\Commands;

use App\Services\HorarioMercado;
use Illuminate\Console\Command;

class TareasHorarioMercado extends Command
{
    protected $signature = 'mercado:tareas-horario';
    protected $description = 'Agente Market: vence cotizaciones, recuerda al técnico, avisa tardanzas y solicitudes sin técnico';

    public function handle(): int
    {
        $r = HorarioMercado::tareas();
        $this->info(json_encode($r, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
