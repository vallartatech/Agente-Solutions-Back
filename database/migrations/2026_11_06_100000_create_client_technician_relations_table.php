<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Mis técnicos" del cliente particular (rol 4): técnicos marcados como favoritos o bloqueados.
// Un técnico bloqueado no ve los trabajos del cliente en la red, no puede cotizarlos ni mandarle mensajes.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('client_technician_relations')) return;

        Schema::create('client_technician_relations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_user_id');
            $table->unsignedBigInteger('technician_id');
            // Tenant del cliente al marcar: sus órdenes pueden ligarse por tenant y no por la propiedad
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('status', 20); // favorito | bloqueado
            $table->timestamps();

            $table->unique(['client_user_id', 'technician_id']);
            $table->index(['technician_id', 'status']);
            $table->foreign('client_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('technician_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_technician_relations');
    }
};
