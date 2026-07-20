<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    'last_played_at',
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
            'last_played_at' => 'datetime',
            'achievements_synced_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(SteamAchievement::class);
    }

    public function huntSetting(): HasOne
    {
        return $this->hasOne(GameHuntSetting::class);
    }

    public function progressSnapshots(): HasMany
    {
        return $this->hasMany(ProgressSnapshot::class);
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

    public function getIsCompletedAttribute(): bool
    {
        return $this->achievements_total > 0 && $this->achievements_unlocked >= $this->achievements_total;
    }

    public function getPlaytimeHoursAttribute(): string
    {
        return number_format($this->playtime_forever / 60, 1);
    }

    public function getLastPlayedLabelAttribute(): ?string
    {
        return $this->last_played_at?->format('M j, Y');
    }

    public function getSteamUrlAttribute(): string
    {
        return "https://store.steampowered.com/app/{$this->appid}";
    }

    public function getGuidesUrlAttribute(): string
    {
        return "https://steamcommunity.com/app/{$this->appid}/guides/";
    }

    public function getAchievementsUrlAttribute(): string
    {
        return "https://steamcommunity.com/stats/{$this->appid}/achievements/";
    }
}
