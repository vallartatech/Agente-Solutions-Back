<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('technician_cancellations')) {
            Schema::create('technician_cancellations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('technician_id');
                $table->unsignedBigInteger('work_order_id')->nullable();
                $table->unsignedBigInteger('client_user_id')->nullable();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('cancelled_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('rated')->default(false);
                $table->timestamps();

                $table->foreign('technician_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['technician_id', 'expires_at']);
                $table->index(['technician_id', 'client_user_id']);
            });
        }

        Schema::table('work_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('work_orders', 'cancelled_by_tech')) {
                $table->boolean('cancelled_by_tech')->default(false)->after('scheduled_at');
            }
            if (!Schema::hasColumn('work_orders', 'cancelled_technician_id')) {
                $table->unsignedBigInteger('cancelled_technician_id')->nullable()->after('cancelled_by_tech');
            }
            if (!Schema::hasColumn('work_orders', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_technician_id');
            }
            if (!Schema::hasColumn('work_orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_cancellations');
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['cancelled_by_tech', 'cancelled_technician_id', 'cancellation_reason', 'cancelled_at']);
        });
    }
};