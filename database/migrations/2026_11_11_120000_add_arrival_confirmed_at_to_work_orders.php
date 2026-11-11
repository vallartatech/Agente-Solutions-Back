<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red: el dueño de la propiedad confirma que el técnico ya llegó (autoriza el inicio del trabajo).
 * arrival_status se queda en EN_SITIO porque el QR y el mapa en vivo dependen de ese valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('work_orders', 'arrival_confirmed_at')) {
                $table->timestamp('arrival_confirmed_at')->nullable()->after('arrival_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (Schema::hasColumn('work_orders', 'arrival_confirmed_at')) {
                $table->dropColumn('arrival_confirmed_at');
            }
        });
    }
};
