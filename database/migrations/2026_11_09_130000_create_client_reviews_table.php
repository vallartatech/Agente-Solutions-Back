<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calificación del cliente de la red, hecha por el técnico al terminar el trabajo:
 *  ★ trato (claridad, respeto, facilidad de trabajar con él)
 *  ⏱ pago (pagó a tiempo y estuvo disponible en la cita)
 * Los promedios se guardan en users para mostrarlos rápido en el mercado de trabajos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('client_reviews')) {
            Schema::create('client_reviews', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_id');      // users.id del cliente
                $table->unsignedBigInteger('technician_id');  // users.id del técnico que califica
                $table->unsignedBigInteger('work_order_id');
                $table->unsignedTinyInteger('rating_trato');
                $table->unsignedTinyInteger('rating_pago');
                $table->string('comment', 1000)->nullable();
                $table->timestamps();

                $table->unique(['technician_id', 'work_order_id']);
                $table->index('client_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'client_trato_avg')) {
                $table->decimal('client_trato_avg', 3, 2)->nullable();
            }
            if (!Schema::hasColumn('users', 'client_pago_avg')) {
                $table->decimal('client_pago_avg', 3, 2)->nullable();
            }
            if (!Schema::hasColumn('users', 'client_reviews_count')) {
                $table->unsignedInteger('client_reviews_count')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_reviews');
        Schema::table('users', function (Blueprint $table) {
            foreach (['client_trato_avg', 'client_pago_avg', 'client_reviews_count'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
