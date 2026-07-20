<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    'progress_current',
    'progress_target',
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
            'progress_current' => 'integer',
            'progress_target' => 'integer',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(SteamGame::class, 'steam_game_id');
    }

    public function huntSetting(): HasOne
    {
        return $this->hasOne(AchievementHuntSetting::class);
    }

    public function getDisplayIconAttribute(): ?string
    {
        $icon = $this->achieved ? $this->icon : ($this->icongray ?: $this->icon);

        if (! $icon) {
            return null;
        }

        return str_replace('http://', 'https://', $icon);
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

    public function getHasProgressAttribute(): bool
    {
        return $this->progress_current_value !== null
            && $this->progress_target_value !== null
            && $this->progress_target_value > 0
            && $this->progress_current_value < $this->progress_target_value;
    }

    public function getProgressPercentAttribute(): int
    {
        if (! $this->has_progress) {
            return 0;
        }

        return min(100, (int) round(($this->progress_current_value / $this->progress_target_value) * 100));
    }

    public function getProgressCurrentValueAttribute(): ?int
    {
        if ($this->progress_current !== null) {
            return $this->progress_current;
        }

        return $this->progress_target_value !== null && ! $this->achieved ? 0 : null;
    }

    public function getProgressTargetValueAttribute(): ?int
    {
        if ($this->progress_target !== null) {
            return $this->progress_target;
        }

        if ($this->achieved || ! $this->description) {
            return null;
        }

        if (! preg_match_all('/\b\d{1,9}\b/', $this->description, $matches)) {
            return null;
        }

        $target = max(array_map('intval', $matches[0]));

        return $target > 1 ? $target : null;
    }
}
