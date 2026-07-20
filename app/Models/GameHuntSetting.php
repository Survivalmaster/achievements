<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['steam_game_id', 'note', 'tags', 'difficulty', 'archived'])]
class GameHuntSetting extends Model
{
    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(SteamGame::class, 'steam_game_id');
    }
}
