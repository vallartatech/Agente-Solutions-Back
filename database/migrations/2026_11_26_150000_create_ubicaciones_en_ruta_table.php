<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Market: última ubicación del técnico mientras va en camino a un trabajo
 * (de "Iniciar ruta" a "Llegué al lugar"). Una fila por trabajo; el cliente la ve en su mapa.
 * Se borra al llegar o al cancelarse el trabajo, así que no queda historial de recorridos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ubicaciones_en_ruta')) {
            Schema::create('ubicaciones_en_ruta', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('work_order_id')->unique();
                $table->unsignedBigInteger('technician_id')->index();
                $table->decimal('lat', 10, 7);
                $table->decimal('lng', 10, 7);
                $table->decimal('precision_m', 8, 1)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicaciones_en_ruta');
    }
};
