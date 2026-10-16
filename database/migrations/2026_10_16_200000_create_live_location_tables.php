<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Únicos usuarios autorizados inicialmente para ver el mapa en vivo
    private array $viewerEmails = [
        'ppechmena@gmail.com',
        '1vallarta@agentesolutions.com',
        't.montero@mantenere.com.mx',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'can_view_live_map')) {
                $table->boolean('can_view_live_map')->default(false);
            }
            if (!Schema::hasColumn('users', 'location_consent_at')) {
                $table->timestamp('location_consent_at')->nullable();
            }
        });

        Schema::create('user_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->float('accuracy')->nullable();
            // live | app_open | job_update
            $table->string('source', 30)->default('live');
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->index(['user_id', 'recorded_at']);
        });

        // Quién consultó la ubicación de quién (auditoría)
        Schema::create('location_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('viewer_id')->index();
            $table->unsignedBigInteger('viewed_user_id')->nullable();
            $table->string('action', 30);
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('users')
            ->whereIn(DB::raw('LOWER(email)'), $this->viewerEmails)
            ->update(['can_view_live_map' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('location_access_logs');
        Schema::dropIfExists('user_locations');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_view_live_map', 'location_consent_at']);
        });
    }
};
