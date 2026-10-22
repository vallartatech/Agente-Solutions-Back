<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // "Ver todo el calendario": quien lo tiene ve todos los avisos del equipo (también los privados).
    // Los demás solo ven los de todo el equipo, en los que participan y los que publicaron.
    // No cambia las notificaciones: un aviso privado solo notifica a sus participantes.
    private array $adminsPrincipales = [
        'jdzulescalante@gmail.com',
        'ppechmena@gmail.com',
        '1vallarta@agentesolutions.com',
        't.montero@mantenere.com.mx',
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('users', 'can_view_all_calendar')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('can_view_all_calendar')->default(false);
            });
        }

        DB::table('users')
            ->whereIn(DB::raw('LOWER(email)'), $this->adminsPrincipales)
            ->update(['can_view_all_calendar' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'can_view_all_calendar')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('can_view_all_calendar');
            });
        }
    }
};
