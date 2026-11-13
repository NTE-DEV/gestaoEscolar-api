<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service_name',
        'method',
        'route',
        'status_code',
        'response_time_ms',
        'ip_address',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'response_time_ms' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
