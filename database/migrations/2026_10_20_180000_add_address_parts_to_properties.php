<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Partes de la dirección por separado para poder editar cada una.
    // `address` sigue guardando el texto completo que muestra la app.
    private array $columnas = ['municipio', 'colonia', 'calle', 'numero', 'cruzamientos'];

    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            foreach ($this->columnas as $col) {
                if (!Schema::hasColumn('properties', $col)) {
                    $table->string($col, 191)->nullable();
                }
            }
        });

        // Rellenar las existentes leyendo el formato con el que siempre se armó la dirección:
        // "Calle {calle} #{numero}[ x {cruzamientos}], Col. {colonia}, {municipio}, {estado}"
        DB::table('properties')->whereNull('calle')->whereNotNull('address')->orderBy('id')
            ->chunkById(200, function ($props) {
                foreach ($props as $p) {
                    $partes = self::separarDireccion($p->address);
                    if ($partes) {
                        DB::table('properties')->where('id', $p->id)->update($partes);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            foreach ($this->columnas as $col) {
                if (Schema::hasColumn('properties', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private static function separarDireccion(?string $address): ?array
    {
        if (!$address || !preg_match('/^Calle\s+(.+?)\s+#(.+?)(?:\s+x\s+(.+?))?,\s*Col\.\s*(.+?),\s*(.+?),\s*(.+)$/u', trim($address), $m)) {
            return null;
        }
        return [
            'calle' => trim($m[1]),
            'numero' => trim($m[2]),
            'cruzamientos' => isset($m[3]) && trim($m[3]) !== '' ? trim($m[3]) : null,
            'colonia' => trim($m[4]),
            'municipio' => trim($m[5]),
        ];
    }
};
