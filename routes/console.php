<?php

use App\Models\User;
use App\Services\OpenXblClient;
use App\Services\PsnTrophyClient;
use App\Services\SteamAchievementClient;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('steam:sync-achievements {--limit=0 : Maximum games to check, 0 means all unsynced games}', function (SteamAchievementClient $steam): int {
    $totalSynced = 0;
    $totalFailed = 0;
    $remainingLimit = (int) $this->option('limit');
    $syncAll = $remainingLimit === 0;

    do {
        $batchSize = $syncAll ? 25 : min($remainingLimit, 25);
        $result = $steam->syncAchievementBatch($batchSize);

        $totalSynced += $result['synced'];
        $totalFailed += $result['failed'];

        $this->info("Checked {$result['attempted']} games. Synced {$result['synced']}, failed {$result['failed']}, remaining {$result['remaining']}.");

        if (! $syncAll) {
            $remainingLimit -= $result['attempted'];
        }

        if ($result['attempted'] > 0 && $result['synced'] === 0) {
            $this->warn('Stopping because the latest batch did not sync any games.');
            break;
        }
    } while ($result['attempted'] > 0 && $result['remaining'] > 0 && ($syncAll || $remainingLimit > 0));

    $this->info("Done. Synced {$totalSynced} games; {$totalFailed} failed.");

    return 0;
})->purpose('Sync Steam achievement data for unsynced games');

Artisan::command('steam:refresh-active-achievements {--games=20 : Maximum games to refresh per user}', function (SteamAchievementClient $steam): int {
    $gamesPerUser = max(1, min((int) $this->option('games'), 50));
    $totalUsers = 0;
    $totalSynced = 0;
    $totalFailed = 0;

    User::query()
        ->whereNotNull('steam_id')
        ->orderBy('id')
        ->each(function (User $user) use ($steam, $gamesPerUser, &$totalUsers, &$totalSynced, &$totalFailed): void {
            Auth::guard()->setUser($user);
            $totalUsers++;

            try {
                $steam->syncLibrary();
                $initial = $steam->syncAchievementBatch($gamesPerUser);
                $result = $steam->refreshActiveAchievementBatch($gamesPerUser);

                $totalSynced += $initial['synced'] + $result['synced'];
                $totalFailed += $initial['failed'] + $result['failed'];

                $this->info("{$user->name}: synced {$initial['synced']} new, refreshed {$result['synced']} active, ".($initial['failed'] + $result['failed']).' failed.');
            } catch (Throwable $exception) {
                $totalFailed++;
                $this->warn("{$user->name}: {$exception->getMessage()}");
            } finally {
                Auth::guard()->forgetUser();
            }
        });

    $this->info("Done. Checked {$totalUsers} users, refreshed {$totalSynced} games, {$totalFailed} failed.");

    return 0;
})->purpose('Refresh likely-active Steam achievement data for every tracker user');

Artisan::command('psn:refresh-active-trophies {--games=8 : Maximum PSN games to refresh per user}', function (PsnTrophyClient $psn): int {
    $gamesPerUser = max(1, min((int) $this->option('games'), 25));
    $totalUsers = 0;
    $totalSynced = 0;
    $totalFailed = 0;

    User::query()
        ->whereHas('platformAccounts', fn ($query) => $query->where('platform', 'psn'))
        ->orderBy('id')
        ->each(function (User $user) use ($psn, $gamesPerUser, &$totalUsers, &$totalSynced, &$totalFailed): void {
            $totalUsers++;

            try {
                $psn->syncLibrary($user);
                $result = $psn->refreshActiveTrophyBatch($user, $gamesPerUser);

                $totalSynced += $result['synced'];
                $totalFailed += $result['failed'];

                $this->info("{$user->name}: refreshed {$result['synced']} PlayStation titles, {$result['failed']} failed.");
            } catch (Throwable $exception) {
                $totalFailed++;
                $this->warn("{$user->name}: {$exception->getMessage()}");
            }
        });

    $this->info("Done. Checked {$totalUsers} PSN-linked users, refreshed {$totalSynced} titles, {$totalFailed} failed.");

    return 0;
})->purpose('Refresh likely-active PlayStation trophy data for every PSN-linked tracker user');

Artisan::command('xbox:refresh-active-achievements {--games=8 : Maximum Xbox games to refresh per user}', function (OpenXblClient $xbox): int {
    $gamesPerUser = max(1, min((int) $this->option('games'), 25));
    $totalUsers = 0;
    $totalSynced = 0;
    $totalFailed = 0;

    User::query()
        ->whereHas('platformAccounts', fn ($query) => $query->where('platform', 'xbox'))
        ->orderBy('id')
        ->each(function (User $user) use ($xbox, $gamesPerUser, &$totalUsers, &$totalSynced, &$totalFailed): void {
            $totalUsers++;

            try {
                $xbox->syncLibrary($user);
                $result = $xbox->refreshActiveAchievementBatch($user, $gamesPerUser);

                $totalSynced += $result['synced'];
                $totalFailed += $result['failed'];

                $this->info("{$user->name}: refreshed {$result['synced']} Xbox titles, {$result['failed']} failed.");
            } catch (Throwable $exception) {
                $totalFailed++;
                $this->warn("{$user->name}: {$exception->getMessage()}");
            }
        });

    $this->info("Done. Checked {$totalUsers} Xbox-linked users, refreshed {$totalSynced} titles, {$totalFailed} failed.");

    return 0;
})->purpose('Refresh likely-active Xbox achievement data for every Xbox-linked tracker user');

Schedule::command('steam:refresh-active-achievements --games=20')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('psn:refresh-active-trophies --games=8')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('xbox:refresh-active-achievements --games=8')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
