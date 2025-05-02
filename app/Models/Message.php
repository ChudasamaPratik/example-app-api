<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Message extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['conversation_id', 'sender_id', 'body', 'type', 'file_path', 'reply_to_id'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function reactions()
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function statuses()
    {
        return $this->hasMany(MessageStatus::class);
    }

    public function replyTo()
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }
}
