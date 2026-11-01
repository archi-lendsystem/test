<?php

namespace Modules\UserManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class LoginOtpVerification extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'otp',
        'channel',
        'expires_at',
        'used',
        'session_id',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used'       => 'boolean',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }


    public function scopeValid($query)
    {
        return $query
            ->where('used', false)
            ->where('expires_at', '>', Carbon::now('UTC'));
    }

   
    public function scopeForSession($query, string $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

   
    public function scopeForIp($query, string $ip)
    {
        return $query->where('ip_address', $ip);
    }
}
