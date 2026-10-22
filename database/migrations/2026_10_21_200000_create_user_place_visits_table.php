<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Visitas detectadas por GPS: quién estuvo en qué propiedad registrada y de qué hora a qué hora.
    // Con ellas se avisa la llegada y la salida a quienes tienen acceso al mapa en vivo.
    public function up(): void
    {
        if (!Schema::hasTable('user_place_visits')) {
            Schema::create('user_place_visits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('property_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamp('entered_at');
                $table->timestamp('last_seen_at');
                $table->timestamp('left_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'left_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_place_visits');
    }
};
