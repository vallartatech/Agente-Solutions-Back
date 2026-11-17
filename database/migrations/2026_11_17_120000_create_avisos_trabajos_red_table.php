<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red: aviso "Hay trabajos de tu especialidad" agrupado por técnico (App\Services\AvisoTrabajosRed).
 * Guarda cuántos trabajos nuevos lleva sin avisar y cuándo fue el último aviso,
 * para no mandar una notificación por cada trabajo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('avisos_trabajos_red')) {
            return;
        }
        Schema::create('avisos_trabajos_red', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedInteger('pendientes')->default(0);
            // Habilidades de los trabajos pendientes ("" = sin habilidad): arma el texto del aviso
            $table->text('especialidades')->nullable();
            $table->timestamp('ultimo_aviso_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_trabajos_red');
    }
};
