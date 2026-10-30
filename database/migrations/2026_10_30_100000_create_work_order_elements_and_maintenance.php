<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Trabajos con varios elementos y ciclos de mantenimiento. Tablas propias: los levantamientos
    // (property_components) solo se leen y las órdenes sin elementos siguen funcionando igual.
    public function up(): void
    {
        // Equipo en mantenimiento: del levantamiento (property_component_id) o capturado sin levantamiento
        // como grupo ("3 minis"). Sin veces_al_ano todavía no tiene ciclo.
        if (!Schema::hasTable('maintenance_equipment')) {
            Schema::create('maintenance_equipment', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
                $table->foreignId('property_component_id')->nullable()->constrained('property_components')->nullOnDelete();
                $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
                $table->string('tipo', 150);
                $table->unsignedInteger('cantidad')->default(1);
                $table->string('foto_equipo_url')->nullable();
                $table->string('foto_datos_url')->nullable();
                $table->text('detalle')->nullable();
                $table->unsignedTinyInteger('veces_al_ano')->nullable();
                $table->date('fecha_base')->nullable();
                $table->boolean('activo')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Cada fecha del ciclo: pendiente | convertida (ya es orden) | omitida
        if (!Schema::hasTable('maintenance_visits')) {
            Schema::create('maintenance_visits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('maintenance_equipment_id')->constrained('maintenance_equipment')->cascadeOnDelete();
                $table->date('fecha_programada')->index();
                $table->string('status', 20)->default('pendiente')->index();
                $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
                $table->timestamps();
                $table->unique(['maintenance_equipment_id', 'fecha_programada'], 'maintenance_visits_equipo_fecha_unique');
            });
        }

        // Elementos de una orden. La etiqueta se copia para que el reporte siga completo
        // aunque después se borre el elemento del levantamiento.
        if (!Schema::hasTable('work_order_components')) {
            Schema::create('work_order_components', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
                $table->foreignId('property_component_id')->nullable()->constrained('property_components')->nullOnDelete();
                $table->foreignId('maintenance_equipment_id')->nullable()->constrained('maintenance_equipment')->nullOnDelete();
                $table->string('label');
                $table->timestamps();
            });
        }

        // A qué elemento pertenece la evidencia; vacío en las órdenes de un solo elemento
        // Con índice y sin llave foránea: crearla revalida toda la tabla y work_reports tiene evidencias
        // viejas de técnicos que ya no existen. Los elementos solo se borran junto con su orden.
        if (!Schema::hasColumn('work_reports', 'work_order_component_id')) {
            Schema::table('work_reports', function (Blueprint $table) {
                $table->unsignedBigInteger('work_order_component_id')->nullable();
            });
        }
        if (!Schema::hasIndex('work_reports', ['work_order_component_id'])) {
            Schema::table('work_reports', function (Blueprint $table) {
                $table->index('work_order_component_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_reports', 'work_order_component_id')) {
            Schema::table('work_reports', function (Blueprint $table) {
                $table->dropIndex(['work_order_component_id']);
                $table->dropColumn('work_order_component_id');
            });
        }
        Schema::dropIfExists('work_order_components');
        Schema::dropIfExists('maintenance_visits');
        Schema::dropIfExists('maintenance_equipment');
    }
};
