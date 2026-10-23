<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Stickers del chat: personales (cada usuario) o del equipo (is_team: los ve y usa todo el equipo)
class ChatSticker extends Model
{
    protected $table = 'chat_stickers';

    protected $fillable = ['user_id', 'is_team', 'tenant_id', 'url'];

    protected $casts = [
        'is_team' => 'boolean',
    ];
}
