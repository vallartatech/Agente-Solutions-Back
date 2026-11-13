<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red: ajustes de una cotización ya aceptada (v2, v3…).
 * La cotización original (network_quotes) conserva el precio vigente; aquí queda el historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('network_quote_ajustes')) return;

        Schema::create('network_quote_ajustes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('network_quote_id')->index();
            $table->unsignedBigInteger('work_order_id')->index();
            $table->unsignedBigInteger('technician_id')->index();
            $table->unsignedSmallInteger('version');                 // 2 = primer ajuste (la original es la v1)
            $table->decimal('precio_anterior', 10, 2);
            $table->decimal('precio', 10, 2);
            $table->text('motivo');
            $table->json('desglose')->nullable();                     // {servicios:[{descripcion,cantidad,precio}], materiales:[…]}
            $table->json('fotos')->nullable();                        // URLs de Cloudinary
            $table->string('status', 20)->default('pending');         // pending | accepted | rejected | retirado
            $table->unsignedBigInteger('respondido_por')->nullable();
            $table->timestamp('respondido_at')->nullable();
            $table->string('decision_tecnico', 20)->nullable();       // tras un rechazo: continuar | cancelar
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_quote_ajustes');
    }
};
