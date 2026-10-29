<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Etapa de cada evidencia como columna (antes estaba solo como "[ANTES]" dentro de la descripción).
        // Nueva etapa "ticket": la foto del comprobante firmado/sellado que entrega la empresa.
        Schema::table('work_reports', function (Blueprint $table) {
            $table->string('stage', 20)->nullable()->after('technician_id');
            $table->index('stage');
        });

        // Se rellena a partir de la etiqueta; las evidencias sin etiqueta se quedan sin etapa (no se adivina)
        $etiquetas = [
            'antes' => ['[ANTES]'],
            'durante' => ['[DURANTE]'],
            'despues' => ['[DESPUÉS]', '[DESPUES]'],
            'extra' => ['[EXTRA]'],
        ];
        foreach ($etiquetas as $stage => $tags) {
            DB::table('work_reports')
                ->whereNull('stage')
                ->where(function ($q) use ($tags) {
                    foreach ($tags as $tag) {
                        $q->orWhereRaw('UPPER(description) LIKE ?', ['%' . $tag . '%']);
                    }
                })
                ->update(['stage' => $stage]);
        }

        // Validación del trabajo terminado: quién lo dio por finalizado, cuándo y si fue con o sin ticket
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('ticket_status', 20)->nullable(); // con_ticket | sin_ticket
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
        });

        // Validador fijo (le llegan los trabajos terminados) y su mascota de alerta en el menú
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('valida_trabajos')->default(false);
            $table->string('alert_mascot', 20)->default('gato'); // gato | perro | fantasma | numero
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['valida_trabajos', 'alert_mascot']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['closed_by']);
            $table->dropColumn(['ticket_status', 'closed_by', 'closed_at']);
        });

        Schema::table('work_reports', function (Blueprint $table) {
            $table->dropIndex(['stage']);
            $table->dropColumn('stage');
        });
    }
};
