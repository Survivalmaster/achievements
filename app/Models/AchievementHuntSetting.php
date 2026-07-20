<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['steam_achievement_id', 'status', 'note', 'tags', 'manual_progress_current', 'manual_progress_target'])]
class AchievementHuntSetting extends Model
{
    public const STATUSES = ['none', 'target', 'later', 'ignore'];

    protected function casts(): array
    {
        return [
            'manual_progress_current' => 'integer',
            'manual_progress_target' => 'integer',
        ];
    }

    public function achievement(): BelongsTo
    {
        return $this->belongsTo(SteamAchievement::class, 'steam_achievement_id');
    }
}
