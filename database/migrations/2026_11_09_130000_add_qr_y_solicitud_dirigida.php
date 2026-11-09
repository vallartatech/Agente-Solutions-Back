<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // QR del técnico de la red y solicitudes dirigidas a un técnico favorito.
    // - users.qr_codigo: código aleatorio y permanente del técnico; el QR solo guarda la URL /t/{qr_codigo}.
    //   Se genera la primera vez que se pide y nunca cambia (un QR impreso sirve para siempre).
    // - work_orders.tecnico_dirigido_id: la solicitud está en la red pero solo la ve ese técnico (un favorito
    //   del cliente). Él la cotiza y sigue el flujo normal; si el cliente la reabre a la red se limpia.
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'qr_codigo')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('qr_codigo', 20)->nullable()->unique();
            });
        }

        if (!Schema::hasColumn('work_orders', 'tecnico_dirigido_id')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('tecnico_dirigido_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_orders', 'tecnico_dirigido_id')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->dropIndex(['tecnico_dirigido_id']);
                $table->dropColumn('tecnico_dirigido_id');
            });
        }
        if (Schema::hasColumn('users', 'qr_codigo')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['qr_codigo']);
                $table->dropColumn('qr_codigo');
            });
        }
    }
};
