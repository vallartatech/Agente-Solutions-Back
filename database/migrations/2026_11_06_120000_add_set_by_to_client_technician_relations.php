<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Quién puso la marca: el cliente sobre el técnico ("Mis técnicos") o el técnico sobre el cliente
// ("Mis clientes"). Cada lado tiene su propio favorito/bloqueo para el mismo par.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('client_technician_relations', 'set_by')) return;

        Schema::table('client_technician_relations', function (Blueprint $table) {
            $table->string('set_by', 10)->default('cliente')->after('status'); // cliente | tecnico
        });

        Schema::table('client_technician_relations', function (Blueprint $table) {
            // MySQL usa el índice único para la llave foránea de client_user_id: primero el índice nuevo
            $table->index('client_user_id');
            $table->dropUnique(['client_user_id', 'technician_id']);
            $table->unique(['client_user_id', 'technician_id', 'set_by']);
        });
    }

    public function down(): void
    {
        Schema::table('client_technician_relations', function (Blueprint $table) {
            $table->dropUnique(['client_user_id', 'technician_id', 'set_by']);
            $table->unique(['client_user_id', 'technician_id']);
            $table->dropIndex(['client_user_id']);
            $table->dropColumn('set_by');
        });
    }
};
