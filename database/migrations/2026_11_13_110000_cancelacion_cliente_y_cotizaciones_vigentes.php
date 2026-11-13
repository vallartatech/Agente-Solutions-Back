<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red: el cliente cancela un trabajo asignado y, al reenviarlo a la red, puede elegir
 * una cotización anterior que siga vigente (el técnico confirma que sigue disponible).
 * Solo agrega columnas nulas o con valor por defecto; no cambia datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('work_orders', 'cancelled_by_client')) {
                $table->boolean('cancelled_by_client')->default(false);
            }
            // Última vez que se reenvió a la red: las cotizaciones anteriores piden confirmación al técnico
            if (!Schema::hasColumn('work_orders', 'reabierta_at')) {
                $table->timestamp('reabierta_at')->nullable();
            }
        });

        Schema::table('network_quotes', function (Blueprint $table) {
            if (!Schema::hasColumn('network_quotes', 'cotizada_at')) {
                $table->timestamp('cotizada_at')->nullable();             // cuándo puso o cambió el precio
            }
            if (!Schema::hasColumn('network_quotes', 'retirada_at')) {
                $table->timestamp('retirada_at')->nullable();             // el técnico la retiró
            }
            if (!Schema::hasColumn('network_quotes', 'confirmacion_pedida_at')) {
                $table->timestamp('confirmacion_pedida_at')->nullable();  // el cliente la eligió; falta que el técnico confirme
            }
        });

        // El cliente canceló: el técnico debe calificarlo (trato y seriedad). Aparte de
        // technician_cancellations, que además sirve para el bloqueo de 24 h del técnico.
        if (!Schema::hasTable('client_cancellations')) {
            Schema::create('client_cancellations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('work_order_id')->index();
                $table->unsignedBigInteger('technician_id')->index();
                $table->unsignedBigInteger('client_user_id')->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->boolean('rated')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_cancellations');
        Schema::table('network_quotes', function (Blueprint $table) {
            foreach (['cotizada_at', 'retirada_at', 'confirmacion_pedida_at'] as $columna) {
                if (Schema::hasColumn('network_quotes', $columna)) $table->dropColumn($columna);
            }
        });
        Schema::table('work_orders', function (Blueprint $table) {
            foreach (['cancelled_by_client', 'reabierta_at'] as $columna) {
                if (Schema::hasColumn('work_orders', $columna)) $table->dropColumn($columna);
            }
        });
    }
};
