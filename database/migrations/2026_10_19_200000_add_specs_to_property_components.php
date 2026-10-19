<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Ficha técnica opcional por elemento del levantamiento (p. ej. aire acondicionado:
    // capacidad, refrigerante, voltaje...). JSON flexible para reutilizarlo en otras categorías.
    public function up(): void
    {
        if (!Schema::hasColumn('property_components', 'specs')) {
            Schema::table('property_components', function (Blueprint $table) {
                $table->json('specs')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('property_components', 'specs')) {
            Schema::table('property_components', function (Blueprint $table) {
                $table->dropColumn('specs');
            });
        }
    }
};
