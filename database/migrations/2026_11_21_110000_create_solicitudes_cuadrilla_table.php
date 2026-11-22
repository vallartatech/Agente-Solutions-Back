<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de un técnico que YA tiene cuenta para unirse a la cuadrilla de un contratista:
 * un técnico independiente (6) o uno que salió de otra cuadrilla. Mientras el contratista responde,
 * la cuenta sigue igual (el independiente puede seguir trabajando); al aceptarla pasa a técnico de cuadrilla.
 * Los registros nuevos con código siguen usando users.approval_status = 'pending'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('solicitudes_cuadrilla')) {
            return;
        }
        Schema::create('solicitudes_cuadrilla', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id'); // empresa (cuadrilla) del contratista
            $table->string('estado', 20)->default('pendiente'); // pendiente | aceptada | rechazada | cancelada
            $table->timestamp('respondida_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'estado']);
            $table->index(['user_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_cuadrilla');
    }
};
