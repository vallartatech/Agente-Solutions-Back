<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos obligatorios del perfil profesional (archivos privados, como la INE):
 * comprobante de domicilio y constancia de antecedentes no penales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technician_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('technician_profiles', 'comprobante_domicilio_path')) {
                $table->string('comprobante_domicilio_path')->nullable()->after('ine_reverso_path');
            }
            if (!Schema::hasColumn('technician_profiles', 'antecedentes_penales_path')) {
                $table->string('antecedentes_penales_path')->nullable()->after('comprobante_domicilio_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('technician_profiles', function (Blueprint $table) {
            foreach (['comprobante_domicilio_path', 'antecedentes_penales_path'] as $col) {
                if (Schema::hasColumn('technician_profiles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
