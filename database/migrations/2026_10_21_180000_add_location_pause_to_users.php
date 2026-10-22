<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // "Pausar mi ubicación": quien tiene el permiso puede pausar y reanudar el envío de su ubicación.
    // Mientras está pausada, el mapa del equipo muestra "Ubicación pausada" en lugar de su posición.
    private array $conPermiso = [
        '1vallarta@agentesolutions.com', // Jorge Vallarta (dirección)
        'ppechmena@gmail.com',           // Pedro Pech Koh
        'jdzulescalante@gmail.com',      // Jesús Dzul
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'can_pause_location')) {
                $table->boolean('can_pause_location')->default(false);
            }
            if (!Schema::hasColumn('users', 'location_paused_at')) {
                $table->timestamp('location_paused_at')->nullable();
            }
            // Estado elegido al pausar ("En junta", "Ocupado"...), se muestra en el mapa
            if (!Schema::hasColumn('users', 'location_pause_status')) {
                $table->string('location_pause_status', 40)->nullable();
            }
        });

        DB::table('users')
            ->whereIn(DB::raw('LOWER(email)'), $this->conPermiso)
            ->update(['can_pause_location' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['can_pause_location', 'location_paused_at', 'location_pause_status'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
