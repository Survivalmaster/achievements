<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::get('/auth/steam', [AuthController::class, 'redirectToSteam'])->name('steam.redirect');
Route::get('/auth/steam/callback', [AuthController::class, 'handleSteamCallback'])->name('steam.callback');
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

Route::middleware('dashboard.auth')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/psn/link', [DashboardController::class, 'linkPsn'])->name('psn.link');
    Route::post('/psn/sync', [DashboardController::class, 'syncPsnLibrary'])->name('psn.sync');
    Route::post('/platforms/{platform}/link', [DashboardController::class, 'linkExternalPlatform'])->name('platforms.link');
    Route::post('/sync/library', [DashboardController::class, 'syncLibrary'])->name('sync.library');
    Route::post('/sync/achievements', [DashboardController::class, 'syncAchievements'])->name('sync.achievements');
    Route::post('/sync/refresh-all', [DashboardController::class, 'refreshAllAchievements'])->name('sync.refresh-all');
    Route::post('/sync/quick-refresh', [DashboardController::class, 'quickRefreshAchievements'])->name('sync.quick-refresh');
    Route::post('/hunt-session/start', [DashboardController::class, 'startHuntSession'])->name('hunt-session.start');
    Route::post('/spoilers', [DashboardController::class, 'updateSpoilers'])->name('spoilers.update');
    Route::get('/games/{game}', [DashboardController::class, 'showGame'])->name('games.show');
    Route::post('/games/{game}/current', [DashboardController::class, 'setCurrent'])->name('games.current');
    Route::post('/games/{game}/hunt', [DashboardController::class, 'updateGame'])->name('games.hunt');
    Route::post('/games/{game}/refresh', [DashboardController::class, 'refreshGame'])->name('games.refresh');
    Route::post('/achievements/{achievement}/hunt', [DashboardController::class, 'updateAchievement'])->name('achievements.hunt');
});
