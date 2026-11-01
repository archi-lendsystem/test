<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\UserManagement\Models\User;

class ApiLog extends Model
{
    protected $table = 'api_logs';

    protected $fillable = [
        'request_id',
        'endpoint',
        'method',
        'user_id',
        'ip_address',
        'request_payload',
        'response_payload',
        'status_code',
        'response_time',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'status_code' => 'integer',
        'response_time' => 'decimal:3',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}