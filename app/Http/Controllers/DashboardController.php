<?php

namespace App\Http\Controllers;

use App\Models\AchievementHuntSetting;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\TrackerSetting;
use App\Models\User;
use App\Services\EpicGamesClient;
use App\Models\UserPlatformAccount;
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

    public function showGame(Request $request, SteamGame $game, SteamAchievementClient $steam): View
    {
        $this->authorizeGame($game);

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

        if ($supportsPlatform && $platformFilter !== 'all') {
            $baseGamesQuery->where('platform', $platformFilter);
        } elseif (! $supportsPlatform && $platformFilter === SteamGame::PLATFORM_PSN) {
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
            'archived' => SteamGame::query()
                ->where('user_id', Auth::id())
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

        $platformCounts = ['all' => (clone $platformCountsQuery)->count()];

        foreach (SteamGame::PLATFORMS as $key => $label) {
            $platformCounts[$key] = match (true) {
                $supportsPlatform => (clone $platformCountsQuery)->where('platform', $key)->count(),
                $key === SteamGame::PLATFORM_STEAM => (clone $platformCountsQuery)->count(),
                default => 0,
            };
        }

        $gamesQuery = $gameFilter === 'archived'
            ? SteamGame::query()->where('user_id', Auth::id())->with('huntSetting')->whereHas('huntSetting', fn ($setting) => $setting->where('archived', true))
            : clone $baseGamesQuery;

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
            'recentAchievements' => $this->recentAchievements(),
            'roadmapGames' => $this->roadmapGames(),
            'rarestUnlocked' => $this->rareAchievements(true),
            'rarestMissing' => $this->rareAchievements(false),
            'plannedAchievements' => $this->plannedAchievements(),
            'tonightAchievements' => $this->tonightAchievements(),
            'refreshStatus' => $this->refreshStatus(),
            'friendActivity' => $this->friendActivity(),
            'staleGames' => $this->staleGames(),
            'psnAccount' => $this->psnAccount(),
            'epicAccount' => $this->epicAccount(),
            'epicAuthUrl' => app(EpicGamesClient::class)->authUrl(),
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
            return back()->with('error', $this->message($exception));
        }

        return back()->with('status', 'PSN linked. Sync PlayStation to pull your trophy titles.');
    }

    public function syncPsnLibrary(Request $request, PsnTrophyClient $psn): RedirectResponse
    {
        try {
            $count = $psn->syncLibrary($request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $this->message($exception));
        }

        return back()->with('status', "Synced {$count} PlayStation trophy titles.");
    }

    public function linkEpic(Request $request, EpicGamesClient $epic): RedirectResponse
    {
        $data = $request->validate([
            'authorization_code' => ['required', 'string', 'max:500'],
        ]);

        try {
            $epic->link($request->user(), $data['authorization_code']);
        } catch (Throwable $exception) {
            return back()->with('error', $this->message($exception));
        }

        return back()->with('status', 'Epic linked. Sync Epic to pull your library.');
    }

    public function syncEpicLibrary(Request $request, EpicGamesClient $epic): RedirectResponse
    {
        try {
            $count = $epic->syncLibrary($request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $this->message($exception));
        }

        return back()->with('status', "Synced {$count} Epic library items.");
    }

    public function syncLibrary(SteamAchievementClient $steam): RedirectResponse
    {
        try {
            $count = $steam->syncLibrary();
        } catch (Throwable $exception) {
            return back()->with('error', $this->message($exception));
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
            if ($request->expectsJson()) {
                return response()->json(['message' => $this->message($exception)], 500);
            }

            return back()->with('error', $this->message($exception));
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
            if ($request->expectsJson()) {
                return response()->json(['message' => $this->message($exception)], 500);
            }

            return back()->with('error', $this->message($exception));
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
            return response()->json(['message' => $this->message($exception)], 500);
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

    public function refreshGame(SteamGame $game, SteamAchievementClient $steam, PsnTrophyClient $psn, EpicGamesClient $epic): RedirectResponse
    {
        $this->authorizeGame($game);
        $beforeUnlocked = $game->achievements_unlocked;
        $beforeTotal = $game->achievements_total;
        $beforeAchievements = $game->achievements()->where('achieved', true)->pluck('name')->all();

        try {
            if ($game->platform_key === SteamGame::PLATFORM_PSN) {
                $psn->syncGame($game);
            } elseif ($game->platform_key === SteamGame::PLATFORM_EPIC) {
                $epic->syncGame($game);
            } else {
                $steam->syncLibrary();
                $steam->syncAchievements($game);
            }
        } catch (Throwable $exception) {
            return back()->with('error', $this->message($exception));
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

    public function setCurrent(Request $request, SteamGame $game, SteamAchievementClient $steam, PsnTrophyClient $psn, EpicGamesClient $epic): RedirectResponse
    {
        $this->authorizeGame($game);

        SteamGame::where('user_id', Auth::id())->update(['is_current' => false]);
        $game->update(['is_current' => true]);

        if (! $game->achievements_synced_at) {
            try {
                if ($game->platform_key === SteamGame::PLATFORM_PSN) {
                    $psn->syncGame($game);
                } else {
                    $steam->syncAchievements($game);
                }
            } catch (Throwable $exception) {
                return back()->with('error', $this->message($exception));
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
            'difficulty' => ['nullable', 'in:easy,normal,hard,grind,buggy,multiplayer,missable'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $game->huntSetting()->updateOrCreate(
            ['steam_game_id' => $game->id],
            [
                'note' => $data['note'] ?? null,
                'tags' => $data['tags'] ?? null,
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

    private function epicAccount(): ?UserPlatformAccount
    {
        return UserPlatformAccount::query()
            ->where('user_id', Auth::id())
            ->where('platform', SteamGame::PLATFORM_EPIC)
            ->whereNotNull('access_token')
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

    private function roadmapGames()
    {
        return SteamGame::query()
            ->where('user_id', Auth::id())
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

    private function overviewStats(): array
    {
        $activeGames = SteamGame::query()
            ->where('user_id', Auth::id())
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
            'rare_missing' => SteamAchievement::query()->whereHas('game', fn ($query) => $query->where('user_id', Auth::id()))->where('achieved', false)->where('global_percent', '>', 0)->where('global_percent', '<=', 10)->count(),
            'secret_locked' => SteamAchievement::query()->whereHas('game', fn ($query) => $query->where('user_id', Auth::id()))->where('achieved', false)->where('hidden', true)->count(),
            'targets' => AchievementHuntSetting::query()
                ->where('status', 'target')
                ->whereHas('achievement.game', fn ($query) => $query->where('user_id', Auth::id()))
                ->count(),
            'bands' => $bands,
            'max_band' => $maxBand,
            'top_playtime' => SteamGame::query()
                ->where('user_id', Auth::id())
                ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true))
                ->where('playtime_forever', '>', 0)
                ->orderByDesc('playtime_forever')
                ->limit(5)
                ->get(),
            'recently_played' => SteamGame::query()
                ->where('user_id', Auth::id())
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
            ->whereHas('game', fn ($query) => $query->where('user_id', Auth::id()))
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
            ->whereHas('game', fn ($query) => $query->where('user_id', '!=', Auth::id()))
            ->orderByDesc('unlock_time')
            ->limit(8)
            ->get();
    }

    private function staleGames()
    {
        return SteamGame::query()
            ->where('user_id', Auth::id())
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
