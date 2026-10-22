<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    protected $table = 'chats';

    protected $fillable = ['tenant_id', 'title', 'is_group', 'created_by', 'last_message_at'];

    protected $casts = [
        'is_group' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function participants()
    {
        return $this->hasMany(ChatParticipant::class);
    }

    public function activeParticipants()
    {
        return $this->hasMany(ChatParticipant::class)->whereNull('left_at');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class);
    }
}
