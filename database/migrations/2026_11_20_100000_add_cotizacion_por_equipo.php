<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Mantenimiento de varios equipos en una solicitud: una cotización por equipo y un carrito
    // donde el admin elige cuáles contrata el cliente. Al finalizar el carrito ya no se puede cambiar.
    public function up(): void
    {
        // A qué equipo de la orden corresponde la cotización; vacío en las órdenes de un solo trabajo
        if (!Schema::hasColumn('quotes', 'work_order_component_id')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->unsignedBigInteger('work_order_component_id')->nullable();
                $table->index('work_order_component_id');
            });
        }

        // pendiente | aceptado | no_aceptado (lo decide el admin en el carrito)
        if (!Schema::hasColumn('work_order_components', 'estado')) {
            Schema::table('work_order_components', function (Blueprint $table) {
                $table->string('estado', 20)->default('pendiente');
            });
        }

        // Cuándo y quién cerró el carrito: a partir de ahí la elección es definitiva
        if (!Schema::hasColumn('work_orders', 'carrito_finalizado_at')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->timestamp('carrito_finalizado_at')->nullable();
                $table->unsignedBigInteger('carrito_finalizado_por')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_orders', 'carrito_finalizado_at')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->dropColumn(['carrito_finalizado_at', 'carrito_finalizado_por']);
            });
        }
        if (Schema::hasColumn('work_order_components', 'estado')) {
            Schema::table('work_order_components', function (Blueprint $table) {
                $table->dropColumn('estado');
            });
        }
        if (Schema::hasColumn('quotes', 'work_order_component_id')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->dropIndex(['work_order_component_id']);
                $table->dropColumn('work_order_component_id');
            });
        }
    }
};
