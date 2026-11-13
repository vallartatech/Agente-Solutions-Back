<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red: el ajuste de cotización usa el mismo formato que el cotizador interno.
 * - archivo_url / archivo_nombre: la cotización cargada como PDF o imagen (pestaña "Cargar archivo").
 * - con_iva: el técnico marcó "Agregar IVA (16%)"; el precio guardado ya lo incluye.
 * Solo agrega columnas opcionales; no cambia datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('network_quote_ajustes')) return;

        Schema::table('network_quote_ajustes', function (Blueprint $table) {
            if (!Schema::hasColumn('network_quote_ajustes', 'archivo_url')) {
                $table->string('archivo_url', 500)->nullable();
            }
            if (!Schema::hasColumn('network_quote_ajustes', 'archivo_nombre')) {
                $table->string('archivo_nombre', 191)->nullable();
            }
            if (!Schema::hasColumn('network_quote_ajustes', 'con_iva')) {
                $table->boolean('con_iva')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('network_quote_ajustes')) return;

        Schema::table('network_quote_ajustes', function (Blueprint $table) {
            foreach (['archivo_url', 'archivo_nombre', 'con_iva'] as $columna) {
                if (Schema::hasColumn('network_quote_ajustes', $columna)) $table->dropColumn($columna);
            }
        });
    }
};
