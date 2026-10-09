<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('technician_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('client_id')->constrained('users')->onDelete('cascade');
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->decimal('rating_stars', 2, 1)->default(5.0); // 1.0 a 5.0 ⭐ Calidad del trabajo
            $table->decimal('rating_time', 2, 1)->default(5.0);  // 1.0 a 5.0 ⏱️ Puntualidad y Tiempo de llegada
            $table->text('comment')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->integer('delay_minutes')->nullable();
            $table->timestamps();

            $table->index(['technician_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'rating_stars_avg')) {
                $table->decimal('rating_stars_avg', 3, 2)->default(5.00)->after('subscription_mp_payment_id');
            }
            if (!Schema::hasColumn('users', 'rating_time_avg')) {
                $table->decimal('rating_time_avg', 3, 2)->default(5.00)->after('rating_stars_avg');
            }
            if (!Schema::hasColumn('users', 'total_reviews_count')) {
                $table->unsignedInteger('total_reviews_count')->default(0)->after('rating_time_avg');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technician_reviews');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['rating_stars_avg', 'rating_time_avg', 'total_reviews_count']);
        });
    }
};
