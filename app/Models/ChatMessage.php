<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $table = 'chat_messages';

    // type: text | image | video | sticker | system
    protected $fillable = ['chat_id', 'user_id', 'type', 'body', 'attachment_url', 'deleted_at'];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
