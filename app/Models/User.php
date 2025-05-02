<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

 /**
     * Get the devices for the user.
     */
    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Get the conversations for the user.
     */
    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_user')
                    ->withTimestamps();
    }

    /**
     * Get the messages sent by the user.
     */
    public function messages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * Get the message reactions by the user.
     */
    public function messageReactions()
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * Get the message statuses for the user.
     */
    public function messageStatuses()
    {
        return $this->hasMany(MessageStatus::class);
    }

    /**
     * Get the activity logs for the user.
     */
    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Get the created conversations by the user.
     */
    public function createdConversations()
    {
        return $this->hasMany(Conversation::class, 'created_by');
    }

    /**
     * Get the avatar URL attribute.
     *
     * @return string
     */
    public function getAvatarUrlAttribute()
    {
        if ($this->avatar) {
            return url('storage/' . $this->avatar);
        }

        return url('assets/default-avatar.png');
    }

    /**
     * Get the online status attribute.
     *
     * @return bool
     */
    public function getIsOnlineAttribute()
    {
        return $this->activityLogs()
            ->where('activity_type', 'online')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();
    }
}
