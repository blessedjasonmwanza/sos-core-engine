<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestLogin extends Model
{
    protected $fillable = [
        'phone_number',
        'otp_code',
        'otp_expires_at',
    ];

    protected $casts = [
        'otp_expires_at' => 'datetime',
    ];
}
