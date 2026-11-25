<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agente Market: por qué el cliente rechazó la cotización del técnico.
 * Se borra cuando el técnico vuelve a cotizar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('network_quotes') && !Schema::hasColumn('network_quotes', 'motivo_rechazo')) {
            Schema::table('network_quotes', function (Blueprint $table) {
                $table->text('motivo_rechazo')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('network_quotes') && Schema::hasColumn('network_quotes', 'motivo_rechazo')) {
            Schema::table('network_quotes', function (Blueprint $table) {
                $table->dropColumn('motivo_rechazo');
            });
        }
    }
};
