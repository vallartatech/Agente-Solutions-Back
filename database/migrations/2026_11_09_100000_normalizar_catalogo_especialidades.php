<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de especialidades:
 *  1. La tabla pasa a utf8mb4: los emojis de 4 bytes (🚰 🎨 🧱…) se guardaban como "?".
 *  2. Une los duplicados que dejó la siembra de usuarios de prueba
 *     ("Pintura" → "Pintura e Impermeabilización", etc.) y reasigna a sus técnicos.
 *  3. Vuelve a poner el ícono correcto de cada especialidad.
 */
return new class extends Migration
{
    // Duplicado => especialidad que se conserva
    private const UNIR = [
        'Aire Acondicionado' => 'Aire Acondicionado (HVAC)',
        'Albañilería' => 'Albañilería y Remodelación',
        'Cerrajería' => 'Cerrajería y Seguridad',
        'Pintura' => 'Pintura e Impermeabilización',
    ];

    private const ICONOS = [
        'Electricidad' => '⚡',
        'Plomería' => '🚰',
        'Aire Acondicionado (HVAC)' => '❄️',
        'Pintura e Impermeabilización' => '🎨',
        'Albañilería y Remodelación' => '🧱',
        'Carpintería y Muebles' => '🪚',
        'Cerrajería y Seguridad' => '🔑',
        'Limpieza y Mantenimiento' => '🧹',
        'Multi-técnico / General' => '🧰',
        'Electrodomésticos y Equipos' => '🔌',
        'Jardinería y Exteriores' => '🪴',
        'Redes y CCTV' => '🖥️',
        'Refrigeración' => '🧊',
        'Mantenimiento General' => '🛠️',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('specialties')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE specialties CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }

        DB::transaction(function () {
            foreach (self::UNIR as $duplicado => $destino) {
                $viejo = DB::table('specialties')->where('name', $duplicado)->value('id');
                $nuevo = DB::table('specialties')->where('name', $destino)->value('id');
                if (!$viejo || !$nuevo || $viejo === $nuevo) {
                    continue;
                }

                // Técnicos: pasan a la especialidad conservada (sin repetir la que ya tenían)
                if (Schema::hasTable('technician_specialties')) {
                    $usuarios = DB::table('technician_specialties')->where('specialty_id', $viejo)->pluck('user_id');
                    foreach ($usuarios as $userId) {
                        $yaLaTiene = DB::table('technician_specialties')
                            ->where('user_id', $userId)->where('specialty_id', $nuevo)->exists();
                        if (!$yaLaTiene) {
                            DB::table('technician_specialties')->insert([
                                'user_id' => $userId,
                                'specialty_id' => $nuevo,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                    DB::table('technician_specialties')->where('specialty_id', $viejo)->delete();
                }

                if (Schema::hasTable('job_requests') && Schema::hasColumn('job_requests', 'specialty_id')) {
                    DB::table('job_requests')->where('specialty_id', $viejo)->update(['specialty_id' => $nuevo]);
                }

                DB::table('specialties')->where('id', $viejo)->delete();
            }

            foreach (self::ICONOS as $nombre => $icono) {
                DB::table('specialties')->where('name', $nombre)->update(['icon' => $icono]);
            }
            // Las que agregaron los técnicos y perdieron su emoji
            DB::table('specialties')
                ->where(fn ($q) => $q->whereNull('icon')->orWhere('icon', '')->orWhere('icon', 'like', '%?%'))
                ->update(['icon' => '🔧']);
        });
    }

    public function down(): void
    {
        // Los duplicados unidos no se restauran.
    }
};
