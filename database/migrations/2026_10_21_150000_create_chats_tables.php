<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Chat interno del equipo (tipo WhatsApp): conversaciones de 2 personas o grupos.
    public function up(): void
    {
        if (!Schema::hasTable('chats')) {
            Schema::create('chats', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('title', 80)->nullable();          // solo grupos
                $table->boolean('is_group')->default(false);
                $table->unsignedBigInteger('created_by')->index();
                $table->timestamp('last_message_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable();  // null en mensajes del sistema
                $table->string('type', 20)->default('text');        // text | image | system
                $table->text('body')->nullable();
                $table->string('attachment_url', 500)->nullable();
                $table->timestamps();
                $table->index(['chat_id', 'id']);
            });
        }

        if (!Schema::hasTable('chat_participants')) {
            Schema::create('chat_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('added_by')->nullable();
                // Ve los mensajes con id > from_message_id (lo anterior a su entrada no) ...
                $table->unsignedBigInteger('from_message_id')->default(0);
                // ... y hasta until_message_id si lo sacaron o salió (null = sigue en el chat)
                $table->unsignedBigInteger('until_message_id')->nullable();
                $table->timestamp('left_at')->nullable();
                $table->unsignedBigInteger('last_read_message_id')->default(0);
                $table->timestamps();
                $table->unique(['chat_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chats');
    }
};
