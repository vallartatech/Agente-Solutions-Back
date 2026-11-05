<?php

namespace App\Console\Commands;

use App\Services\AccesoQr;
use Illuminate\Console\Command;

// Respaldo del borrado automático: elimina QRs de acceso de trabajos ya cerrados, borrados o vencidos.
class PurgarAccesoQr extends Command
{
    protected $signature = 'acceso-qr:purgar';
    protected $description = 'Elimina los QR de acceso de trabajos finalizados, cancelados, eliminados o vencidos';

    public function handle(): int
    {
        $total = AccesoQr::purgarTodo();
        $this->info("QR de acceso eliminados: {$total}");
        return self::SUCCESS;
    }
}
