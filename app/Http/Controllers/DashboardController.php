<?php

namespace App\Http\Controllers;

use App\Models\SteamGame;
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
        $gameFilter = $request->query('game_filter', 'all');
        $baseGamesQuery = SteamGame::query()
            ->withCount([
                'achievements as secret_count' => fn ($query) => $query->where('hidden', true),
                'achievements as rare_count' => fn ($query) => $query->where('global_percent', '>', 0)->where('global_percent', '<=', 10),
            ])
            ->where(function ($query): void {
                $query->whereNull('achievements_synced_at')
                    ->orWhere('achievements_total', '>', 0);
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
        ];

        $gamesQuery = clone $baseGamesQuery;

        if ($gameFilter === 'in_progress') {
            $gamesQuery->where('achievements_total', '>', 0)
                ->whereColumn('achievements_unlocked', '<', 'achievements_total');
        } elseif ($gameFilter === 'completed') {
            $gamesQuery->where('achievements_total', '>', 0)
                ->whereColumn('achievements_unlocked', '>=', 'achievements_total');
        } elseif ($gameFilter === 'unchecked') {
            $gamesQuery->whereNull('achievements_synced_at');
        } else {
            $gameFilter = 'all';
        }

        $games = $gamesQuery
            ->orderByDesc('is_current')
            ->orderByDesc('last_played_at')
            ->orderByDesc('playtime_2weeks')
            ->orderByDesc('playtime_forever')
            ->orderBy('name')
            ->get();

        $currentGame = $games->firstWhere('is_current', true) ?? $games->first();

        $achievementQuery = $currentGame
            ? $currentGame->achievements()->orderBy('achieved')->orderByRaw('global_percent is null, global_percent asc')->orderBy('name')
            : null;

        $filter = $request->query('filter', 'all');

        if ($achievementQuery && $filter === 'locked') {
            $achievementQuery->where('achieved', false);
        } elseif ($achievementQuery && $filter === 'unlocked') {
            $achievementQuery->where('achieved', true);
        } elseif ($achievementQuery && $filter === 'secret') {
            $achievementQuery->where('hidden', true);
        } elseif ($achievementQuery && $filter === 'rare') {
            $achievementQuery->where('global_percent', '>', 0)->where('global_percent', '<=', 10);
        }

        return view('dashboard', [
            'games' => $games,
            'currentGame' => $currentGame,
            'achievements' => $achievementQuery?->get() ?? collect(),
            'filter' => $filter,
            'gameFilter' => $gameFilter,
            'gameCounts' => $gameCounts,
            'configured' => config('services.steam.api_key') && config('services.steam.steam_id'),
            'unsyncedGames' => SteamGame::whereNull('achievements_synced_at')->count(),
        ]);
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

    public function setCurrent(SteamGame $game, SteamAchievementClient $steam): RedirectResponse
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

        return redirect()->route('dashboard')->with('status', "{$game->name} is now your current game.");
    }

    private function message(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException) {
            return $exception->getMessage();
        }

        return 'Steam did not answer cleanly. Check your API key, Steam ID, and profile privacy.';
    }
}
