<?php

namespace App\Services;

use App\Models\SteamAchievement;
use App\Models\SteamGame;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SteamAchievementClient
{
    public function syncLibrary(): int
    {
        $key = $this->apiKey();
        $steamId = $this->steamId();

        $payload = $this->http()->get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/', [
            'key' => $key,
            'steamid' => $steamId,
            'include_appinfo' => true,
            'include_played_free_games' => true,
            'format' => 'json',
        ])->throw()->json('response.games', []);

        foreach ($payload as $game) {
            SteamGame::updateOrCreate(
                ['appid' => $game['appid']],
                [
                    'name' => $game['name'] ?? 'Unknown game',
                    'img_icon_url' => $game['img_icon_url'] ?? null,
                    'playtime_forever' => $game['playtime_forever'] ?? 0,
                    'playtime_2weeks' => $game['playtime_2weeks'] ?? 0,
                    'last_played_at' => ($game['rtime_last_played'] ?? 0) > 0
                        ? Carbon::createFromTimestamp($game['rtime_last_played'])
                        : null,
                    'synced_at' => now(),
                ],
            );
        }

        if (! SteamGame::where('is_current', true)->exists()) {
            SteamGame::orderByDesc('playtime_2weeks')
                ->orderByDesc('playtime_forever')
                ->first()
                ?->update(['is_current' => true]);
        }

        return count($payload);
    }

    public function syncAchievements(SteamGame $game): SteamGame
    {
        $schemaAchievements = $this->schemaAchievements($game->appid);

        if ($schemaAchievements === []) {
            $game->update([
                'achievements_total' => 0,
                'achievements_unlocked' => 0,
                'achievements_synced_at' => now(),
            ]);

            return $game->refresh();
        }

        $playerAchievements = collect($this->playerAchievements($game->appid))->keyBy('apiname');
        $globalPercentages = collect($this->globalPercentages($game->appid))->keyBy('name');

        DB::transaction(function () use ($game, $schemaAchievements, $playerAchievements, $globalPercentages): void {
            foreach ($schemaAchievements as $achievement) {
                $player = $playerAchievements->get($achievement['name'], []);
                $global = $globalPercentages->get($achievement['name'], []);

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
                    ],
                );
            }

            $game->update([
                'achievements_total' => count($schemaAchievements),
                'achievements_unlocked' => $game->achievements()->where('achieved', true)->count(),
                'achievements_synced_at' => now(),
            ]);
        });

        return $game->refresh();
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
            'remaining' => SteamGame::whereNull('achievements_synced_at')->count(),
        ];
    }

    private function schemaAchievements(int $appid): array
    {
        return $this->http()->get('https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v2/', [
            'key' => $this->apiKey(),
            'appid' => $appid,
            'l' => config('services.steam.language'),
            'format' => 'json',
        ])->throw()->json('game.availableGameStats.achievements', []);
    }

    private function playerAchievements(int $appid): array
    {
        $response = $this->http()->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v1/', [
            'key' => $this->apiKey(),
            'steamid' => $this->steamId(),
            'appid' => $appid,
            'l' => config('services.steam.language'),
            'format' => 'json',
        ])->throw()->json('playerstats', []);

        return ($response['success'] ?? true) ? ($response['achievements'] ?? []) : [];
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
        $steamId = (string) config('services.steam.steam_id');

        if ($steamId === '') {
            throw new RuntimeException('STEAM_ID is missing.');
        }

        return $steamId;
    }
}
