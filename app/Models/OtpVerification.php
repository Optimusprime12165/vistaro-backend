<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpVerification extends Model
{
    protected $fillable = ['mobile', 'purpose', 'code_hash', 'expires_at', 'verified_at', 'invalidated_at', 'failed_attempts', 'ip'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'verified_at' => 'datetime', 'invalidated_at' => 'datetime'];
    }
}
