<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'id',
        'conversation_id',
        'activity_type',
        'user_id',
    ];
}
