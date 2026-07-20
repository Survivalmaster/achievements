<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'steam_game_id',
    'apiname',
    'name',
    'description',
    'icon',
    'icongray',
    'hidden',
    'achieved',
    'unlock_time',
    'global_percent',
])]
class SteamAchievement extends Model
{
    protected function casts(): array
    {
        return [
            'hidden' => 'boolean',
            'achieved' => 'boolean',
            'unlock_time' => 'integer',
            'global_percent' => 'decimal:3',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(SteamGame::class, 'steam_game_id');
    }

    public function getDisplayIconAttribute(): ?string
    {
        return $this->achieved ? $this->icon : ($this->icongray ?: $this->icon);
    }

    public function getRarityClassAttribute(): string
    {
        $percent = (float) $this->global_percent;

        return match (true) {
            $percent > 0 && $percent <= 3 => 'rarity-mythic',
            $percent > 0 && $percent <= 10 => 'rarity-rare',
            default => '',
        };
    }
}
