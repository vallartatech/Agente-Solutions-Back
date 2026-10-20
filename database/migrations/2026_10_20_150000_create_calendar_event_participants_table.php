<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Participantes de un aviso del calendario (junta, trabajo...).
    // Sin participantes = aviso para todo el equipo (comportamiento anterior).
    public function up(): void
    {
        if (!Schema::hasTable('calendar_event_participants')) {
            Schema::create('calendar_event_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('calendar_event_id')->constrained('calendar_events')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->index();
                $table->timestamps();
                $table->unique(['calendar_event_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_participants');
    }
};
