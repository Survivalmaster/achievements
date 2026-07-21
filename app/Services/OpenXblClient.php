<?php

namespace App\Services;

use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\User;
use App\Models\UserPlatformAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class OpenXblClient
{
    private const BASE_URL = 'https://api.xbl.io/v2';

    public function link(User $user, ?string $apiKey = null): UserPlatformAccount
    {
        $apiKey = trim((string) ($apiKey ?: config('services.openxbl.api_key')));

        if ($apiKey === '') {
            throw new RuntimeException('Paste an OpenXBL API key to link Xbox.');
        }

        $profile = $this->accountProfile($apiKey);

        return UserPlatformAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => SteamGame::PLATFORM_XBOX,
            ],
            [
                'account_id' => $profile['xuid'],
                'display_name' => $profile['gamertag'],
                'access_token' => $apiKey,
                'linked_at' => now(),
                'meta' => [
                    'gamerpic' => $profile['gamerpic'],
                    'gamerscore' => $profile['gamerscore'],
                ],
            ],
        );
    }

    public function syncLibrary(User $user): int
    {
        $account = $this->account($user);
        $payload = $this->http($account)
            ->get(self::BASE_URL.'/titles')
            ->throw()
            ->json('content.titles', []);
        $count = 0;

        foreach ($payload as $title) {
            if (($title['type'] ?? null) !== 'Game') {
                continue;
            }

            $this->upsertTitle($user, $title);
            $count++;
        }

        $account->update(['synced_at' => now()]);

        if (! SteamGame::query()->where('user_id', $user->id)->where('is_current', true)->exists()) {
            SteamGame::query()
                ->where('user_id', $user->id)
                ->where('platform', SteamGame::PLATFORM_XBOX)
                ->orderByDesc('last_played_at')
                ->first()
                ?->update(['is_current' => true]);
        }

        return $count;
    }

    public function syncGame(SteamGame $game): SteamGame
    {
        if ($game->platform_key !== SteamGame::PLATFORM_XBOX) {
            throw new RuntimeException('This is not an Xbox game.');
        }

        $game->loadMissing('user');
        $account = $this->account($game->user);
        $xuid = $account->account_id;
        $titleId = $game->external_id ?: (string) $game->appid;

        if (! $xuid || ! $titleId) {
            throw new RuntimeException('This Xbox game is missing its title ID. Sync Xbox again.');
        }

        $achievements = collect($this->playerAchievements($account, $xuid, $titleId));

        DB::transaction(function () use ($game, $achievements): void {
            foreach ($achievements as $achievement) {
                $progress = $this->progress($achievement);
                $icon = $this->assetUrl($achievement);
                $unlockTime = $this->unlockTimestamp(Arr::get($achievement, 'progression.timeUnlocked'));

                SteamAchievement::query()->updateOrCreate(
                    [
                        'steam_game_id' => $game->id,
                        'apiname' => 'xbox:'.$achievement['id'],
                    ],
                    [
                        'name' => $achievement['name'] ?? 'Xbox achievement '.$achievement['id'],
                        'description' => $achievement['description'] ?? $achievement['lockedDescription'] ?? null,
                        'icon' => $icon,
                        'icongray' => $icon,
                        'hidden' => (bool) ($achievement['isSecret'] ?? false),
                        'achieved' => ($achievement['progressState'] ?? null) === 'Achieved',
                        'unlock_time' => $unlockTime,
                        'global_percent' => Arr::has($achievement, 'rarity.currentPercentage') ? round((float) Arr::get($achievement, 'rarity.currentPercentage'), 3) : null,
                        'progress_current' => $progress['current'],
                        'progress_target' => $progress['target'],
                    ],
                );
            }

            $game->update([
                'achievements_total' => $achievements->count(),
                'achievements_unlocked' => $game->achievements()->where('achieved', true)->count(),
                'achievements_synced_at' => now(),
            ]);
        });

        $game = $game->refresh();
        $this->snapshot($game);

        return $game;
    }

    /**
     * @return array{attempted:int,synced:int,failed:int}
     */
    public function refreshActiveAchievementBatch(User $user, int $limit = 10): array
    {
        $limit = max(1, min($limit, 25));
        $games = $this->activeRefreshGames($user, $limit);
        $synced = 0;
        $failed = 0;

        foreach ($games as $game) {
            try {
                $this->syncGame($game);
                $synced++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return [
            'attempted' => $games->count(),
            'synced' => $synced,
            'failed' => $failed,
        ];
    }

    private function upsertTitle(User $user, array $title): SteamGame
    {
        $titleId = (string) ($title['titleId'] ?? '');

        if ($titleId === '') {
            throw new RuntimeException('OpenXBL returned a title without an Xbox title ID.');
        }

        $achievement = $title['achievement'] ?? [];
        $history = $title['titleHistory'] ?? [];
        $currentAchievements = (int) ($achievement['currentAchievements'] ?? 0);
        $totalAchievements = (int) ($achievement['totalAchievements'] ?? 0);
        $totalGamerscore = (int) ($achievement['totalGamerscore'] ?? 0);
        $currentGamerscore = (int) ($achievement['currentGamerscore'] ?? 0);
        $lookup = [
            'user_id' => $user->id,
            'platform' => SteamGame::PLATFORM_XBOX,
            'appid' => $this->numericId($titleId),
        ];
        $values = [
            'platform' => SteamGame::PLATFORM_XBOX,
            'external_id' => $titleId,
            'name' => $title['name'] ?? 'Unknown Xbox title',
            'img_icon_url' => $this->normalizeUrl($title['displayImage'] ?? null),
            'playtime_forever' => $this->minutesPlayed($title),
            'playtime_2weeks' => 0,
            'achievements_total' => $totalAchievements,
            'achievements_unlocked' => $currentAchievements,
            'last_played_at' => isset($history['lastTimePlayed']) ? Carbon::parse($history['lastTimePlayed']) : null,
            'platform_meta' => [
                'titleId' => $titleId,
                'serviceConfigId' => $title['serviceConfigId'] ?? null,
                'devices' => $title['devices'] ?? [],
                'currentGamerscore' => $currentGamerscore,
                'totalGamerscore' => $totalGamerscore,
                'progressPercentage' => $achievement['progressPercentage'] ?? null,
                'visible' => (bool) ($history['visible'] ?? true),
                'gamePass' => (bool) Arr::get($title, 'gamePass.isGamePass', false),
            ],
            'synced_at' => now(),
        ];

        if (! Schema::hasColumn('steam_games', 'external_id')) {
            unset($values['external_id'], $values['platform_meta']);
        }

        return SteamGame::query()->updateOrCreate($lookup, $values);
    }

    private function account(User $user): UserPlatformAccount
    {
        $account = UserPlatformAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', SteamGame::PLATFORM_XBOX)
            ->first();

        if (! $account) {
            return $this->link($user);
        }

        if (! $account->access_token && config('services.openxbl.api_key')) {
            $account->update(['access_token' => config('services.openxbl.api_key')]);
            $account->refresh();
        }

        if (! $account->access_token) {
            throw new RuntimeException('Link Xbox with an OpenXBL API key first.');
        }

        return $account;
    }

    /**
     * @return Collection<int, SteamGame>
     */
    private function activeRefreshGames(User $user, int $limit): Collection
    {
        return SteamGame::query()
            ->where('user_id', $user->id)
            ->where('platform', SteamGame::PLATFORM_XBOX)
            ->where(function ($query): void {
                $query->whereNull('achievements_synced_at')
                    ->orWhere('achievements_synced_at', '<=', now()->subMinutes(55));
            })
            ->where(function ($query): void {
                $query->where('achievements_total', '>', 0)
                    ->orWhereNull('achievements_synced_at');
            })
            ->orderByDesc('is_current')
            ->orderByDesc('last_played_at')
            ->orderByRaw('case when achievements_total > 0 and achievements_unlocked < achievements_total then 0 else 1 end asc')
            ->orderBy('achievements_synced_at')
            ->limit($limit)
            ->get();
    }

    private function accountProfile(string $apiKey): array
    {
        $profile = $this->http($apiKey)
            ->get(self::BASE_URL.'/account')
            ->throw()
            ->json('content.profileUsers.0', []);
        $settings = collect($profile['settings'] ?? [])->keyBy('id');
        $xuid = (string) ($profile['id'] ?? '');
        $gamertag = (string) ($settings->get('UniqueModernGamertag')['value'] ?? $settings->get('Gamertag')['value'] ?? '');

        if ($xuid === '' || $gamertag === '') {
            throw new RuntimeException('OpenXBL did not return a usable Xbox profile for that key.');
        }

        return [
            'xuid' => $xuid,
            'gamertag' => $gamertag,
            'gamerpic' => $this->normalizeUrl($settings->get('GameDisplayPicRaw')['value'] ?? null),
            'gamerscore' => $settings->get('Gamerscore')['value'] ?? null,
        ];
    }

    private function playerAchievements(UserPlatformAccount $account, string $xuid, string $titleId): array
    {
        return $this->http($account)
            ->get(self::BASE_URL."/achievements/player/{$xuid}/{$titleId}")
            ->throw()
            ->json('content.achievements', []);
    }

    private function http(UserPlatformAccount|string $accountOrKey): PendingRequest
    {
        $key = $accountOrKey instanceof UserPlatformAccount
            ? (string) $accountOrKey->access_token
            : $accountOrKey;

        return Http::acceptJson()
            ->withHeaders([
                'X-Authorization' => $key,
                'Accept-Language' => config('services.openxbl.language', 'en-US'),
            ])
            ->timeout(30)
            ->retry(2, 500);
    }

    private function progress(array $achievement): array
    {
        $requirements = Arr::get($achievement, 'progression.requirements', []);
        $first = collect($requirements)->first(fn ($requirement) => is_array($requirement) && is_numeric($requirement['target'] ?? null));

        if (! $first) {
            return ['current' => null, 'target' => null];
        }

        $target = (int) $first['target'];
        $current = is_numeric($first['current'] ?? null) ? (int) $first['current'] : null;

        if ($target <= 1) {
            return ['current' => null, 'target' => null];
        }

        return [
            'current' => max(0, min($current ?? 0, $target)),
            'target' => $target,
        ];
    }

    private function assetUrl(array $achievement): ?string
    {
        $asset = collect($achievement['mediaAssets'] ?? [])->first(fn ($asset) => ($asset['type'] ?? null) === 'Icon')
            ?: Arr::first($achievement['mediaAssets'] ?? []);

        return $this->normalizeUrl($asset['url'] ?? null);
    }

    private function unlockTimestamp(?string $value): ?int
    {
        if (! $value || str_starts_with($value, '0001-01-01')) {
            return null;
        }

        return Carbon::parse($value)->timestamp;
    }

    private function minutesPlayed(array $title): int
    {
        $minutes = Arr::get($title, 'stats.minutesPlayed')
            ?? Arr::get($title, 'stats.MinutesPlayed')
            ?? Arr::get($title, 'stats.timePlayedMinutes');

        return is_numeric($minutes) ? (int) $minutes : 0;
    }

    private function numericId(string $externalId): int
    {
        return (int) sprintf('%u', crc32('xbox:'.$externalId));
    }

    private function normalizeUrl(?string $url): ?string
    {
        return $url ? str_replace('http://', 'https://', $url) : null;
    }

    private function snapshot(SteamGame $game): void
    {
        if ($game->achievements_total === 0) {
            return;
        }

        $latest = $game->progressSnapshots()->latest('taken_at')->first();

        if (
            $latest
            && $latest->taken_at->isToday()
            && $latest->achievements_unlocked === $game->achievements_unlocked
            && $latest->achievements_total === $game->achievements_total
        ) {
            return;
        }

        $game->progressSnapshots()->create([
            'achievements_total' => $game->achievements_total,
            'achievements_unlocked' => $game->achievements_unlocked,
            'completion_percent' => $game->completion_percent,
            'taken_at' => now(),
        ]);
    }
}
