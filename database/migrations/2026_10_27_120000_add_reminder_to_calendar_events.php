<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Alarma 1 hora antes de los avisos con hora fija: la programa OneSignal (send_after).
    // Se guarda su id para cancelarla si el aviso se elimina o se completa antes.
    public function up(): void
    {
        if (!Schema::hasColumn('calendar_events', 'reminder_push_id')) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->string('reminder_push_id', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('calendar_events', 'reminder_push_id')) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->dropColumn('reminder_push_id');
            });
        }
    }
};
