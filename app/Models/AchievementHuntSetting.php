<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['steam_achievement_id', 'status', 'note', 'tags'])]
class AchievementHuntSetting extends Model
{
    public const STATUSES = ['none', 'target', 'later', 'ignore'];

    public function achievement(): BelongsTo
    {
        return $this->belongsTo(SteamAchievement::class, 'steam_achievement_id');
    }
}
