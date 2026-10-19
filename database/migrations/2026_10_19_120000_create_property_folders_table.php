<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Carpetas para organizar el catálogo de propiedades (solo organización visual, compartidas por los admins del tenant)
        Schema::create('property_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('name', 80);
            $table->string('color', 30)->default('#F26522');
            $table->timestamps();
        });

        // Cada propiedad vive en una sola carpeta (o en ninguna). Al borrar la carpeta, la propiedad queda suelta.
        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('tenant_id')
                ->constrained('property_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('property_folders');
    }
};
