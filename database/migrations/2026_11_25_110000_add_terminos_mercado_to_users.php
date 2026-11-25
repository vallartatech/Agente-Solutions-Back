<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Market: cuándo aceptó el usuario la cláusula del Mercado
 * (la app solo conecta clientes y técnicos, no cobra comisiones y los pagos y problemas son entre ellos).
 * Aplica a técnicos y clientes de la red, nuevos y ya registrados.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'terminos_mercado_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('terminos_mercado_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'terminos_mercado_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('terminos_mercado_at');
            });
        }
    }
};
