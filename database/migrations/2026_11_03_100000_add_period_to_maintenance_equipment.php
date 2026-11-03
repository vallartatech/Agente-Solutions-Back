<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Mantenimiento por periodo: cada N meses desde el último mantenimiento. Sin frecuencia el equipo
    // sigue como antes (una próxima fecha escrita a mano). En las visitas se guarda cuándo se realizó.
    public function up(): void
    {
        if (!Schema::hasColumn('maintenance_equipment', 'frecuencia_meses')) {
            Schema::table('maintenance_equipment', function (Blueprint $table) {
                $table->unsignedTinyInteger('frecuencia_meses')->nullable();
                $table->date('ultimo_mantenimiento')->nullable();
            });
        }
        if (!Schema::hasColumn('maintenance_visits', 'realizada_el')) {
            Schema::table('maintenance_visits', function (Blueprint $table) {
                $table->date('realizada_el')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('maintenance_visits', 'realizada_el')) {
            Schema::table('maintenance_visits', function (Blueprint $table) {
                $table->dropColumn('realizada_el');
            });
        }
        if (Schema::hasColumn('maintenance_equipment', 'frecuencia_meses')) {
            Schema::table('maintenance_equipment', function (Blueprint $table) {
                $table->dropColumn(['frecuencia_meses', 'ultimo_mantenimiento']);
            });
        }
    }
};
