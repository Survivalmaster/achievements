<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'platform',
    'account_id',
    'display_name',
    'access_token',
    'refresh_token',
    'npsso',
    'token_expires_at',
    'linked_at',
    'synced_at',
    'meta',
])]
#[Hidden(['access_token', 'refresh_token', 'npsso'])]
class UserPlatformAccount extends Model
{
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'npsso' => 'encrypted',
            'token_expires_at' => 'datetime',
            'linked_at' => 'datetime',
            'synced_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
