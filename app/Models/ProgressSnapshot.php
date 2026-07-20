<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['steam_game_id', 'achievements_total', 'achievements_unlocked', 'completion_percent', 'taken_at'])]
class ProgressSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(SteamGame::class, 'steam_game_id');
    }
}
