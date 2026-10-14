<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'mobile', 'ip', 'user_agent', 'method'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
