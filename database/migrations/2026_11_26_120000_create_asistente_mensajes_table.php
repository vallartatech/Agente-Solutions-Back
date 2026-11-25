<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asistente de ayuda con IA: conversación guardada de cada usuario (solo el texto visible).
 * Los mensajes de más de 30 días se borran desde AsistenteController (el scheduler no corre).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('asistente_mensajes')) {
            Schema::create('asistente_mensajes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('rol', 10); // user | assistant
                $table->text('texto');
                $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('asistente_mensajes')) {
            Schema::drop('asistente_mensajes');
        }
    }
};
