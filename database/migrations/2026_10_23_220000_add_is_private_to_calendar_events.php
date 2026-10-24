<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Aviso privado: solo lo ve quien lo publicó (ni Root ni "ver todo el calendario") y no notifica a nadie.
    public function up(): void
    {
        if (!Schema::hasColumn('calendar_events', 'is_private')) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->boolean('is_private')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('calendar_events', 'is_private')) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->dropIndex(['is_private']);
                $table->dropColumn('is_private');
            });
        }
    }
};
