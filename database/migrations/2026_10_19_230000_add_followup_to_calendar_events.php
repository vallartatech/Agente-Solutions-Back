<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Seguimiento de avisos: un aviso se marca como completado (con comentario para el equipo)
    // y el siguiente paso queda ligado al anterior (parent_id) para ver el historial completo.
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            if (!Schema::hasColumn('calendar_events', 'parent_id')) {
                $table->foreignId('parent_id')->nullable()->after('id')
                    ->constrained('calendar_events')->nullOnDelete();
            }
            if (!Schema::hasColumn('calendar_events', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->text('completion_note')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            if (Schema::hasColumn('calendar_events', 'parent_id')) {
                $table->dropConstrainedForeignId('parent_id');
            }
            if (Schema::hasColumn('calendar_events', 'completed_at')) {
                $table->dropColumn(['completed_at', 'completed_by', 'completion_note']);
            }
        });
    }
};
