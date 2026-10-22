<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatParticipant extends Model
{
    protected $table = 'chat_participants';

    protected $fillable = [
        'chat_id', 'user_id', 'added_by', 'from_message_id', 'until_message_id', 'left_at', 'last_read_message_id',
    ];

    protected $casts = [
        'left_at' => 'datetime',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return is_null($this->left_at);
    }
}
