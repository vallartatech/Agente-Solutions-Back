<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red: habilidad que necesita el trabajo (la elige el cliente al solicitarlo, o se sugiere sola por el equipo).
 * Vacía = "No estoy seguro": el trabajo le aparece a todos los técnicos.
 * Con ella, el mercado muestra cada trabajo solo a los técnicos que tienen esa habilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('work_orders') || Schema::hasColumn('work_orders', 'specialty_id')) {
            return;
        }
        Schema::table('work_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('specialty_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_orders', 'specialty_id')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->dropIndex(['specialty_id']);
                $table->dropColumn('specialty_id');
            });
        }
    }
};
