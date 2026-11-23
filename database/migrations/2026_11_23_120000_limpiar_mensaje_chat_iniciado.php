<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Red: al abrir el chat antes de cotizar, la cotización se guardaba con el mensaje
 * "Chat iniciado por el técnico". No es una propuesta: se deja en blanco.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('network_quotes')) return;
        DB::table('network_quotes')
            ->where('message', 'Chat iniciado por el técnico')
            ->update(['message' => null]);
    }

    public function down(): void
    {
        // Sin vuelta atrás: el texto no aportaba información
    }
};
