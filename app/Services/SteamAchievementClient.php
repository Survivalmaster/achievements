<?php

namespace App\Services;

use App\Models\SteamAchievement;
use App\Models\SteamGame;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class SteamAchievementClient
{
    public function syncLibrary(): int
    {
        $key = $this->apiKey();
        $steamId = $this->steamId();
        $userId = Auth::id();

        $payload = $this->http()->get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
            'key' => $key,
            'steamid' => $steamId,
            'include_appinfo' => true,
            'include_played_free_games' => true,
            'format' => 'json',
        ])->throw()->json('response.games', []);
        $supportsPlatform = Schema::hasColumn('steam_games', 'platform');

        foreach ($payload as $game) {
            $lookup = ['user_id' => $userId, 'appid' => $game['appid']];
            $values = [
                'name' => $game['name'] ?? 'Unknown game',
                'img_icon_url' => $game['img_icon_url'] ?? null,
                'playtime_forever' => $game['playtime_forever'] ?? 0,
                'playtime_2weeks' => $game['playtime_2weeks'] ?? 0,
                'last_played_at' => ($game['rtime_last_played'] ?? 0) > 0
                    ? Carbon::createFromTimestamp($game['rtime_last_played'])
                    : null,
                'synced_at' => now(),
            ];

            if ($supportsPlatform) {
                $lookup['platform'] = SteamGame::PLATFORM_STEAM;
                $values['platform'] = SteamGame::PLATFORM_STEAM;
            }

            SteamGame::updateOrCreate(
                $lookup,
                $values,
            );
        }

        if (! SteamGame::where('user_id', $userId)->where('is_current', true)->exists()) {
            SteamGame::where('user_id', $userId)
                ->orderByDesc('playtime_2weeks')
                ->orderByDesc('playtime_forever')
                ->first()
                ?->update(['is_current' => true]);
        }

        return count($payload);
    }

    public function syncAchievements(SteamGame $game): SteamGame
    {
        $schema = $this->schema($game->appid);
        $schemaAchievements = $schema['achievements'] ?? [];

        if ($schemaAchievements === []) {
            $game->update([
                'achievements_total' => 0,
                'achievements_unlocked' => 0,
                'achievements_synced_at' => now(),
            ]);

            return $game->refresh();
        }

        $playerAchievements = collect($this->playerAchievements($game->appid))->keyBy('apiname');
        $playerStats = collect($this->playerStats($game->appid))->keyBy('name');
        $schemaStats = collect($schema['stats'] ?? [])->keyBy('name');
        $globalPercentages = collect($this->globalPercentages($game->appid))->keyBy('name');
        $communityProgress = $this->communityAchievementProgress($game->appid);

        DB::transaction(function () use ($game, $schemaAchievements, $playerAchievements, $playerStats, $schemaStats, $globalPercentages, $communityProgress): void {
            foreach ($schemaAchievements as $achievement) {
                $player = $playerAchievements->get($achievement['name'], []);
                $global = $globalPercentages->get($achievement['name'], []);
                $progress = $this->achievementProgress($achievement, $player, $playerStats, $schemaStats);
                $progress = $this->mergeCommunityProgress($progress, $achievement, $communityProgress);

                SteamAchievement::updateOrCreate(
                    [
                        'steam_game_id' => $game->id,
                        'apiname' => $achievement['name'],
                    ],
                    [
                        'name' => $achievement['displayName'] ?? $achievement['name'],
                        'description' => $achievement['description'] ?? null,
                        'icon' => $this->normalizeUrl($achievement['icon'] ?? null),
                        'icongray' => $this->normalizeUrl($achievement['icongray'] ?? null),
                        'hidden' => (bool) ($achievement['hidden'] ?? false),
                        'achieved' => (bool) ($player['achieved'] ?? false),
                        'unlock_time' => ($player['unlocktime'] ?? 0) > 0 ? $player['unlocktime'] : null,
                        'global_percent' => Arr::has($global, 'percent') ? round((float) $global['percent'], 3) : null,
                        'progress_current' => $progress['current'],
                        'progress_target' => $progress['target'],
                    ],
                );
            }

            $game->update([
                'achievements_total' => count($schemaAchievements),
                'achievements_unlocked' => $game->achievements()->where('achieved', true)->count(),
                'achievements_synced_at' => now(),
            ]);
        });

        $game = $game->refresh();
        $this->snapshot($game);

        return $game;
    }

    /**
     * @return array{attempted:int,synced:int,failed:int,remaining:int}
     */
    public function syncAchievementBatch(int $limit = 15): array
    {
        $limit = max(1, min($limit, 50));
        $this->apiKey();
        $this->steamId();

        $games = SteamGame::query()
            ->where('user_id', Auth::id())
            ->whereNull('achievements_synced_at')
            ->orderByDesc('is_current')
            ->orderByDesc('playtime_2weeks')
            ->orderByDesc('playtime_forever')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $synced = 0;
        $failed = 0;

        foreach ($games as $game) {
            try {
                $this->syncAchievements($game);
                $synced++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return [
            'attempted' => $games->count(),
            'synced' => $synced,
            'failed' => $failed,
            'remaining' => SteamGame::where('user_id', Auth::id())->whereNull('achievements_synced_at')->count(),
        ];
    }

    /**
     * @return array{attempted:int,synced:int,failed:int}
     */
    public function refreshActiveAchievementBatch(int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        $this->apiKey();
        $this->steamId();

        $games = $this->activeRefreshGames($limit);
        $synced = 0;
        $failed = 0;

        foreach ($games as $game) {
            try {
                $this->syncAchievements($game);
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

    /**
     * @return array{attempted:int,synced:int,failed:int,remaining:int,next_after_id:int,total:int}
     */
    public function refreshAllAchievementBatch(int $afterId = 0, int $limit = 15): array
    {
        $limit = max(1, min($limit, 50));
        $this->apiKey();
        $this->steamId();

        $baseQuery = $this->refreshableGamesQuery();
        $total = (clone $baseQuery)->count();
        $games = (clone $baseQuery)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $synced = 0;
        $failed = 0;
        $nextAfterId = $afterId;

        foreach ($games as $game) {
            $nextAfterId = max($nextAfterId, $game->id);

            try {
                $this->syncAchievements($game);
                $synced++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return [
            'attempted' => $games->count(),
            'synced' => $synced,
            'failed' => $failed,
            'remaining' => (clone $baseQuery)->where('id', '>', $nextAfterId)->count(),
            'next_after_id' => $nextAfterId,
            'total' => $total,
        ];
    }

    /**
     * @return Collection<int, SteamGame>
     */
    private function activeRefreshGames(int $limit): Collection
    {
        return SteamGame::query()
            ->where('user_id', Auth::id())
            ->where('achievements_total', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('achievements_synced_at')
                    ->orWhere('is_current', true)
                    ->orWhere('last_played_at', '>=', now()->subDays(30))
                    ->orWhereColumn('achievements_unlocked', '<', 'achievements_total');
            })
            ->orderByRaw('case when achievements_synced_at is null then 0 else 1 end asc')
            ->orderByDesc('is_current')
            ->orderByDesc('last_played_at')
            ->orderByRaw('case when achievements_total > 0 and achievements_unlocked < achievements_total then 0 else 1 end asc')
            ->orderBy('achievements_synced_at')
            ->limit($limit)
            ->get();
    }

    private function refreshableGamesQuery()
    {
        return SteamGame::query()
            ->where('user_id', Auth::id())
            ->where(function ($query): void {
                $query->where('achievements_total', '>', 0)
                    ->orWhereNull('achievements_synced_at');
            });
    }

    public function playerSummary(string $steamId): array
    {
        return $this->http()->get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/', [
            'key' => $this->apiKey(),
            'steamids' => $steamId,
            'format' => 'json',
        ])->throw()->json('response.players.0', []);
    }

    public function friendSummaries(): array
    {
        $friends = $this->http()->get('https://api.steampowered.com/ISteamUser/GetFriendList/v1/', [
            'key' => $this->apiKey(),
            'steamid' => $this->steamId(),
            'relationship' => 'friend',
            'format' => 'json',
        ])->throw()->json('friendslist.friends', []);

        $steamIds = collect($friends)->pluck('steamid')->filter()->take(50)->implode(',');

        if ($steamIds === '') {
            return [];
        }

        return $this->http()->get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/', [
            'key' => $this->apiKey(),
            'steamids' => $steamIds,
            'format' => 'json',
        ])->throw()->json('response.players', []);
    }

    public function friendSteamIds(): array
    {
        $friends = $this->http()->get('https://api.steampowered.com/ISteamUser/GetFriendList/v1/', [
            'key' => $this->apiKey(),
            'steamid' => $this->steamId(),
            'relationship' => 'friend',
            'format' => 'json',
        ])->throw()->json('friendslist.friends', []);

        return collect($friends)->pluck('steamid')->filter()->values()->all();
    }

    public function playerAchievementsFor(int $appid, string $steamId): array
    {
        return $this->playerAchievements($appid, $steamId);
    }

    private function schema(int $appid): array
    {
        return $this->http()->get('https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/', [
            'key' => $this->apiKey(),
            'appid' => $appid,
            'l' => config('services.steam.language'),
            'format' => 'json',
        ])->throw()->json('game.availableGameStats', []);
    }

    private function playerAchievements(int $appid, ?string $steamId = null): array
    {
        $response = $this->http()->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v1/', [
            'key' => $this->apiKey(),
            'steamid' => $steamId ?? $this->steamId(),
            'appid' => $appid,
            'l' => config('services.steam.language'),
            'format' => 'json',
        ])->throw()->json('playerstats', []);

        return ($response['success'] ?? true) ? ($response['achievements'] ?? []) : [];
    }

    private function playerStats(int $appid): array
    {
        try {
            $response = $this->http()->get('https://api.steampowered.com/ISteamUserStats/GetUserStatsForGame/v2/', [
                'key' => $this->apiKey(),
                'steamid' => $this->steamId(),
                'appid' => $appid,
                'format' => 'json',
            ])->throw()->json('playerstats', []);
        } catch (Throwable) {
            return [];
        }

        return ($response['success'] ?? true) ? ($response['stats'] ?? []) : [];
    }

    private function achievementProgress(array $achievement, array $player, Collection $playerStats, Collection $schemaStats): array
    {
        $current = $this->firstNumeric($player, ['curprogress', 'currentprogress', 'current', 'progress', 'value']);
        $target = $this->firstNumeric($player, ['maxprogress', 'targetprogress', 'target', 'max']);
        $statName = null;

        if ($current === null) {
            $statName = $this->progressStatName($achievement, $playerStats, $schemaStats);
            $stat = $statName ? $playerStats->get($statName) : null;
            $current = $this->firstNumeric($stat ?? [], ['value']);
        }

        $target ??= $this->firstNumeric($achievement, ['maxprogress', 'targetprogress', 'target', 'max', 'unlockvalue', 'unlock_value', 'threshold']);
        $target ??= $this->targetFromText($achievement['description'] ?? null);

        if ($current === null && $statName && $target !== null) {
            $current = 0;
        }

        if ($current === null || $target === null || $target <= 0) {
            return ['current' => null, 'target' => null];
        }

        return [
            'current' => max(0, min($current, $target)),
            'target' => $target,
        ];
    }

    private function mergeCommunityProgress(array $progress, array $achievement, Collection $communityProgress): array
    {
        if ($progress['current'] !== null && $progress['target'] !== null) {
            return $progress;
        }

        $key = $this->achievementProgressKey($achievement);
        $match = $communityProgress->first(function (array $candidate) use ($key): bool {
            return str_contains($candidate['key'], $key) || str_contains($key, $candidate['key']);
        });

        if (! $match) {
            return $progress;
        }

        return [
            'current' => $match['current'],
            'target' => $match['target'],
        ];
    }

    private function communityAchievementProgress(int $appid): Collection
    {
        try {
            $html = $this->http()->get("https://steamcommunity.com/profiles/{$this->steamId()}/stats/{$appid}/achievements")->throw()->body();
        } catch (Throwable) {
            return collect();
        }

        if ($html === '') {
            return collect();
        }

        return collect($this->communityAchievementBlocks($html))
            ->map(function (string $block): ?array {
                $text = $this->cleanHtmlText($block);

                if (! preg_match('/\b([0-9][0-9,]*)\s*\/\s*([0-9][0-9,]*)\b/', $text, $matches)) {
                    return null;
                }

                $current = (int) str_replace(',', '', $matches[1]);
                $target = (int) str_replace(',', '', $matches[2]);

                if ($target <= 0 || $current >= $target) {
                    return null;
                }

                return [
                    'key' => $this->normalizedStatName(preg_replace('/\b[0-9][0-9,]*\s*\/\s*[0-9][0-9,]*\b/', '', $text) ?? $text),
                    'current' => max(0, min($current, $target)),
                    'target' => $target,
                ];
            })
            ->filter()
            ->values();
    }

    private function communityAchievementBlocks(string $html): array
    {
        preg_match_all('/<div[^>]+class="[^"]*(?:achieveRow|achieveTxt|achieveUnlockTime|achieveInfo)[^"]*"[^>]*>.*?(?=<div[^>]+class="[^"]*(?:achieveRow|achieveTxt|achieveUnlockTime|achieveInfo)[^"]*"|$)/is', $html, $matches);

        if (($matches[0] ?? []) !== []) {
            return $matches[0];
        }

        preg_match_all('/<[^>]+>[^<]*(?:[0-9][0-9,]*\s*\/\s*[0-9][0-9,]*)[^<]*(?:<\/[^>]+>)?/i', $html, $matches);

        return $matches[0] ?? [];
    }

    private function achievementProgressKey(array $achievement): string
    {
        return $this->normalizedStatName(implode(' ', array_filter([
            $achievement['displayName'] ?? null,
            $achievement['description'] ?? null,
        ])));
    }

    private function cleanHtmlText(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)) ?? '');
    }

    private function progressStatName(array $achievement, Collection $playerStats, Collection $schemaStats): ?string
    {
        $availableStats = $schemaStats->keys()
            ->merge($playerStats->keys())
            ->filter()
            ->unique()
            ->values();
        $direct = Arr::first([
            $achievement['progressStat'] ?? null,
            $achievement['progress_stat'] ?? null,
            $achievement['progress_stat_name'] ?? null,
            $achievement['stat'] ?? null,
            $achievement['stat_name'] ?? null,
        ], fn ($value) => is_string($value) && $value !== '');

        if ($direct && $availableStats->contains($direct)) {
            return $direct;
        }

        $candidates = collect([
            $achievement['name'] ?? null,
            $achievement['displayName'] ?? null,
            $achievement['description'] ?? null,
        ])
            ->filter()
            ->map(fn (string $value): string => $this->normalizedStatName($value));

        foreach ($availableStats as $name) {
            $normalized = $this->normalizedStatName((string) $name);

            if ($candidates->contains($normalized)) {
                return (string) $name;
            }
        }

        return $this->bestProgressStatName($achievement, $availableStats);
    }

    private function bestProgressStatName(array $achievement, Collection $availableStats): ?string
    {
        $tokens = $this->progressTokens(implode(' ', array_filter([
            $achievement['name'] ?? null,
            $achievement['displayName'] ?? null,
            $achievement['description'] ?? null,
        ])));

        if ($tokens === []) {
            return null;
        }

        $bestName = null;
        $bestScore = 0;

        foreach ($availableStats as $name) {
            $stat = $this->normalizedStatName((string) $name);
            $score = 0;

            foreach ($tokens as $token) {
                if (str_contains($stat, $token)) {
                    $score += strlen($token) >= 5 ? 2 : 1;
                }
            }

            if ($score > $bestScore) {
                $bestName = (string) $name;
                $bestScore = $score;
            }
        }

        return $bestScore >= 3 ? $bestName : null;
    }

    private function progressTokens(string $value): array
    {
        preg_match_all('/[a-z0-9]+/i', strtolower($value), $matches);

        $stopWords = [
            'achievement', 'achievements', 'and', 'buy', 'cars', 'complete', 'completed',
            'for', 'from', 'get', 'iii', 'into', 'sell', 'sold', 'the', 'toy', 'vehicle',
            'vehicles', 'with',
        ];

        return collect($matches[0] ?? [])
            ->reject(fn (string $token): bool => is_numeric($token) || strlen($token) < 3 || in_array($token, $stopWords, true))
            ->map(fn (string $token): string => rtrim($token, 's'))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizedStatName(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }

    private function firstNumeric(?array $source, array $keys): ?int
    {
        if (! $source) {
            return null;
        }

        foreach ($keys as $key) {
            if (Arr::has($source, $key) && is_numeric($source[$key])) {
                return (int) $source[$key];
            }
        }

        return null;
    }

    private function targetFromText(?string $text): ?int
    {
        if (! $text || ! preg_match_all('/\b\d{1,9}\b/', $text, $matches)) {
            return null;
        }

        $numbers = array_map('intval', $matches[0]);

        return max($numbers) ?: null;
    }

    private function globalPercentages(int $appid): array
    {
        return $this->http()->get('https://api.steampowered.com/ISteamUserStats/GetGlobalAchievementPercentagesForApp/v2/', [
            'gameid' => $appid,
            'format' => 'json',
        ])->throw()->json('achievementpercentages.achievements', []);
    }

    private function http(): PendingRequest
    {
        return Http::timeout(20)->retry(2, 300);
    }

    private function normalizeUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        return str_replace('http://', 'https://', $url);
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

    private function apiKey(): string
    {
        $key = (string) config('services.steam.api_key');

        if ($key === '') {
            throw new RuntimeException('STEAM_API_KEY is missing.');
        }

        return $key;
    }

    private function steamId(): string
    {
        $steamId = (string) (Auth::user()?->steam_id ?? config('services.steam.steam_id'));

        if ($steamId === '') {
            throw new RuntimeException('STEAM_ID is missing.');
        }

        return $steamId;
    }
}
