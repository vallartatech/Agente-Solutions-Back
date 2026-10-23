<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Extras del chat: eliminar chat (para uno mismo), eliminar mensajes para todos,
    // "en línea / última vez", fondo del chat por usuario y stickers propios.
    public function up(): void
    {
        Schema::table('chat_participants', function (Blueprint $table) {
            // "Eliminar chat": se ocultan los mensajes hasta este id; si llegan nuevos, el chat reaparece
            if (!Schema::hasColumn('chat_participants', 'cleared_until_message_id')) {
                $table->unsignedBigInteger('cleared_until_message_id')->default(0);
            }
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_messages', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable(); // "Se eliminó este mensaje"
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'chat_last_seen_at')) {
                $table->timestamp('chat_last_seen_at')->nullable(); // "En línea" / "últ. vez"
            }
            if (!Schema::hasColumn('users', 'chat_wallpaper')) {
                $table->string('chat_wallpaper', 500)->nullable(); // fondo predefinido o URL de imagen
            }
            // Puede subir y borrar los stickers del equipo
            if (!Schema::hasColumn('users', 'can_manage_stickers')) {
                $table->boolean('can_manage_stickers')->default(false);
            }
        });

        // Stickers: personales (de cada usuario) o del equipo (los ve y usa todo el equipo)
        if (!Schema::hasTable('chat_stickers')) {
            Schema::create('chat_stickers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();       // quien lo subió / guardó
                $table->boolean('is_team')->default(false)->index();  // paquete del equipo
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('url', 500);
                $table->timestamps();
            });
        }

        DB::table('users')->whereRaw('LOWER(email) = ?', ['ppechmena@gmail.com'])
            ->update(['can_manage_stickers' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_stickers');
        Schema::table('users', function (Blueprint $table) {
            foreach (['chat_last_seen_at', 'chat_wallpaper', 'can_manage_stickers'] as $col) {
                if (Schema::hasColumn('users', $col)) $table->dropColumn($col);
            }
        });
        Schema::table('chat_messages', function (Blueprint $table) {
            if (Schema::hasColumn('chat_messages', 'deleted_at')) $table->dropColumn('deleted_at');
        });
        Schema::table('chat_participants', function (Blueprint $table) {
            if (Schema::hasColumn('chat_participants', 'cleared_until_message_id')) $table->dropColumn('cleared_until_message_id');
        });
    }
};
