<?php

use App\Services\SteamAchievementClient;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
