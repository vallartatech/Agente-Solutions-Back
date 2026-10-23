<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Cada guardado del reporte oficial crea una versión nueva (#1, #2, ...) en lugar de sobrescribir.
    // Los reportes que ya existían quedan como la versión 1.
    public function up(): void
    {
        Schema::table('final_work_reports', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('work_order_id');
            $table->string('logo_url', 500)->nullable()->after('folio');
            // Copia completa de lo que se ve en el reporte (cliente, propiedad, técnico, etc.)
            $table->json('data')->nullable()->after('selected_images');
            $table->unsignedBigInteger('created_by')->nullable()->after('data');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['service_id', 'version']);
            $table->index(['work_order_id', 'version']);
        });

        Schema::create('report_logos', function (Blueprint $table) {
            $table->id();
            $table->string('url', 500);
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_logos');

        Schema::table('final_work_reports', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropIndex(['service_id', 'version']);
            $table->dropIndex(['work_order_id', 'version']);
            $table->dropColumn(['version', 'logo_url', 'data', 'created_by']);
        });
    }
};
