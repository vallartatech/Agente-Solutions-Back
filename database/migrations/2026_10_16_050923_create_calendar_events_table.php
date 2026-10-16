<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('event_date')->index();
            $table->string('start_time', 20)->nullable();
            $table->string('end_time', 20)->nullable();
            $table->string('type', 50)->default('aviso');
            $table->string('color', 30)->default('#F26522');
            $table->boolean('notify_team')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
