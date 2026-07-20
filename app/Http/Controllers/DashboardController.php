<?php

namespace App\Http\Controllers;

use App\Models\AchievementHuntSetting;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\TrackerSetting;
use App\Services\SteamAchievementClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function showGame(Request $request, SteamGame $game): View
    {
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

        return view('dashboard', [
            ...$payload,
            'mode' => 'game',
            'currentGame' => $game,
            'achievements' => $achievementQuery->get(),
            'filter' => $filter,
            'history' => $game->progressSnapshots()->latest('taken_at')->limit(8)->get(),
            'overview' => $this->overviewStats(),
        ]);
    }

    private function basePayload(Request $request): array
    {
        $gameFilter = $request->query('game_filter', 'all');
        $baseGamesQuery = SteamGame::query()
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
                ->whereHas('huntSetting', fn ($setting) => $setting->where('archived', true))
                ->count(),
        ];

        $gamesQuery = $gameFilter === 'archived'
            ? SteamGame::query()->with('huntSetting')->whereHas('huntSetting', fn ($setting) => $setting->where('archived', true))
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
            ->orderByDesc('last_played_at')
            ->orderByDesc('playtime_2weeks')
            ->orderByDesc('playtime_forever')
            ->orderBy('name')
            ->get();

        $spoilerSafe = TrackerSetting::value('spoiler_safe', '0') === '1';

        return [
            'games' => $games,
            'gameFilter' => $gameFilter,
            'gameCounts' => $gameCounts,
            'configured' => config('services.steam.api_key') && config('services.steam.steam_id'),
            'unsyncedGames' => SteamGame::whereNull('achievements_synced_at')->count(),
            'spoilerSafe' => $spoilerSafe,
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
        try {
            $steam->syncAchievements($game);
        } catch (Throwable $exception) {
            return back()->with('error', $this->message($exception));
        }

        return back()->with('status', "Refreshed achievements for {$game->name}.");
    }

    public function setCurrent(Request $request, SteamGame $game, SteamAchievementClient $steam): RedirectResponse
    {
        SteamGame::query()->update(['is_current' => false]);
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
            ['key' => 'spoiler_safe'],
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
                $query->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true));
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
                $query->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true));
            })
            ->orderBy('achieved')
            ->orderByRaw('global_percent is null, global_percent asc')
            ->limit(8)
            ->get();
    }

    private function tonightAchievements()
    {
        $targets = SteamAchievement::query()
            ->with(['game', 'huntSetting'])
            ->where('achieved', false)
            ->whereHas('huntSetting', fn ($setting) => $setting->where('status', 'target'))
            ->whereHas('game', function ($query): void {
                $query->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true));
            })
            ->orderByRaw('global_percent is null, global_percent asc')
            ->limit(4)
            ->get();

        if ($targets->count() >= 4) {
            return $targets;
        }

        $rare = SteamAchievement::query()
            ->with(['game', 'huntSetting'])
            ->where('achieved', false)
            ->where('global_percent', '>', 0)
            ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('status', 'ignore'))
            ->whereHas('game', function ($query): void {
                $query->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true));
            })
            ->orderBy('global_percent')
            ->limit(4 - $targets->count())
            ->get();

        return $targets->concat($rare);
    }

    private function overviewStats(): array
    {
        $activeGames = SteamGame::query()
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
            'rare_missing' => SteamAchievement::query()->where('achieved', false)->where('global_percent', '>', 0)->where('global_percent', '<=', 10)->count(),
            'secret_locked' => SteamAchievement::query()->where('achieved', false)->where('hidden', true)->count(),
            'targets' => AchievementHuntSetting::query()->where('status', 'target')->count(),
            'bands' => $bands,
            'max_band' => $maxBand,
            'top_playtime' => SteamGame::query()
                ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true))
                ->where('playtime_forever', '>', 0)
                ->orderByDesc('playtime_forever')
                ->limit(5)
                ->get(),
            'recently_played' => SteamGame::query()
                ->whereDoesntHave('huntSetting', fn ($setting) => $setting->where('archived', true))
                ->whereNotNull('last_played_at')
                ->orderByDesc('last_played_at')
                ->limit(5)
                ->get(),
        ];
    }
}
