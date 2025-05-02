<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'device_name',
        'device_token',
        'ip_address'
    ];
}
