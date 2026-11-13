<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red: el acceso a la propiedad puede ser una imagen de QR, un código de texto (PIN, caseta) o ambos.
 * - codigo: texto cifrado con APP_KEY.
 * - image y mime pasan a ser opcionales (un acceso puede ser solo código).
 * No cambia los QRs que ya existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('access_qrs')) return;

        Schema::table('access_qrs', function (Blueprint $table) {
            if (!Schema::hasColumn('access_qrs', 'codigo')) {
                $table->text('codigo')->nullable()->after('image');
            }
            $table->longText('image')->nullable()->change();
            $table->string('mime', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('access_qrs')) return;

        Schema::table('access_qrs', function (Blueprint $table) {
            if (Schema::hasColumn('access_qrs', 'codigo')) {
                $table->dropColumn('codigo');
            }
        });
    }
};
