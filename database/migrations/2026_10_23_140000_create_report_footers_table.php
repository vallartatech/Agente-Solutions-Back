<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Pies de página guardados para el reporte oficial (datos de la empresa para la que se emite)
    public function up(): void
    {
        Schema::create('report_footers', function (Blueprint $table) {
            $table->id();
            $table->string('empresa');
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->string('leyenda', 500)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_footers');
    }
};
