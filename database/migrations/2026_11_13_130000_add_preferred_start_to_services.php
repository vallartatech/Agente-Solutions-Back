<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red: el cliente elige la fecha y hora en que prefiere la visita del levantamiento.
 * El Root la ve al asignar al técnico y la ajusta con él; la cita real sigue en scheduled_start.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('services')) return;

        Schema::table('services', function (Blueprint $table) {
            if (!Schema::hasColumn('services', 'preferred_start')) {
                $table->dateTime('preferred_start')->nullable()->after('scheduled_end');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('services')) return;

        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'preferred_start')) {
                $table->dropColumn('preferred_start');
            }
        });
    }
};
