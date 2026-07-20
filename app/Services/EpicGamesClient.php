<?php

namespace App\Services;

use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\User;
use App\Models\UserPlatformAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

class EpicGamesClient
{
    private const CLIENT_ID = '34a02cf8f4414e29b15921876da36f9a';
    private const CLIENT_SECRET = 'daafbccc737745039dffe53d94fc76cf';
    private const OAUTH_HOST = 'https://account-public-service-prod03.ol.epicgames.com';
    private const CATALOG_HOST = 'https://catalog-public-service-prod06.ol.epicgames.com';
    private const LIBRARY_HOST = 'https://library-service.live.use1a.on.epicgames.com';
    private const GRAPHQL_URL = 'https://launcher.store.epicgames.com/graphql';

    public function authUrl(): string
    {
        $redirect = 'https://www.epicgames.com/id/api/redirect?'.http_build_query([
            'clientId' => self::CLIENT_ID,
            'responseType' => 'code',
        ]);

        return 'https://www.epicgames.com/id/login?redirectUrl='.rawurlencode($redirect);
    }

    public function link(User $user, string $authorizationCode): UserPlatformAccount
    {
        $tokens = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => trim($authorizationCode),
            'token_type' => 'eg1',
        ]);

        return UserPlatformAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => SteamGame::PLATFORM_EPIC,
            ],
            [
                'account_id' => $tokens['account_id'] ?? null,
                'display_name' => $tokens['displayName'] ?? $tokens['account_id'] ?? 'Epic Games',
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 7200) - 60)),
                'linked_at' => now(),
                'meta' => ['sync_supported' => true],
            ],
        );
    }

    public function syncLibrary(User $user): int
    {
        $account = $this->account($user);
        $count = 0;
        $cursor = null;
        $seenAppIds = [];

        do {
            $query = ['includeMetadata' => 'true'];

            if ($cursor) {
                $query['cursor'] = $cursor;
            }

            $payload = $this->http($account)
                ->get(self::LIBRARY_HOST.'/library/api/public/items', $query)
                ->throw()
                ->json();

            foreach (($payload['records'] ?? []) as $item) {
                if ($this->shouldSkipLibraryItem($item)) {
                    continue;
                }

                $game = $this->upsertLibraryItem($user, $account, $item);

                if ($game) {
                    $seenAppIds[] = $game->appid;
                    $count++;
                }
            }

            $cursor = $payload['responseMetadata']['nextCursor'] ?? null;
        } while ($cursor);

        $account->update(['synced_at' => now()]);

        if ($seenAppIds !== []) {
            SteamGame::query()
                ->where('user_id', $user->id)
                ->where('platform', SteamGame::PLATFORM_EPIC)
                ->whereNotIn('appid', array_unique($seenAppIds))
                ->delete();
        }

        return $count;
    }

    public function syncGame(SteamGame $game): SteamGame
    {
        if ($game->platform_key !== SteamGame::PLATFORM_EPIC) {
            throw new RuntimeException('This is not an Epic game.');
        }

        $game->loadMissing('user');
        $account = $this->account($game->user);
        $namespace = $game->external_id ?: ($game->platform_meta['namespace'] ?? null);

        if (! $namespace) {
            throw new RuntimeException('This Epic game is missing its namespace. Sync Epic again.');
        }

        $definitions = $this->achievementDefinitions($account, $namespace);
        $playerRows = $definitions === [] ? [] : $this->playerAchievements($account, $namespace);

        DB::transaction(function () use ($game, $definitions, $playerRows): void {
            foreach ($definitions as $definition) {
                $name = $definition['name'] ?? null;

                if (! $name) {
                    continue;
                }

                $player = $playerRows[$name] ?? [];
                $achieved = (bool) ($player['unlocked'] ?? false);

                SteamAchievement::query()->updateOrCreate(
                    [
                        'steam_game_id' => $game->id,
                        'apiname' => 'epic:'.$name,
                    ],
                    [
                        'name' => $definition[$achieved ? 'unlockedDisplayName' : 'lockedDisplayName'] ?? $definition['unlockedDisplayName'] ?? $name,
                        'description' => $definition[$achieved ? 'unlockedDescription' : 'lockedDescription'] ?? $definition['unlockedDescription'] ?? null,
                        'icon' => $definition['unlockedIconLink'] ?? $definition['lockedIconLink'] ?? null,
                        'icongray' => $definition['lockedIconLink'] ?? $definition['unlockedIconLink'] ?? null,
                        'hidden' => (bool) ($definition['hidden'] ?? false),
                        'achieved' => $achieved,
                        'unlock_time' => isset($player['unlockDate']) ? Carbon::parse($player['unlockDate'])->timestamp : null,
                        'global_percent' => isset($definition['rarity']['percent']) ? round((float) $definition['rarity']['percent'], 3) : null,
                        'progress_current' => isset($player['progress']) && is_numeric($player['progress']) ? (int) $player['progress'] : null,
                        'progress_target' => null,
                    ],
                );
            }

            $game->update([
                'achievements_total' => count($definitions),
                'achievements_unlocked' => $game->achievements()->where('achieved', true)->count(),
                'achievements_synced_at' => now(),
            ]);
        });

        return $game->refresh();
    }

    private function upsertLibraryItem(User $user, UserPlatformAccount $account, array $item): ?SteamGame
    {
        $namespace = $item['namespace'] ?? null;
        $catalogItemId = $item['catalogItemId'] ?? null;
        $appName = $item['appName'] ?? $catalogItemId ?? $namespace;
        $metadata = $this->catalogMetadata($account, $namespace, $catalogItemId);
        $title = $metadata['title'] ?? $item['title'] ?? $appName ?? 'Unknown Epic game';

        if ($this->shouldSkipCatalogItem($item, $metadata, (string) $appName)) {
            return null;
        }

        return SteamGame::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => SteamGame::PLATFORM_EPIC,
                'appid' => $this->numericId((string) ($appName ?: $catalogItemId ?: $namespace)),
            ],
            [
                'platform' => SteamGame::PLATFORM_EPIC,
                'external_id' => $namespace,
                'name' => $title,
                'img_icon_url' => $this->imageUrl($metadata),
                'playtime_forever' => 0,
                'playtime_2weeks' => 0,
                'platform_meta' => [
                    'namespace' => $namespace,
                    'catalogItemId' => $catalogItemId,
                    'appName' => $appName,
                    'categories' => $metadata['categories'] ?? [],
                ],
                'synced_at' => now(),
            ],
        );
    }

    private function achievementDefinitions(UserPlatformAccount $account, string $namespace): array
    {
        $payload = $this->graphql($account, <<<'GQL'
query Achievement($sandboxId: String!, $locale: String!) {
  Achievement {
    productAchievementsRecordBySandbox(sandboxId: $sandboxId, locale: $locale) {
      achievements {
        achievement {
          name
          hidden
          unlockedDisplayName
          lockedDisplayName
          unlockedDescription
          lockedDescription
          unlockedIconLink
          lockedIconLink
          XP
          rarity { percent }
        }
      }
    }
  }
}
GQL, ['sandboxId' => $namespace, 'locale' => 'en'], true);

        $record = $payload['data']['Achievement']['productAchievementsRecordBySandbox'] ?? null;

        if (! $record) {
            return [];
        }

        return collect($record['achievements'] ?? [])
            ->pluck('achievement')
            ->filter()
            ->values()
            ->all();
    }

    private function playerAchievements(UserPlatformAccount $account, string $namespace): array
    {
        $payload = $this->graphql($account, <<<'GQL'
query PlayerAchievement($epicAccountId: String!, $sandboxId: String!) {
  PlayerAchievement {
    playerAchievementGameRecordsBySandbox(epicAccountId: $epicAccountId, sandboxId: $sandboxId) {
      records {
        playerAchievements {
          playerAchievement {
            unlocked
            progress
            unlockDate
            achievementName
          }
        }
      }
    }
  }
}
GQL, [
            'sandboxId' => $namespace,
            'epicAccountId' => $account->account_id,
        ]);

        $records = $payload['data']['PlayerAchievement']['playerAchievementGameRecordsBySandbox']['records'] ?? [];

        if ($records === []) {
            return [];
        }

        return collect($records[0]['playerAchievements'] ?? [])
            ->pluck('playerAchievement')
            ->filter()
            ->keyBy('achievementName')
            ->all();
    }

    private function catalogMetadata(UserPlatformAccount $account, ?string $namespace, ?string $catalogItemId): array
    {
        if (! $namespace || ! $catalogItemId) {
            return [];
        }

        try {
            return $this->http($account)
                ->get(self::CATALOG_HOST."/catalog/api/shared/namespace/{$namespace}/bulk/items", [
                    'id' => $catalogItemId,
                    'includeDLCDetails' => 'true',
                    'includeMainGameDetails' => 'true',
                    'country' => 'US',
                    'locale' => 'en',
                ])
                ->throw()
                ->json($catalogItemId, []);
        } catch (RequestException) {
            return [];
        }
    }

    private function account(User $user): UserPlatformAccount
    {
        $account = UserPlatformAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', SteamGame::PLATFORM_EPIC)
            ->first();

        if (! $account) {
            throw new RuntimeException('Link your Epic account first.');
        }

        if (! $account->access_token || ! $account->token_expires_at || $account->token_expires_at->isPast()) {
            $tokens = $this->tokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'token_type' => 'eg1',
            ]);

            $account->update([
                'account_id' => $tokens['account_id'] ?? $account->account_id,
                'display_name' => $tokens['displayName'] ?? $account->display_name,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $account->refresh_token,
                'token_expires_at' => now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 7200) - 60)),
            ]);
            $account->refresh();
        }

        return $account;
    }

    private function http(UserPlatformAccount $account): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders([
                'Authorization' => 'bearer '.$account->access_token,
                'User-Agent' => 'UELauncher/11.0.1-14907503+++Portal+Release-Live Windows/10.0.19041.1.256.64bit',
            ])
            ->timeout(25);
    }

    private function graphql(UserPlatformAccount $account, string $query, array $variables, bool $allowUnavailable = false): array
    {
        $response = $this->http($account)
            ->withHeaders(['User-Agent' => 'EpicGamesLauncher/14.0.8-22004686+++Portal+Release-Live'])
            ->post(self::GRAPHQL_URL, [
                'query' => $query,
                'variables' => $variables,
            ]);

        if ($response->failed()) {
            $body = $response->body();
            $message = $this->isHtmlChallenge($body)
                ? 'Epic blocked the server with a browser security challenge. Library sync can work, but achievement sync is not available from this server IP right now.'
                : ($response->json('errorMessage')
                    ?? $response->json('message')
                    ?? str($body)->limit(240)->toString()
                    ?: 'Epic GraphQL request failed.');

            throw new RuntimeException('Epic API: '.$message);
        }

        $body = $response->body();

        if ($this->isHtmlChallenge($body)) {
            throw new RuntimeException('Epic API: Epic blocked the server with a browser security challenge. Library sync can work, but achievement sync is not available from this server IP right now.');
        }

        $payload = $response->json();

        if (! empty($payload['errors'])) {
            if ($allowUnavailable) {
                return [];
            }

            $message = collect($payload['errors'])->pluck('message')->filter()->implode(' ');

            throw new RuntimeException('Epic API: '.($message ?: 'Achievement data is not available for this game.'));
        }

        return $payload;
    }

    private function tokenRequest(array $form): array
    {
        $payload = Http::asForm()
            ->withBasicAuth(self::CLIENT_ID, self::CLIENT_SECRET)
            ->post(self::OAUTH_HOST.'/account/api/oauth/token', $form)
            ->throw()
            ->json();

        if (empty($payload['access_token'])) {
            throw new RuntimeException('Epic did not return a usable access token.');
        }

        return $payload;
    }

    private function shouldSkipLibraryItem(array $item): bool
    {
        return ($item['namespace'] ?? null) === 'ue'
            || ! isset($item['appName'])
            || ($item['sandboxType'] ?? null) === 'PRIVATE'
            || str_ends_with((string) ($item['appName'] ?? ''), 'Content')
            || str_contains((string) ($item['appName'] ?? ''), '_Content');
    }

    private function isHtmlChallenge(string $body): bool
    {
        $sample = strtolower(substr(trim($body), 0, 1200));

        return str_starts_with($sample, '<!doctype html')
            || str_starts_with($sample, '<html')
            || str_contains($sample, 'cf_challenge')
            || str_contains($sample, 'challenge-platform')
            || str_contains($sample, 'please complete a security check');
    }

    private function shouldSkipCatalogItem(array $item, array $metadata, string $appName): bool
    {
        $categories = collect($metadata['categories'] ?? [])->pluck('path')->filter()->all();

        return isset($metadata['mainGameItem'])
            || in_array('mods', $categories, true)
            || in_array('addons', $categories, true)
            || in_array('addons/launchable', $categories, true)
            || str_ends_with($appName, 'Content')
            || str_contains($appName, '_Content');
    }

    private function imageUrl(array $metadata): ?string
    {
        $images = collect($metadata['keyImages'] ?? []);
        $image = $images->firstWhere('type', 'DieselGameBoxTall')
            ?? $images->firstWhere('type', 'DieselGameBox')
            ?? $images->firstWhere('type', 'OfferImageTall')
            ?? $images->first();

        return is_array($image) ? ($image['url'] ?? null) : null;
    }

    private function numericId(string $externalId): int
    {
        return (int) sprintf('%u', crc32($externalId));
    }
}
