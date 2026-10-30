<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Un equipo puede agregarse sin propiedad o con solo un nombre escrito a mano
    public function up(): void
    {
        Schema::table('maintenance_equipment', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->change();
            $table->string('propiedad_nombre', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_equipment', function (Blueprint $table) {
            $table->dropColumn('propiedad_nombre');
        });
    }
};
