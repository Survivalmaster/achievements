<?php

namespace App\Http\Controllers;

use App\Models\AchievementHuntSetting;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\TrackerSetting;
use App\Models\User;
use App\Models\UserPlatformAccount;
use App\Services\OpenXblClient;
use App\Services\PsnTrophyClient;
use App\Services\SteamAchievementClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $payload = $this->basePayload($request);

        return view('dashboard', [
            ...$payload,
            'mode' => 'overview',
            'currentGame' => null,
            'achievements' => collect(),
            'filter' => $this->achievementFilter($request->query('filter', 'all')),
            'history' => collect(),
            'overview' => $this->overviewStats(),
        ]);
    }

    public function showGame(Request $request, SteamGame $game, SteamAchievementClient $steam, OpenXblClient $xbox): View
    {
        $this->authorizeGame($game);

        if (
            $game->platform_key === SteamGame::PLATFORM_XBOX
            && (! $game->achievements_synced_at || ($game->achievements_total > 0 && ! $game->achievements()->exists()))
        ) {
            try {
                $xbox->syncGame($game);
                $game->refresh();
            } catch (Throwable) {
                // The game page still renders so the manual Refresh button can surface the platform error.
            }
        }

        $payload = $this->basePayload($request);
        $game->load('huntSetting');

        $achievementQuery = $game->achievements()
            ->with('huntSetting')
            ->orderBy('achieved')
            ->orderByRaw('global_percent is null, global_percent asc')
            ->orderBy('name');

        $filter = $this->achievementFilter($request->query('filter', 'all'));

        if ($filter === 'locked') {
            $achievementQuery->where('achieved', false);
        } elseif ($filter === 'unlocked') {
            $achievementQuery->where('achieved', true);
        } elseif ($filter === 'secret') {
            $achievementQuery->where('hidden', true);
        } elseif ($filter === 'rare') {
            $achievementQuery->where('global_percent', '>', 0)->where('global_percent', '<=', 10);
        }

        $friends = $this->friends($steam);
        $compareSteamId = $request->query('friend_steam_id');
        $compareUser = null;
        $comparison = collect();
        $compareProfile = null;
        $compareStats = null;

        if (is_string($compareSteamId) && preg_match('/^\d{17}$/', $compareSteamId)) {
            $compareUser = $friends->firstWhere('steam_id', $compareSteamId);

            if ($compareUser) {
                $compareProfile = $compareUser;
                $friendGame = SteamGame::query()
                    ->with('achievements')
                    ->where('user_id', $compareUser->id)
                    ->where('appid', $game->appid)
                    ->first();
                $friendAchievements = $friendGame?->achievements->keyBy('apiname') ?? collect();

                $comparison = $game->achievements()
                    ->orderBy('achieved')
                    ->orderBy('name')
                    ->get()
                    ->map(function (SteamAchievement $achievement) use ($friendAchievements): array {
                        $friend = $friendAchievements->get($achievement->apiname);

                        return [
                            'name' => $achievement->name,
                            'you' => $achievement->achieved,
                            'friend' => (bool) ($friend?->achieved ?? false),
                        ];
                    });
                $compareStats = [
                    'you' => "{$game->achievements_unlocked}/{$game->achievements_total}",
                    'friend' => $friendGame ? "{$friendGame->achievements_unlocked}/{$friendGame->achievements_total}" : 'No data',
                    'both_missing' => $comparison->filter(fn ($row) => ! $row['you'] && ! $row['friend'])->count(),
                    'only_you' => $comparison->filter(fn ($row) => $row['you'] && ! $row['friend'])->count(),
                    'only_friend' => $comparison->filter(fn ($row) => ! $row['you'] && $row['friend'])->count(),
                ];
            }
        }

        return view('dashboard', [
            ...$payload,
            'mode' => 'game',
            'currentGame' => $game,
            'achievements' => $achievementQuery->get(),
            'filter' => $filter,
            'history' => $game->progressSnapshots()->latest('taken_at')->limit(8)->get(),
            'overview' => $this->overviewStats(),
            'friends' => $friends,
            'compareSteamId' => $compareSteamId,
            'compareProfile' => $compareProfile,
            'compareUser' => $compareUser,
            'compareStats' => $compareStats,
            'comparison' => $comparison,
            'relatedGames' => $this->relatedPlatformGames($game),
            'trackerCompareUsers' => $this->trackerCompareUsers($game),
            'trackerCompareId' => (int) $request->query('compare_user_id', 0),
            'trackerComparison' => $this->trackerComparison($game, (int) $request->query('compare_user_id', 0)),
        ]);
    }

    private function basePayload(Request $request): array
    {
        $gameFilter = $request->query('game_filter', 'all');
        $platformFilter = $this->platformFilter($request->query('platform_filter', 'all'));
        $supportsPlatform = Schema::hasColumn('steam_games', 'platform');
        $baseGamesQuery = SteamGame::query()
            ->where('user_id', Auth::id())
            ->with('huntSetting')
            ->withCount([
                'achievements as secret_count' => fn ($query) => $query->where('hidden', true),
                'achievements as rare_count' => fn ($query) => $query->where('global_percent', '>', 0)->where('global_percent', '<=', 10),
            ])
            ->where(function ($query): void {
                $query->whereNull('achievements_synced_at')
                    ->orWhere('achievements_total', '>', 0);
            })
            ->where(function ($query): void {
                $query->whereDoesntHave('huntSetting')
                    ->orWhereHas('huntSetting', fn ($setting) => $setting->where('archived', false));
            });

        if ($supportsPlatform) {
            $baseGamesQuery->whereIn('platform', array_keys(SteamGame::PLATFORMS));
        }

        if ($supportsPlatform && $platformFilter !== 'all') {
            $baseGamesQuery->where('platform', $platformFilter);
        } elseif (! $supportsPlatform && $platformFilter !== SteamGame::PLATFORM_STEAM && $platformFilter !== 'all') {
            $baseGamesQuery->whereRaw('1 = 0');
        }

        $gameCounts = [
            'all' => (clone $baseGamesQuery)->count(),
            'in_progress' => (clone $baseGamesQuery)
                ->where('achievements_total', '>', 0)
                ->whereColumn('achievements_unlocked', '<', 'achievements_total')
                ->count(),
            'completed' => (clone $baseGamesQuery)
                ->where('achievements_total', '>', 0)
                ->whereColumn('achievements_unlocked', '>=', 'achievements_total')
                ->count(),
            'unchecked' => (clone $baseGamesQuery)
                ->whereNull('achievements_synced_at')
                ->count(),
            'archived' => $this->supportedGamesQuery()
                ->whereHas('huntSetting', fn ($setting) => $setting->where('archived', true))
                ->count(),
        ];

        $platformCountsQuery = SteamGame::query()
            ->where('user_id', Auth::id())
            ->where(function ($query): void {
                $query->whereNull('achievements_synced_at')
                    ->orWhere('achievements_total', '>', 0);
            })
            ->where(function ($query): void {
                $query->whereDoesntHave('huntSetting')
                    ->orWhereHas('huntSetting', fn ($setting) => $setting->where('archived', false));
            });

        if ($supportsPlatform) {
            $platformCountsQuery->whereIn('platform', array_keys(SteamGame::PLATFORMS));
        }

        $platformCounts = ['all' => (clone $platformCountsQuery)->count()];

        foreach (SteamGame::PLATFORMS as $key => $label) {
            $platformCounts[$key] = match (true) {
                $supportsPlatform => (clone $platformCountsQuery)->where('platform', $key)->count(),
                $key === SteamGame::PLATFORM_STEAM => (clone $platformCountsQuery)->count(),
                default => 0,
            };
        }

        $gamesQuery = $gameFilter === 'archived'
            ? $this->supportedGamesQuery()->with('huntSetting')->whereHas('huntSetting', fn ($setting) => $setting->where('archived', true))
            : clone $baseGamesQuery;

        $hideFinished = TrackerSetting::value('hide_finished:'.Auth::id(), '0') === '1';

        if ($gameFilter === 'archived' && $supportsPlatform) {
            $gamesQuery->whereIn('platform', array_keys(SteamGame::PLATFORMS));
        }

        if ($gameFilter === 'archived' && $supportsPlatform && $platformFilter !== 'all') {
            $gamesQuery->where('platform', $platformFilter);
        }

        if ($gameFilter === 'in_progress') {
            $gamesQuery->where('achievements_total', '>', 0)
                ->whereColumn('achievements_unlocked', '<', 'achievements_total');
        } elseif ($gameFilter === 'completed') {
            $gamesQuery->where('achievements_total', '>', 0)
                ->whereColumn('achievements_unlocked', '>=', 'achievements_total');
        } elseif ($gameFilter === 'unchecked') {
            $gamesQuery->whereNull('achievements_synced_at');
        } elseif ($gameFilter !== 'archived') {
            $gameFilter = 'all';
        }

        if ($hideFinished && ! in_array($gameFilter, ['completed', 'archived'], true)) {
            $gamesQuery->where(function ($query): void {
                $query->whereNull('achievements_synced_at')
                    ->orWhere('achievements_total', 0)
                    ->orWhereColumn('achievements_unlocked', '<', 'achievements_total');
            });
        }

        $games = $gamesQuery
            ->orderByDesc('is_current')
            ->orderByRaw('case when achievements_total > 0 and achievements_unlocked >= achievements_total then 1 else 0 end asc')
            ->orderByRaw('case when achievements_total = 0 then 1 else 0 end asc')
            ->orderByRaw('case when achievements_total > 0 then ((achievements_unlocked * 100.0) / achievements_total) else 0 end desc')
            ->orderByRaw('case when achievements_total > 0 then (achievements_total - achievements_unlocked) else 999999 end asc')
            ->orderByDesc('last_played_at')
            ->orderByDesc('playtime_2weeks')
            ->orderByDesc('playtime_forever')
            ->orderBy('name')
            ->get();

        $spoilerSafe = TrackerSetting::value('spoiler_safe:'.Auth::id(), '0') === '1';

        return [
            'games' => $games,
            'gameFilter' => $gameFilter,
            'platformFilter' => $platformFilter,
            'platforms' => SteamGame::PLATFORMS,
            'platformCounts' => $platformCounts,
            'gameCounts' => $gameCounts,
            'configured' => (bool) config('services.steam.api_key'),
            'unsyncedGames' => $this->steamGamesQuery()->whereNull('achievements_synced_at')->count(),
            'refreshableGames' => $this->steamGamesQuery()
                ->where(function ($query): void {
                    $query->where('achievements_total', '>', 0)
                        ->orWhereNull('achievements_synced_at');
                })
                ->count(),
            'spoilerSafe' => $spoilerSafe,
            'hideFinished' => $hideFinished,
            'recentAchievements' => $this->recentAchievements(),
            'roadmapGames' => $this->roadmapGames(),
            'rarestUnlocked' => $this->rareAchievements(true),
            'rarestMissing' => $this->rareAchievements(false),
            'plannedAchievements' => $this->plannedAchievements(),
            'tonightAchievements' => $this->tonightAchievements(),
            'smartRecommendations' => $this->smartRecommendations(),
            'huntBoard' => $this->huntBoard(),
            'platformHealth' => $this->platformHealth(),
            'needsRefreshGames' => $this->needsRefreshGames(),
            'crossPlatformGroups' => $this->crossPlatformGroups(),
            'syncIssues' => $this->syncIssues(),
            'refreshStatus' => $this->refreshStatus(),
            'friendActivity' => $this->friendActivity(),
            'staleGames' => $this->staleGames(),
            'psnAccount' => $this->psnAccount(),
            'xboxAccount' => $this->xboxAccount(),
            'openXblConfigured' => (bool) config('services.openxbl.api_key'),
        ];
    }

    public function linkPsn(Request $request, PsnTrophyClient $psn): RedirectResponse
    {
        $data = $request->validate([
            'npsso' => ['required', 'string', 'min:20', 'max:255'],
        ]);

        try {
            $psn->link($request->user(), $data['npsso']);
        } catch (Throwable $exception) {
            $message = $this->message($exception);
            $this->recordSyncIssue('PSN', 'Link PlayStation', $message);
            return back()->with('error', $message);
        }

        return back()->with('status', 'PSN linked. Sync PlayStation to pull your trophy titles.');
    }

    public function syncPsnLibrary(Request $request, PsnTrophyClient $psn): RedirectResponse
    {
        try {
            $count = $psn->syncLibrary($request->user());
        } catch (Throwable $exception) {
            $message = $this->message($exception);
            $this->recordSyncIssue('PSN', 'Sync PlayStation', $message);
            return back()->with('error', $message);
        }

        return back()->with('status', "Synced {$count} PlayStation trophy titles.");
    }

    public function linkXbox(Request $request, OpenXblClient $xbox): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'min:20', 'max:255'],
        ]);

        try {
            $account = $xbox->link($request->user(), $data['api_key'] ?? null);
        } catch (Throwable $exception) {
            $message = $this->message($exception);
            $this->recordSyncIssue('Xbox', 'Link Xbox', $message);
            return back()->with('error', $message);
        }

        return back()->with('status', "Xbox linked as {$account->display_name}. Sync Xbox to pull your titles.");
    }

    public function syncXboxLibrary(Request $request, OpenXblClient $xbox): RedirectResponse
    {
        try {
            $count = $xbox->syncLibrary($request->user());
            $refresh = $xbox->refreshActiveAchievementBatch($request->user(), 60);
        } catch (Throwable $exception) {
            $message = $this->message($exception);
            $this->recordSyncIssue('Xbox', 'Sync Xbox', $message);
            return back()->with('error', $message);
        }

        return back()->with('status', "Synced {$count} Xbox titles and refreshed {$refresh['synced']} achievement lists.");
    }

    public function syncLibrary(SteamAchievementClient $steam): RedirectResponse
    {
        try {
            $count = $steam->syncLibrary();
        } catch (Throwable $exception) {
            $message = $this->message($exception);
            $this->recordSyncIssue('Steam', 'Sync Library', $message);
            return back()->with('error', $message);
        }

        return back()->with('status', "Synced {$count} Steam games.");
    }

    public function syncAchievements(Request $request, SteamAchievementClient $steam): RedirectResponse|JsonResponse
    {
        try {
            $result = $steam->syncAchievementBatch(15);

            if ($result['attempted'] === 0) {
                $refresh = $steam->refreshActiveAchievementBatch(15);
                $result['attempted'] = $refresh['attempted'];
                $result['synced'] = $refresh['synced'];
                $result['failed'] = $refresh['failed'];
                $result['remaining'] = 0;
                $result['refreshed'] = true;
            }
        } catch (Throwable $exception) {
            $message = $this->message($exception);
            $this->recordSyncIssue('Steam', 'Sync Achievements', $message);
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 500);
            }

            return back()->with('error', $message);
        }

        if ($result['attempted'] === 0) {
            if ($request->expectsJson()) {
                return response()->json($result);
            }

            return back()->with('status', 'All games have achievement data checked.');
        }

        $message = ($result['refreshed'] ?? false) ? "Refreshed {$result['synced']} Steam games" : "Checked {$result['synced']} Steam games";
        $this->recordRefreshStatus('Sync Achievements', $result['attempted'], $result['synced'], $result['failed']);

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        if ($result['failed'] > 0) {
            $message .= " ({$result['failed']} failed)";
        }

        return back()->with('status', "{$message}. {$result['remaining']} games left to check.");
    }

    public function refreshAllAchievements(Request $request, SteamAchievementClient $steam): RedirectResponse|JsonResponse
    {
        $afterId = max(0, (int) $request->input('after_id', 0));

        try {
            if ($afterId === 0) {
                $steam->syncLibrary();
            }

            $result = $steam->refreshAllAchievementBatch($afterId, 15);
        } catch (Throwable $exception) {
            $message = $this->message($exception);
            $this->recordSyncIssue('Steam', 'Refresh All Games', $message);
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 500);
            }

            return back()->with('error', $message);
        }

        if ($request->expectsJson()) {
            $this->recordRefreshStatus('Refresh All Games', $result['attempted'], $result['synced'], $result['failed']);
            return response()->json($result);
        }

        $message = "Refreshed {$result['synced']} games";
        $this->recordRefreshStatus('Refresh All Games', $result['attempted'], $result['synced'], $result['failed']);

        if ($result['failed'] > 0) {
            $message .= " ({$result['failed']} failed)";
        }

        return back()->with('status', "{$message}. {$result['remaining']} games left to refresh.");
    }

    public function quickRefreshAchievements(Request $request, SteamAchievementClient $steam): JsonResponse
    {
        $key = 'quick_refresh:'.Auth::id();
        $lastRefresh = TrackerSetting::value($key);

        if ($lastRefresh && Carbon::parse($lastRefresh)->gt(now()->subMinutes(5))) {
            return response()->json([
                'skipped' => true,
                'message' => 'Recent refresh already ran.',
            ]);
        }

        TrackerSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => now()->toIso8601String()],
        );

        try {
            $steam->syncLibrary();
            $focusedGame = SteamGame::query()
                ->where('user_id', Auth::id())
                ->whereKey((int) $request->input('game_id'))
                ->first();
            $focusedSynced = 0;
            $focusedFailed = 0;

            if ($focusedGame && $focusedGame->achievements_total > 0) {
                try {
                    if ($focusedGame->platform_key === SteamGame::PLATFORM_STEAM) {
                        $steam->syncAchievements($focusedGame);
                        $focusedSynced = 1;
                    }
                } catch (Throwable) {
                    $focusedFailed = 1;
                }
            }

            $result = $steam->refreshActiveAchievementBatch(10);
        } catch (Throwable $exception) {
            $message = $this->message($exception);
            $this->recordSyncIssue('Steam', 'Quick Refresh', $message);
            return response()->json(['message' => $message], 500);
        }

        $attempted = $result['attempted'] + $focusedSynced + $focusedFailed;
        $synced = $result['synced'] + $focusedSynced;
        $failed = $result['failed'] + $focusedFailed;
        $this->recordRefreshStatus('Quick Refresh', $attempted, $synced, $failed);

        return response()->json([
            ...$result,
            'attempted' => $attempted,
            'synced' => $synced,
            'failed' => $failed,
            'skipped' => false,
        ]);
    }

    public function refreshGame(SteamGame $game, SteamAchievementClient $steam, PsnTrophyClient $psn, OpenXblClient $xbox): RedirectResponse
    {
        $this->authorizeGame($game);
        $beforeUnlocked = $game->achievements_unlocked;
        $beforeTotal = $game->achievements_total;
        $beforeAchievements = $game->achievements()->where('achieved', true)->pluck('name')->all();

        try {
            if ($game->platform_key === SteamGame::PLATFORM_PSN) {
                $psn->syncGame($game);
            } elseif ($game->platform_key === SteamGame::PLATFORM_STEAM) {
                $steam->syncLibrary();
                $steam->syncAchievements($game);
            } elseif ($game->platform_key === SteamGame::PLATFORM_XBOX) {
                $xbox->syncGame($game);
            } else {
                throw new RuntimeException("{$game->platform_label} sync is not wired up yet.");
            }
        } catch (Throwable $exception) {
            $message = $this->message($exception);
            $this->recordSyncIssue($game->platform_label, "Refresh {$game->name}", $message);
            return back()->with('error', $message);
        }

        $game->refresh();
        $afterAchievements = $game->achievements()->where('achieved', true)->pluck('name')->all();
        $newUnlocks = collect($afterAchievements)->diff($beforeAchievements)->values();
        $this->recordRefreshStatus("Refresh {$game->name}", 1, 1, 0);

        if ($game->achievements_unlocked > $beforeUnlocked || $game->achievements_total !== $beforeTotal) {
            $detail = $newUnlocks->isNotEmpty() ? ' New: '.$newUnlocks->take(3)->implode(', ').($newUnlocks->count() > 3 ? '...' : '').'.' : '';

            return back()->with('status', "Updated {$game->name}: {$game->achievements_unlocked}/{$game->achievements_total} achievements unlocked.{$detail}");
        }

        return back()->with('status', "{$game->platform_label} returned no new unlocks for {$game->name}. Still {$game->achievements_unlocked}/{$game->achievements_total} unlocked.");
    }

    public function startHuntSession(): RedirectResponse
    {
        $this->tonightAchievements()->each(function (SteamAchievement $achievement): void {
            $achievement->huntSetting()->updateOrCreate(
                ['steam_achievement_id' => $achievement->id],
                [
                    'status' => 'target',
                    'note' => $achievement->huntSetting?->note,
                    'tags' => $achievement->huntSetting?->tags,
                ],
            );
        });

        return back()->with('status', "Tonight's Hunt targets marked.");
    }

    public function setCurrent(Request $request, SteamGame $game, SteamAchievementClient $steam, PsnTrophyClient $psn, OpenXblClient $xbox): RedirectResponse
    {
        $this->authorizeGame($game);

        SteamGame::where('user_id', Auth::id())->update(['is_current' => false]);
        $game->update(['is_current' => true]);

        if (! $game->achievements_synced_at) {
            try {
                if ($game->platform_key === SteamGame::PLATFORM_PSN) {
                    $psn->syncGame($game);
                } elseif ($game->platform_key === SteamGame::PLATFORM_STEAM) {
                    $steam->syncAchievements($game);
                } elseif ($game->platform_key === SteamGame::PLATFORM_XBOX) {
                    $xbox->syncGame($game);
                }
            } catch (Throwable $exception) {
                $message = $this->message($exception);
                $this->recordSyncIssue($game->platform_label, "Set Current {$game->name}", $message);
                return back()->with('error', $message);
            }
        }

        return redirect()
            ->route('games.show', [
                'game' => $game,
                'game_filter' => $this->gameFilter($request->input('game_filter', 'all')),
                'platform_filter' => $this->platformFilter($request->input('platform_filter', 'all')),
                'filter' => $this->achievementFilter($request->input('filter', 'all')),
            ])
            ->with('status', "{$game->name} is now your current game.");
    }

    public function updateGame(Request $request, SteamGame $game): RedirectResponse
    {
        $this->authorizeGame($game);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'string', 'max:255'],
            'tag_presets' => ['nullable', 'array'],
            'tag_presets.*' => ['string', 'in:DLC,Co-op,Online,Missable,Grind,Buggy,Collectibles'],
            'difficulty' => ['nullable', 'in:easy,normal,hard,grind,buggy,multiplayer,missable'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $tags = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn (string $tag): string => trim($tag))
            ->filter()
            ->merge($data['tag_presets'] ?? [])
            ->unique(fn (string $tag): string => strtolower($tag))
            ->take(10)
            ->implode(', ');

        $game->huntSetting()->updateOrCreate(
            ['steam_game_id' => $game->id],
            [
                'note' => $data['note'] ?? null,
                'tags' => $tags ?: null,
                'difficulty' => $data['difficulty'] ?? null,
                'archived' => (bool) ($data['archived'] ?? false),
            ],
        );

        return back()->with('status', 'Game hunt settings saved.');
    }

    public function updateAchievement(Request $request, SteamAchievement $achievement): RedirectResponse
    {
        $this->authorizeAchievement($achievement);
        $supportsManualProgress = Schema::hasColumn('achievement_hunt_settings', 'manual_progress_current')
            && Schema::hasColumn('achievement_hunt_settings', 'manual_progress_target');
        $supportsAchievementDifficulty = Schema::hasColumn('achievement_hunt_settings', 'difficulty');

        $rules = [
            'status' => ['required', 'in:'.implode(',', AchievementHuntSetting::STATUSES)],
            'note' => ['nullable', 'string', 'max:1200'],
            'tags' => ['nullable', 'string', 'max:255'],
        ];

        if ($supportsAchievementDifficulty) {
            $rules['difficulty'] = ['nullable', 'in:'.implode(',', AchievementHuntSetting::DIFFICULTIES)];
        }

        if ($supportsManualProgress) {
            $rules['manual_progress_current'] = ['nullable', 'integer', 'min:0'];
            $rules['manual_progress_target'] = ['nullable', 'integer', 'min:1'];
        }

        $data = $request->validate($rules);

        if (
            $supportsManualProgress
            &&
            ($data['manual_progress_current'] ?? null) !== null
            && ($data['manual_progress_target'] ?? null) !== null
            && (int) $data['manual_progress_current'] > (int) $data['manual_progress_target']
        ) {
            $data['manual_progress_current'] = $data['manual_progress_target'];
        }

        $values = [
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
            'tags' => $data['tags'] ?? null,
        ];

        if ($supportsAchievementDifficulty) {
            $values['difficulty'] = $data['difficulty'] ?? null;
        }

        if ($supportsManualProgress) {
            $values['manual_progress_current'] = $data['manual_progress_current'] ?? null;
            $values['manual_progress_target'] = $data['manual_progress_target'] ?? null;
        }

        $achievement->huntSetting()->updateOrCreate(
            ['steam_achievement_id' => $achievement->id],
            $values,
        );

        return back()->with('status', $supportsManualProgress ? 'Achievement plan saved.' : 'Achievement plan saved. Run migrations to enable manual progress saving.');
    }

    public function updateSpoilers(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'spoiler_safe' => ['nullable', 'boolean'],
        ]);

        TrackerSetting::query()->updateOrCreate(
            ['key' => 'spoiler_safe:'.Auth::id()],
            ['value' => (bool) ($data['spoiler_safe'] ?? false) ? '1' : '0'],
        );

        return back()->with('status', 'Secret achievement display updated.');
    }

    public function updateFilters(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'hide_finished' => ['nullable', 'boolean'],
        ]);

        TrackerSetting::query()->updateOrCreate(
            ['key' => 'hide_finished:'.Auth::id()],
            ['value' => (bool) ($data['hide_finished'] ?? false) ? '1' : '0'],
        );

        return back()->with('status', 'Game list preference updated.');
    }

    private function gameFilter(mixed $filter): string
    {
        return in_array($filter, ['all', 'in_progress', 'completed', 'unchecked', 'archived'], true) ? $filter : 'all';
    }

    private function platformFilter(mixed $filter): string
    {
        if (! is_string($filter)) {
            return 'all';
        }

        return $filter === 'all' || array_key_exists($filter, SteamGame::PLATFORMS) ? $filter : 'all';
    }

    private function achievementFilter(mixed $filter): string
    {
        return in_array($filter, ['all', 'locked', 'unlocked', 'secret', 'rare'], true) ? $filter : 'all';
    }

    private function message(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException) {
            return $exception->getMessage();
        }

        return 'The platform API did not answer cleanly. Check the linked account, token, API key, and profile privacy.';
    }

    private function psnAccount(): ?UserPlatformAccount
    {
        return UserPlatformAccount::query()
            ->where('user_id', Auth::id())
            ->where('platform', SteamGame::PLATFORM_PSN)
            ->first();
    }

    private function xboxAccount(): ?UserPlatformAccount
    {
        return UserPlatformAccount::query()
            ->where('user_id', Auth::id())
            ->where('platform', SteamGame::PLATFORM_XBOX)
            ->first();
    }

    private function steamGamesQuery()
    {
        $query = SteamGame::query()->where('user_id', Auth::id());

        if (Schema::hasColumn('steam_games', 'platform')) {
            $query->where('platform', SteamGame::PLATFORM_STEAM);
        }

        return $query;
    }

    private function supportedGamesQuery()
    {
        $query = SteamGame::query()->where('user_id', Auth::id());

        if (Schema::hasColumn('steam_games', 'platform')) {
            $query->whereIn('platform', array_keys(SteamGame::PLATFORMS));
        }

        return $query;
    }

    private function supportedGameRelation($query)
    {
        $query->where('user_id', Auth::id());

        if (Schema::hasColumn('steam_games', 'platform')) {
            $query->whereIn('platform', array_keys(SteamGame::PLATFORMS));
        }

        return $query;
    }

    private function platformHealth(): array
    {
        return collect(SteamGame::PLATFORMS)->mapWithKeys(function (string $label, string $platform): array {
            $query = $this->supportedGamesQuery()->where('platform', $platform);
            $account = $platform === SteamGame::PLATFORM_STEAM ? null : UserPlatformAccount::query()
                ->where('user_id', Auth::id())
                ->where('platform', $platform)
                ->first();
            $lastGameSync = (clone $query)->whereNotNull('synced_at')->max('synced_at');
            $lastAchievementSync = (clone $query)->whereNotNull('achievements_synced_at')->max('achievements_synced_at');

            return [$platform => [
                'label' => $label,
                'linked' => $platform === SteamGame::PLATFORM_STEAM ? (bool) config('services.steam.api_key') : (bool) $account,
                'games' => (clone $query)->count(),
                'huntable' => (clone $query)->where('achievements_total', '>', 0)->count(),
                'unchecked' => (clone $query)->whereNull('achievements_synced_at')->count(),
                'stale' => (clone $query)->where('achievements_total', '>', 0)->where('achievements_synced_at', '<', now()->subDay())->count(),
                'synced_at' => $account?->synced_at ?? ($lastAchievementSync ? Carbon::parse($lastAchievementSync) : ($lastGameSync ? Carbon::parse($lastGameSync) : null)),
            ]];
        })->all();
    }

    private function needsRefreshGames()
    {
        return $this->supportedGamesQuery()
            ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true))
            ->where(function ($query): void {
                $query->whereNull('achievements_synced_at')
                    ->orWhere('achievements_synced_at', '<', now()->subDay())
                    ->orWhere(function ($query): void {
                        $query->where('achievements_total', '>', 0)
                            ->whereDoesntHave('achievements');
                    });
            })
            ->orderByRaw('achievements_synced_at is not null')
            ->orderBy('achievements_synced_at')
            ->limit(8)
            ->get();
    }

    private function crossPlatformGroups()
    {
        return $this->supportedGamesQuery()
            ->with('huntSetting')
            ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true))
            ->where('achievements_total', '>', 0)
            ->get()
            ->groupBy(fn (SteamGame $game): string => $this->gameMatchKey($game->name))
            ->filter(fn ($games): bool => $games->pluck('platform_key')->unique()->count() > 1)
            ->sortByDesc(fn ($games): int => $games->count())
            ->take(6)
            ->values();
    }

    private function relatedPlatformGames(SteamGame $game)
    {
        return $this->supportedGamesQuery()
            ->whereKeyNot($game->id)
            ->where('achievements_total', '>', 0)
            ->get()
            ->filter(fn (SteamGame $candidate): bool => $this->gameMatchKey($candidate->name) === $this->gameMatchKey($game->name))
            ->values();
    }

    private function gameMatchKey(string $name): string
    {
        $key = strtolower($name);
        $key = preg_replace('/\b(remaster(ed)?|definitive|deluxe|standard|complete|ultimate|goty|game of the year|edition|legacy|ps4|ps5|xbox|series x|windows)\b/', '', $key);

        return preg_replace('/[^a-z0-9]+/', '', $key) ?: strtolower($name);
    }

    private function trackerCompareUsers(SteamGame $game)
    {
        $identity = $this->gameIdentityQuery($game);

        return User::query()
            ->whereKeyNot(Auth::id())
            ->whereHas('steamGames', $identity)
            ->orderBy('name')
            ->limit(25)
            ->get();
    }

    private function trackerComparison(SteamGame $game, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $friend = User::query()->whereKeyNot(Auth::id())->whereKey($userId)->first();

        if (! $friend) {
            return [];
        }

        $friendGame = SteamGame::query()
            ->where('user_id', $friend->id)
            ->where($this->gameIdentityQuery($game))
            ->with('achievements')
            ->first();

        if (! $friendGame) {
            return [];
        }

        $friendAchievements = $friendGame->achievements->keyBy('apiname');
        $rows = $game->achievements()
            ->orderBy('achieved')
            ->orderBy('name')
            ->get()
            ->map(function (SteamAchievement $achievement) use ($friendAchievements): array {
                $friendAchievement = $friendAchievements->get($achievement->apiname);

                return [
                    'name' => $achievement->name,
                    'you' => $achievement->achieved,
                    'friend' => (bool) ($friendAchievement?->achieved ?? false),
                ];
            });

        return [
            'user' => $friend,
            'stats' => [
                'you' => "{$game->achievements_unlocked}/{$game->achievements_total}",
                'friend' => "{$friendGame->achievements_unlocked}/{$friendGame->achievements_total}",
                'both_missing' => $rows->filter(fn ($row) => ! $row['you'] && ! $row['friend'])->count(),
                'only_you' => $rows->filter(fn ($row) => $row['you'] && ! $row['friend'])->count(),
                'only_friend' => $rows->filter(fn ($row) => ! $row['you'] && $row['friend'])->count(),
            ],
            'rows' => $rows->take(12),
        ];
    }

    private function gameIdentityQuery(SteamGame $game): callable
    {
        return function ($query) use ($game): void {
            $query->where('platform', $game->platform_key);

            if ($game->external_id) {
                $query->where('external_id', $game->external_id);
            } else {
                $query->where('appid', $game->appid);
            }
        };
    }

    private function roadmapGames()
    {
        return $this->supportedGamesQuery()
            ->with('huntSetting')
            ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true))
            ->where('achievements_total', '>', 0)
            ->whereColumn('achievements_unlocked', '<', 'achievements_total')
            ->orderByRaw('(achievements_total - achievements_unlocked) asc')
            ->orderByDesc('achievements_unlocked')
            ->limit(6)
            ->get();
    }

    private function rareAchievements(bool $achieved)
    {
        return SteamAchievement::query()
            ->with(['game', 'huntSetting'])
            ->where('achieved', $achieved)
            ->where('global_percent', '>', 0)
            ->whereHas('game', function ($query): void {
                $query
                    ->where('user_id', Auth::id())
                    ->when(Schema::hasColumn('steam_games', 'platform'), fn ($query) => $query->whereIn('platform', array_keys(SteamGame::PLATFORMS)))
                    ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true));
            })
            ->orderBy('global_percent')
            ->limit(6)
            ->get();
    }

    private function plannedAchievements()
    {
        return SteamAchievement::query()
            ->with(['game', 'huntSetting'])
            ->whereHas('huntSetting', fn ($setting) => $setting->whereIn('status', ['target', 'later']))
            ->whereHas('game', function ($query): void {
                $query
                    ->where('user_id', Auth::id())
                    ->when(Schema::hasColumn('steam_games', 'platform'), fn ($query) => $query->whereIn('platform', array_keys(SteamGame::PLATFORMS)))
                    ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true));
            })
            ->orderBy('achieved')
            ->orderByRaw('global_percent is null, global_percent asc')
            ->limit(8)
            ->get();
    }

    private function tonightAchievements()
    {
        return SteamAchievement::query()
            ->with(['game', 'huntSetting'])
            ->where('achieved', false)
            ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('status', 'ignore'))
            ->whereHas('game', function ($query): void {
                $query
                    ->where('user_id', Auth::id())
                    ->when(Schema::hasColumn('steam_games', 'platform'), fn ($query) => $query->whereIn('platform', array_keys(SteamGame::PLATFORMS)))
                    ->where('achievements_total', '>', 0)
                    ->whereColumn('achievements_unlocked', '<', 'achievements_total')
                    ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true));
            })
            ->limit(250)
            ->get()
            ->sortByDesc(fn (SteamAchievement $achievement): float => $this->huntScore($achievement))
            ->take(4)
            ->values();
    }

    private function huntScore(SteamAchievement $achievement): float
    {
        $game = $achievement->game;
        $score = 0;

        if (($achievement->huntSetting?->status ?? 'none') === 'target') {
            $score += 1000;
        }

        if ($game?->last_played_at) {
            $daysSincePlayed = max(0, $game->last_played_at->diffInDays(now()));
            $score += max(0, 220 - ($daysSincePlayed * 12));
        }

        if ($game && $game->achievements_total > 0) {
            $remaining = max(1, $game->achievements_total - $game->achievements_unlocked);
            $score += max(0, 180 - ($remaining * 12));
            $score += $game->completion_percent * 1.2;
        }

        if ($achievement->global_percent !== null && (float) $achievement->global_percent > 0) {
            $score += max(0, 120 - ((float) $achievement->global_percent * 4));
        }

        if (($achievement->huntSetting?->status ?? 'none') === 'later') {
            $score += 35;
        }

        return $score;
    }

    private function smartRecommendations()
    {
        return SteamAchievement::query()
            ->with(['game', 'huntSetting'])
            ->where('achieved', false)
            ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('status', 'ignore'))
            ->whereHas('game', function ($query): void {
                $query
                    ->where('user_id', Auth::id())
                    ->when(Schema::hasColumn('steam_games', 'platform'), fn ($query) => $query->whereIn('platform', array_keys(SteamGame::PLATFORMS)))
                    ->where('achievements_total', '>', 0)
                    ->whereColumn('achievements_unlocked', '<', 'achievements_total')
                    ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true));
            })
            ->limit(300)
            ->get()
            ->map(function (SteamAchievement $achievement): array {
                $game = $achievement->game;
                $remaining = $game ? $game->achievements_total - $game->achievements_unlocked : null;
                $reason = match (true) {
                    ($achievement->huntSetting?->status ?? 'none') === 'target' => 'Already targeted',
                    $game?->last_played_at && $game->last_played_at->gt(now()->subDays(14)) => 'Recent momentum',
                    $remaining !== null && $remaining <= 5 => 'Close completion',
                    $achievement->global_percent !== null && (float) $achievement->global_percent <= 5 => 'Rare chase',
                    ($achievement->huntSetting?->status ?? 'none') === 'later' => 'Saved for later',
                    default => 'Balanced pick',
                };

                return [
                    'achievement' => $achievement,
                    'score' => $this->huntScore($achievement),
                    'reason' => $reason,
                ];
            })
            ->sortByDesc('score')
            ->take(6)
            ->values();
    }

    private function huntBoard(): array
    {
        $base = AchievementHuntSetting::query()
            ->with('achievement.game')
            ->whereHas('achievement.game', fn ($query) => $this->supportedGameRelation($query))
            ->whereHas('achievement', fn ($query) => $query->where('achieved', false));

        $tagGroup = function (string $needle) use ($base) {
            return (clone $base)
                ->where(function ($query) use ($needle): void {
                    $query->where('difficulty', $needle)
                        ->orWhere('tags', 'like', "%{$needle}%");
                })
                ->latest('updated_at')
                ->limit(5)
                ->get();
        };

        return [
            'Targets' => (clone $base)->where('status', 'target')->latest('updated_at')->limit(5)->get(),
            'Later' => (clone $base)->where('status', 'later')->latest('updated_at')->limit(5)->get(),
            'Grinds' => $tagGroup('grind'),
            'Missables' => $tagGroup('missable'),
            'Co-op' => (clone $base)
                ->where(function ($query): void {
                    $query->where('difficulty', 'multiplayer')
                        ->orWhere('tags', 'like', '%co-op%')
                        ->orWhere('tags', 'like', '%coop%')
                        ->orWhere('tags', 'like', '%online%');
                })
                ->latest('updated_at')
                ->limit(5)
                ->get(),
        ];
    }

    private function overviewStats(): array
    {
        $activeGames = $this->supportedGamesQuery()
            ->where(function ($query): void {
                $query->whereNull('achievements_synced_at')
                    ->orWhere('achievements_total', '>', 0);
            })
            ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true));

        $totalAchievements = (int) (clone $activeGames)->sum('achievements_total');
        $unlockedAchievements = (int) (clone $activeGames)->sum('achievements_unlocked');
        $completion = $totalAchievements > 0 ? (int) round(($unlockedAchievements / $totalAchievements) * 100) : 0;

        $syncedGames = (clone $activeGames)->whereNotNull('achievements_synced_at')->where('achievements_total', '>', 0)->get();
        $bands = [
            '0-24%' => 0,
            '25-49%' => 0,
            '50-74%' => 0,
            '75-99%' => 0,
            '100%' => 0,
        ];

        foreach ($syncedGames as $game) {
            if ($game->completion_percent === 100) {
                $bands['100%']++;
            } elseif ($game->completion_percent >= 75) {
                $bands['75-99%']++;
            } elseif ($game->completion_percent >= 50) {
                $bands['50-74%']++;
            } elseif ($game->completion_percent >= 25) {
                $bands['25-49%']++;
            } else {
                $bands['0-24%']++;
            }
        }

        $maxBand = max($bands) ?: 1;

        return [
            'games' => (clone $activeGames)->count(),
            'synced_games' => $syncedGames->count(),
            'completed_games' => (clone $activeGames)->where('achievements_total', '>', 0)->whereColumn('achievements_unlocked', '>=', 'achievements_total')->count(),
            'in_progress_games' => (clone $activeGames)->where('achievements_total', '>', 0)->whereColumn('achievements_unlocked', '<', 'achievements_total')->count(),
            'total_achievements' => $totalAchievements,
            'unlocked_achievements' => $unlockedAchievements,
            'locked_achievements' => max(0, $totalAchievements - $unlockedAchievements),
            'completion' => $completion,
            'rare_missing' => SteamAchievement::query()->whereHas('game', fn ($query) => $this->supportedGameRelation($query))->where('achieved', false)->where('global_percent', '>', 0)->where('global_percent', '<=', 10)->count(),
            'secret_locked' => SteamAchievement::query()->whereHas('game', fn ($query) => $this->supportedGameRelation($query))->where('achieved', false)->where('hidden', true)->count(),
            'targets' => AchievementHuntSetting::query()
                ->where('status', 'target')
                ->whereHas('achievement.game', fn ($query) => $this->supportedGameRelation($query))
                ->count(),
            'bands' => $bands,
            'max_band' => $maxBand,
            'top_playtime' => $this->supportedGamesQuery()
                ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true))
                ->where('playtime_forever', '>', 0)
                ->orderByDesc('playtime_forever')
                ->limit(5)
                ->get(),
            'recently_played' => $this->supportedGamesQuery()
                ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true))
                ->whereNotNull('last_played_at')
                ->orderByDesc('last_played_at')
                ->limit(5)
                ->get(),
        ];
    }

    private function recentAchievements()
    {
        return SteamAchievement::query()
            ->with('game')
            ->whereHas('game', fn ($query) => $this->supportedGameRelation($query))
            ->where('achieved', true)
            ->where('unlock_time', '>=', now()->subDay()->timestamp)
            ->orderByDesc('unlock_time')
            ->limit(12)
            ->get();
    }

    private function friendActivity()
    {
        return SteamAchievement::query()
            ->with(['game.user'])
            ->where('achieved', true)
            ->where('unlock_time', '>=', now()->subDay()->timestamp)
            ->whereHas('game', fn ($query) => $query
                ->where('user_id', '!=', Auth::id())
                ->when(Schema::hasColumn('steam_games', 'platform'), fn ($query) => $query->whereIn('platform', array_keys(SteamGame::PLATFORMS))))
            ->orderByDesc('unlock_time')
            ->limit(8)
            ->get();
    }

    private function staleGames()
    {
        return $this->supportedGamesQuery()
            ->where('achievements_total', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('achievements_synced_at')
                    ->orWhere('achievements_synced_at', '<', now()->subDay());
            })
            ->orderBy('achievements_synced_at')
            ->limit(6)
            ->get();
    }

    private function refreshStatus(): array
    {
        $payload = TrackerSetting::value('refresh_status:'.Auth::id());

        if (! $payload) {
            return [
                'label' => 'Not run yet',
                'checked' => 0,
                'synced' => 0,
                'failed' => 0,
                'ran_at' => null,
            ];
        }

        $status = json_decode($payload, true) ?: [];
        $ranAt = isset($status['ran_at']) ? Carbon::parse($status['ran_at']) : null;

        return [
            'label' => $status['label'] ?? 'Refresh',
            'checked' => (int) ($status['checked'] ?? 0),
            'synced' => (int) ($status['synced'] ?? 0),
            'failed' => (int) ($status['failed'] ?? 0),
            'ran_at' => $ranAt,
        ];
    }

    private function recordRefreshStatus(string $label, int $checked, int $synced, int $failed): void
    {
        TrackerSetting::query()->updateOrCreate(
            ['key' => 'refresh_status:'.Auth::id()],
            ['value' => json_encode([
                'label' => $label,
                'checked' => $checked,
                'synced' => $synced,
                'failed' => $failed,
                'ran_at' => now()->toIso8601String(),
            ])],
        );
    }

    private function syncIssues()
    {
        $payload = TrackerSetting::value('sync_issues:'.Auth::id(), '[]');

        return collect(json_decode($payload, true) ?: [])
            ->map(function (array $issue): array {
                return [
                    'platform' => $issue['platform'] ?? 'Tracker',
                    'label' => $issue['label'] ?? 'Sync issue',
                    'message' => $issue['message'] ?? 'No details recorded.',
                    'ran_at' => isset($issue['ran_at']) ? Carbon::parse($issue['ran_at']) : null,
                ];
            });
    }

    private function recordSyncIssue(string $platform, string $label, string $message): void
    {
        $issues = $this->syncIssues()
            ->map(fn (array $issue): array => [
                'platform' => $issue['platform'],
                'label' => $issue['label'],
                'message' => $issue['message'],
                'ran_at' => $issue['ran_at']?->toIso8601String(),
            ])
            ->prepend([
                'platform' => $platform,
                'label' => $label,
                'message' => substr($message, 0, 240),
                'ran_at' => now()->toIso8601String(),
            ])
            ->take(10)
            ->values();

        TrackerSetting::query()->updateOrCreate(
            ['key' => 'sync_issues:'.Auth::id()],
            ['value' => $issues->toJson()],
        );
    }

    private function friends(SteamAchievementClient $steam)
    {
        try {
            $friendSteamIds = collect($steam->friendSteamIds())->filter()->values();
        } catch (Throwable) {
            $friendSteamIds = collect();
        }

        if ($friendSteamIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereKeyNot(Auth::id())
            ->whereIn('steam_id', $friendSteamIds)
            ->orderBy('name')
            ->get();
    }

    private function authorizeGame(SteamGame $game): void
    {
        abort_unless((int) $game->user_id === (int) Auth::id(), 404);
    }

    private function authorizeAchievement(SteamAchievement $achievement): void
    {
        $achievement->loadMissing('game');
        $this->authorizeGame($achievement->game);
    }
}
