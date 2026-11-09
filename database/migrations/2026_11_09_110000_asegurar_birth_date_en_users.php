<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La fecha de nacimiento ya se usa (perfil, cumpleaños del calendario) y ahora es obligatoria
 * en el perfil profesional de los técnicos de la red. Se asegura la columna por si falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'birth_date')) {
            Schema::table('users', function (Blueprint $table) {
                $table->date('birth_date')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Columna compartida con otras pantallas: no se elimina.
    }
};
