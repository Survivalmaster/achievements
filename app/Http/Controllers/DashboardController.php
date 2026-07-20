<?php

namespace App\Http\Controllers;

use App\Models\AchievementHuntSetting;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\TrackerSetting;
use App\Services\SteamAchievementClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $compareSteamId = $request->query('compare_steam_id') ?: $request->query('friend_steam_id');
        $comparison = collect();
        $compareProfile = null;

        if (is_string($compareSteamId) && preg_match('/^\d{17}$/', $compareSteamId)) {
            try {
                $friendAchievements = collect($steam->playerAchievementsFor($game->appid, $compareSteamId))->keyBy('apiname');
                $compareProfile = $steam->playerSummary($compareSteamId);
                $comparison = $game->achievements()
                    ->orderBy('achieved')
                    ->orderBy('name')
                    ->get()
                    ->map(function (SteamAchievement $achievement) use ($friendAchievements): array {
                        $friend = $friendAchievements->get($achievement->apiname, []);

                        return [
                            'name' => $achievement->name,
                            'you' => $achievement->achieved,
                            'friend' => (bool) ($friend['achieved'] ?? false),
                        ];
                    });
            } catch (Throwable) {
                $comparison = collect();
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
            'friends' => $this->friends($steam),
            'compareSteamId' => $compareSteamId,
            'compareProfile' => $compareProfile,
            'comparison' => $comparison,
        ]);
    }

    private function basePayload(Request $request): array
    {
        $gameFilter = $request->query('game_filter', 'all');
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

        $gamesQuery = $gameFilter === 'archived'
            ? SteamGame::query()->where('user_id', Auth::id())->with('huntSetting')->whereHas('huntSetting', fn ($setting) => $setting->where('archived', true))
            : clone $baseGamesQuery;

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
            'gameCounts' => $gameCounts,
            'configured' => (bool) config('services.steam.api_key'),
            'unsyncedGames' => SteamGame::where('user_id', Auth::id())->whereNull('achievements_synced_at')->count(),
            'spoilerSafe' => $spoilerSafe,
            'recentAchievements' => $this->recentAchievements(),
            'roadmapGames' => $this->roadmapGames(),
            'rarestUnlocked' => $this->rareAchievements(true),
            'rarestMissing' => $this->rareAchievements(false),
            'plannedAchievements' => $this->plannedAchievements(),
            'tonightAchievements' => $this->tonightAchievements(),
        ];
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

    public function syncAchievements(SteamAchievementClient $steam): RedirectResponse
    {
        try {
            $result = $steam->syncAchievementBatch(15);
        } catch (Throwable $exception) {
            return back()->with('error', $this->message($exception));
        }

        if ($result['attempted'] === 0) {
            return back()->with('status', 'All games have achievement data checked.');
        }

        $message = "Checked {$result['synced']} games";

        if ($result['failed'] > 0) {
            $message .= " ({$result['failed']} failed)";
        }

        return back()->with('status', "{$message}. {$result['remaining']} games left to check.");
    }

    public function refreshGame(SteamGame $game, SteamAchievementClient $steam): RedirectResponse
    {
        $this->authorizeGame($game);

        try {
            $steam->syncAchievements($game);
        } catch (Throwable $exception) {
            return back()->with('error', $this->message($exception));
        }

        return back()->with('status', "Refreshed achievements for {$game->name}.");
    }

    public function setCurrent(Request $request, SteamGame $game, SteamAchievementClient $steam): RedirectResponse
    {
        $this->authorizeGame($game);

        SteamGame::where('user_id', Auth::id())->update(['is_current' => false]);
        $game->update(['is_current' => true]);

        if (! $game->achievements_synced_at) {
            try {
                $steam->syncAchievements($game);
            } catch (Throwable $exception) {
                return back()->with('error', $this->message($exception));
            }
        }

        return redirect()
            ->route('games.show', [
                'game' => $game,
                'game_filter' => $this->gameFilter($request->input('game_filter', 'all')),
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

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', AchievementHuntSetting::STATUSES)],
            'note' => ['nullable', 'string', 'max:1200'],
            'tags' => ['nullable', 'string', 'max:255'],
        ]);

        $achievement->huntSetting()->updateOrCreate(
            ['steam_achievement_id' => $achievement->id],
            [
                'status' => $data['status'],
                'note' => $data['note'] ?? null,
                'tags' => $data['tags'] ?? null,
            ],
        );

        return back()->with('status', 'Achievement plan saved.');
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

    private function achievementFilter(mixed $filter): string
    {
        return in_array($filter, ['all', 'locked', 'unlocked', 'secret', 'rare'], true) ? $filter : 'all';
    }

    private function message(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException) {
            return $exception->getMessage();
        }

        return 'Steam did not answer cleanly. Check your API key, Steam ID, and profile privacy.';
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

    private function friends(SteamAchievementClient $steam)
    {
        try {
            return collect($steam->friendSummaries());
        } catch (Throwable) {
            return collect();
        }
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
