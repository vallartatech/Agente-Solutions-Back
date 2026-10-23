<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Logo de la empresa a la que pertenecen las propiedades de la carpeta (ej. McDonald's, Smart Fit).
    // Se muestra en el catálogo y como marcador en el Mapa de propiedades.
    public function up(): void
    {
        if (!Schema::hasColumn('property_folders', 'logo_url')) {
            Schema::table('property_folders', function (Blueprint $table) {
                $table->string('logo_url', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('property_folders', 'logo_url')) {
            Schema::table('property_folders', function (Blueprint $table) {
                $table->dropColumn('logo_url');
            });
        }
    }
};
