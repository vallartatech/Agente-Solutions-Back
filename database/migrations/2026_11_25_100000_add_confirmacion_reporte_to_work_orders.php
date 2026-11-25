<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Market: el técnico envía el reporte y el cliente lo confirma (o se confirma solo a las 48 h).
 *  - report_sent_at: cuándo el técnico envió el reporte al cliente.
 *  - client_confirmed_at: cuándo quedó confirmado.
 *  - client_confirmed_by: quién lo confirmó; null con fecha = confirmación automática.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('work_orders')) return;
        Schema::table('work_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('work_orders', 'report_sent_at')) {
                $table->timestamp('report_sent_at')->nullable();
            }
            if (!Schema::hasColumn('work_orders', 'client_confirmed_at')) {
                $table->timestamp('client_confirmed_at')->nullable();
            }
            if (!Schema::hasColumn('work_orders', 'client_confirmed_by')) {
                $table->unsignedBigInteger('client_confirmed_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('work_orders')) return;
        Schema::table('work_orders', function (Blueprint $table) {
            foreach (['report_sent_at', 'client_confirmed_at', 'client_confirmed_by'] as $col) {
                if (Schema::hasColumn('work_orders', $col)) $table->dropColumn($col);
            }
        });
    }
};
