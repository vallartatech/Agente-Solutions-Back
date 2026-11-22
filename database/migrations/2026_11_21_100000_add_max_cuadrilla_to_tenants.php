<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cupo de la cuadrilla de cada contratista (rol 7): cuántos técnicos activos puede tener.
 * Empieza en 7; Root lo amplía a mano mientras los cobros estén en pausa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenants') && !Schema::hasColumn('tenants', 'max_cuadrilla')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->unsignedSmallInteger('max_cuadrilla')->default(7);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'max_cuadrilla')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('max_cuadrilla');
            });
        }
    }
};
