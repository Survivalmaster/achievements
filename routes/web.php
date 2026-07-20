<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

Route::middleware('dashboard.auth')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/sync/library', [DashboardController::class, 'syncLibrary'])->name('sync.library');
    Route::post('/games/{game}/current', [DashboardController::class, 'setCurrent'])->name('games.current');
    Route::post('/games/{game}/refresh', [DashboardController::class, 'refreshGame'])->name('games.refresh');
});
