<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Etiqueta libre del aviso ("Cita con un cliente", "Junta"...). Las ya usadas se sugieren
    // a todo el equipo para no volver a escribirlas.
    public function up(): void
    {
        if (!Schema::hasColumn('calendar_events', 'label')) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->string('label', 60)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('calendar_events', 'label')) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->dropIndex(['label']);
                $table->dropColumn('label');
            });
        }
    }
};
