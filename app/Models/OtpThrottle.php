<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpThrottle extends Model
{
    protected $fillable = ['mobile', 'purpose', 'window_started_at', 'issuance_count', 'next_allowed_at'];

    protected function casts(): array
    {
        return ['window_started_at' => 'datetime', 'next_allowed_at' => 'datetime'];
    }
}
