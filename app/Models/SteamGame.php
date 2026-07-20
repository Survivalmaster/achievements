<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'appid',
    'name',
    'img_icon_url',
    'playtime_forever',
    'playtime_2weeks',
    'achievements_total',
    'achievements_unlocked',
    'is_current',
    'achievements_synced_at',
    'synced_at',
])]
class SteamGame extends Model
{
    protected function casts(): array
    {
        return [
            'appid' => 'integer',
            'playtime_forever' => 'integer',
            'playtime_2weeks' => 'integer',
            'achievements_total' => 'integer',
            'achievements_unlocked' => 'integer',
            'is_current' => 'boolean',
            'achievements_synced_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(SteamAchievement::class);
    }

    public function getIconUrlAttribute(): ?string
    {
        if (! $this->img_icon_url) {
            return null;
        }

        return "https://media.steampowered.com/steamcommunity/public/images/apps/{$this->appid}/{$this->img_icon_url}.jpg";
    }

    public function getCompletionPercentAttribute(): int
    {
        if ($this->achievements_total === 0) {
            return 0;
        }

        return (int) round(($this->achievements_unlocked / $this->achievements_total) * 100);
    }

    public function getPlaytimeHoursAttribute(): string
    {
        return number_format($this->playtime_forever / 60, 1);
    }
}
