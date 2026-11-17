<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Habilidades sugeridas por los técnicos. Ya no se agregan solas al catálogo:
 * Root/Admin las aprueban (con el nombre corregido), las unen a una existente o las rechazan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('specialty_suggestions')) {
            return;
        }

        Schema::create('specialty_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 60);
            $table->string('comentario', 300)->nullable();
            // pendiente | aprobada | unida | rechazada
            $table->string('status', 20)->default('pendiente')->index();
            // Habilidad del catálogo con la que quedó (aprobada o unida)
            $table->unsignedBigInteger('specialty_id')->nullable();
            $table->string('motivo', 300)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE specialty_suggestions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('specialty_suggestions');
    }
};
