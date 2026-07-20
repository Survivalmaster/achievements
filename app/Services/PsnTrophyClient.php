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

class PsnTrophyClient
{
    private const AUTH_BASE_URL = 'https://ca.account.sony.com/api/authz/v3/oauth';
    private const TROPHY_BASE_URL = 'https://m.np.playstation.com/api/trophy';
    private const CLIENT_AUTH = 'Basic MDk1MTUxNTktNzIzNy00MzcwLTliNDAtMzgwNmU2N2MwODkxOnVjUGprYTV0bnRCMktxc1A=';

    public function link(User $user, string $npsso): UserPlatformAccount
    {
        $npsso = trim($npsso);

        if ($npsso === '') {
            throw new RuntimeException('Paste your PSN NPSSO token to link PlayStation.');
        }

        $tokens = $this->tokensFromNpsso($npsso);

        return UserPlatformAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => SteamGame::PLATFORM_PSN,
            ],
            [
                'display_name' => 'PlayStation Network',
                'access_token' => $tokens['accessToken'],
                'refresh_token' => $tokens['refreshToken'],
                'npsso' => $npsso,
                'token_expires_at' => now()->addSeconds(max(60, (int) $tokens['expiresIn'] - 60)),
                'linked_at' => now(),
            ],
        );
    }

    public function syncLibrary(User $user): int
    {
        $account = $this->account($user);
        $offset = 0;
        $limit = 800;
        $count = 0;

        do {
            $payload = $this->http($account)
                ->get(self::TROPHY_BASE_URL.'/v1/users/me/trophyTitles', [
                    'limit' => $limit,
                    'offset' => $offset,
                ])
                ->throw()
                ->json();
            $titles = $payload['trophyTitles'] ?? [];

            foreach ($titles as $title) {
                $this->upsertTitle($user, $title);
                $count++;
            }

            $offset = isset($payload['nextOffset']) ? (int) $payload['nextOffset'] : null;
        } while ($offset !== null && $offset > 0);

        $account->update(['synced_at' => now()]);

        if (! SteamGame::query()->where('user_id', $user->id)->where('is_current', true)->exists()) {
            SteamGame::query()
                ->where('user_id', $user->id)
                ->where('platform', SteamGame::PLATFORM_PSN)
                ->orderByDesc('last_played_at')
                ->first()
                ?->update(['is_current' => true]);
        }

        return $count;
    }

    public function syncGame(SteamGame $game): SteamGame
    {
        if ($game->platform_key !== SteamGame::PLATFORM_PSN) {
            throw new RuntimeException('This is not a PlayStation game.');
        }

        $game->loadMissing('user');
        $account = $this->account($game->user);
        $npCommunicationId = $game->external_id ?: ($game->platform_meta['npCommunicationId'] ?? null);

        if (! $npCommunicationId) {
            throw new RuntimeException('This PlayStation title is missing its trophy set ID. Sync PSN library again.');
        }

        $npServiceName = $game->platform_meta['npServiceName'] ?? 'trophy2';
        $params = ['npServiceName' => $npServiceName];
        $titlePayload = $this->http($account)
            ->get(self::TROPHY_BASE_URL."/v1/npCommunicationIds/{$npCommunicationId}/trophyGroups/all/trophies", $params)
            ->throw()
            ->json();
        $earnedPayload = $this->http($account)
            ->get(self::TROPHY_BASE_URL."/v1/users/me/npCommunicationIds/{$npCommunicationId}/trophyGroups/all/trophies", $params)
            ->throw()
            ->json();

        $titleTrophies = collect($titlePayload['trophies'] ?? [])->keyBy('trophyId');
        $earnedTrophies = collect($earnedPayload['trophies'] ?? [])->keyBy('trophyId');

        DB::transaction(function () use ($game, $titleTrophies, $earnedTrophies): void {
            foreach ($titleTrophies as $trophyId => $trophy) {
                $earned = $earnedTrophies->get($trophyId, []);
                $target = Arr::get($trophy, 'trophyProgressTargetValue');
                $current = Arr::get($earned, 'progress');

                SteamAchievement::query()->updateOrCreate(
                    [
                        'steam_game_id' => $game->id,
                        'apiname' => 'psn:'.$trophyId,
                    ],
                    [
                        'name' => $trophy['trophyName'] ?? 'Trophy '.$trophyId,
                        'description' => $trophy['trophyDetail'] ?? null,
                        'icon' => $this->normalizeUrl($trophy['trophyIconUrl'] ?? null),
                        'icongray' => $this->normalizeUrl($trophy['trophyIconUrl'] ?? null),
                        'hidden' => (bool) (($earned['trophyHidden'] ?? null) ?? ($trophy['trophyHidden'] ?? false)),
                        'achieved' => (bool) ($earned['earned'] ?? false),
                        'unlock_time' => isset($earned['earnedDateTime']) ? Carbon::parse($earned['earnedDateTime'])->timestamp : null,
                        'global_percent' => isset($earned['trophyEarnedRate']) ? round((float) $earned['trophyEarnedRate'], 3) : null,
                        'progress_current' => is_numeric($current) ? (int) $current : null,
                        'progress_target' => is_numeric($target) ? (int) $target : null,
                    ],
                );
            }

            $game->update([
                'achievements_total' => $titleTrophies->count(),
                'achievements_unlocked' => $game->achievements()->where('achieved', true)->count(),
                'achievements_synced_at' => now(),
            ]);
        });

        return $game->refresh();
    }

    /**
     * @return array{attempted:int,synced:int,failed:int}
     */
    public function refreshActiveTrophyBatch(User $user, int $limit = 10): array
    {
        $limit = max(1, min($limit, 25));
        $games = $this->activeRefreshGames($user, $limit);
        $synced = 0;
        $failed = 0;

        foreach ($games as $game) {
            try {
                $this->syncGame($game);
                $synced++;
            } catch (\Throwable) {
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
        $npCommunicationId = $title['npCommunicationId'] ?? null;

        if (! $npCommunicationId) {
            throw new RuntimeException('PSN returned a trophy title without a trophy set ID.');
        }

        $defined = array_sum(array_map('intval', $title['definedTrophies'] ?? []));
        $earned = array_sum(array_map('intval', $title['earnedTrophies'] ?? []));
        $lookup = [
            'user_id' => $user->id,
            'platform' => SteamGame::PLATFORM_PSN,
            'appid' => $this->numericId($npCommunicationId),
        ];
        $values = [
            'platform' => SteamGame::PLATFORM_PSN,
            'external_id' => $npCommunicationId,
            'name' => $title['trophyTitleName'] ?? 'Unknown PSN title',
            'img_icon_url' => $title['trophyTitleIconUrl'] ?? null,
            'playtime_forever' => 0,
            'playtime_2weeks' => 0,
            'achievements_total' => $defined,
            'achievements_unlocked' => $earned,
            'last_played_at' => isset($title['lastUpdatedDateTime']) ? Carbon::parse($title['lastUpdatedDateTime']) : null,
            'platform_meta' => [
                'npCommunicationId' => $npCommunicationId,
                'npServiceName' => $title['npServiceName'] ?? 'trophy2',
                'trophyTitlePlatform' => $title['trophyTitlePlatform'] ?? null,
                'hasTrophyGroups' => (bool) ($title['hasTrophyGroups'] ?? false),
                'hiddenFlag' => (bool) ($title['hiddenFlag'] ?? false),
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
            ->where('platform', SteamGame::PLATFORM_PSN)
            ->first();

        if (! $account) {
            throw new RuntimeException('Link your PSN account first.');
        }

        if (! $account->access_token || ! $account->token_expires_at || $account->token_expires_at->isPast()) {
            $tokens = $account->refresh_token
                ? $this->tokensFromRefreshToken($account->refresh_token)
                : $this->tokensFromNpsso((string) $account->npsso);

            $account->update([
                'access_token' => $tokens['accessToken'],
                'refresh_token' => $tokens['refreshToken'] ?: $account->refresh_token,
                'token_expires_at' => now()->addSeconds(max(60, (int) $tokens['expiresIn'] - 60)),
            ]);
            $account->refresh();
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
            ->where('platform', SteamGame::PLATFORM_PSN)
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

    private function http(UserPlatformAccount $account): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) $account->access_token)
            ->timeout(25);
    }

    private function tokensFromNpsso(string $npsso): array
    {
        $code = $this->accessCode($npsso);

        return $this->tokenRequest([
            'code' => $code,
            'redirect_uri' => 'com.scee.psxandroid.scecompcall://redirect',
            'grant_type' => 'authorization_code',
            'token_format' => 'jwt',
        ]);
    }

    private function tokensFromRefreshToken(string $refreshToken): array
    {
        return $this->tokenRequest([
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'token_format' => 'jwt',
            'scope' => 'psn:mobile.v2.core psn:clientapp',
        ]);
    }

    private function accessCode(string $npsso): string
    {
        $response = Http::withHeaders(['Cookie' => 'npsso='.$npsso])
            ->withOptions(['allow_redirects' => false])
            ->get(self::AUTH_BASE_URL.'/authorize', [
                'access_type' => 'offline',
                'client_id' => '09515159-7237-4370-9b40-3806e67c0891',
                'redirect_uri' => 'com.scee.psxandroid.scecompcall://redirect',
                'response_type' => 'code',
                'scope' => 'psn:mobile.v2.core psn:clientapp',
            ]);
        $location = $response->header('Location');

        if (! $location || ! str_contains($location, 'code=')) {
            throw new RuntimeException('PSN did not return an access code. Check the NPSSO token and try linking again.');
        }

        parse_str(str_contains($location, '?') ? substr($location, strpos($location, '?') + 1) : '', $query);

        if (empty($query['code'])) {
            throw new RuntimeException('PSN returned a redirect without an access code.');
        }

        return (string) $query['code'];
    }

    private function tokenRequest(array $form): array
    {
        $payload = Http::asForm()
            ->withHeaders(['Authorization' => self::CLIENT_AUTH])
            ->post(self::AUTH_BASE_URL.'/token', $form)
            ->throw()
            ->json();

        if (empty($payload['access_token'])) {
            throw new RuntimeException('PSN did not return a usable access token.');
        }

        return [
            'accessToken' => $payload['access_token'],
            'expiresIn' => $payload['expires_in'] ?? 3600,
            'refreshToken' => $payload['refresh_token'] ?? null,
        ];
    }

    private function numericId(string $externalId): int
    {
        return (int) sprintf('%u', crc32($externalId));
    }

    private function normalizeUrl(?string $url): ?string
    {
        return $url ? str_replace('http://', 'https://', $url) : null;
    }
}
