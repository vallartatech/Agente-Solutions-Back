<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Market: el horario va en la cotización (App\Services\HorarioMercado).
 *
 * - work_orders: fecha/hora que pidió el cliente, marca del flujo nuevo (solo solicitudes nuevas),
 *   avisos ya enviados (recordatorios, tardanza, "nadie cotizó"), "En camino" y la re-programación.
 * - network_quotes: hora exacta que propone el técnico, cuántas veces la cambió y cuándo venció.
 * - tecnicos_no_llegaron: historial "No llegó"; también bloquea al técnico ese trabajo.
 * Todas las horas son de Mérida, sin zona (igual que scheduled_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('work_orders', 'flujo_horario')) $table->boolean('flujo_horario')->default(false);
            if (!Schema::hasColumn('work_orders', 'fecha_deseada')) $table->date('fecha_deseada')->nullable();
            // null = a cualquier hora del día
            if (!Schema::hasColumn('work_orders', 'hora_deseada')) $table->time('hora_deseada')->nullable();
            if (!Schema::hasColumn('work_orders', 'ruta_iniciada_at')) $table->dateTime('ruta_iniciada_at')->nullable();
            if (!Schema::hasColumn('work_orders', 'recordatorio_20_at')) $table->dateTime('recordatorio_20_at')->nullable();
            if (!Schema::hasColumn('work_orders', 'recordatorio_10_at')) $table->dateTime('recordatorio_10_at')->nullable();
            if (!Schema::hasColumn('work_orders', 'aviso_tardanza_at')) $table->dateTime('aviso_tardanza_at')->nullable();
            if (!Schema::hasColumn('work_orders', 'sin_tecnico_avisado_at')) $table->dateTime('sin_tecnico_avisado_at')->nullable();
            // Re-programación después de aceptar (1 sola, el cliente la aprueba)
            if (!Schema::hasColumn('work_orders', 'reprogramacion_propuesta')) $table->dateTime('reprogramacion_propuesta')->nullable();
            if (!Schema::hasColumn('work_orders', 'reprogramacion_usada')) $table->boolean('reprogramacion_usada')->default(false);
        });

        Schema::table('network_quotes', function (Blueprint $table) {
            if (!Schema::hasColumn('network_quotes', 'horario_propuesto')) $table->dateTime('horario_propuesto')->nullable();
            if (!Schema::hasColumn('network_quotes', 'cambios_horario')) $table->unsignedTinyInteger('cambios_horario')->default(0);
            if (!Schema::hasColumn('network_quotes', 'vencida_at')) $table->dateTime('vencida_at')->nullable();
        });

        if (!Schema::hasTable('tecnicos_no_llegaron')) {
            Schema::create('tecnicos_no_llegaron', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('work_order_id')->index();
                $table->unsignedBigInteger('technician_id')->index();
                $table->unsignedBigInteger('client_user_id')->nullable();
                $table->dateTime('scheduled_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tecnicos_no_llegaron');

        $quotes = array_filter(['horario_propuesto', 'cambios_horario', 'vencida_at'], fn ($c) => Schema::hasColumn('network_quotes', $c));
        if ($quotes) {
            Schema::table('network_quotes', fn (Blueprint $t) => $t->dropColumn(array_values($quotes)));
        }

        $ordenes = array_filter([
            'flujo_horario', 'fecha_deseada', 'hora_deseada', 'ruta_iniciada_at', 'recordatorio_20_at', 'recordatorio_10_at',
            'aviso_tardanza_at', 'sin_tecnico_avisado_at', 'reprogramacion_propuesta', 'reprogramacion_usada',
        ], fn ($c) => Schema::hasColumn('work_orders', $c));
        if ($ordenes) {
            Schema::table('work_orders', fn (Blueprint $t) => $t->dropColumn(array_values($ordenes)));
        }
    }
};
