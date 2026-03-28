<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleDashboardController;
use Illuminate\Support\Facades\Route;

// Home
Route::get('/', function () {
    return view('welcome');
});

// Dashboard — dispatcher per ruolo
Route::get('/dashboard', [RoleDashboardController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

// ── Gestione (admin + gestore) ────────────────────────────────────────────────
Route::middleware(['auth', 'role:admin|gestore'])->prefix('gestione')->name('gestione.')->group(function () {
    Route::get('/', fn () => view('gestione.dashboard'))->name('dashboard');
});

// ── Admin ─────────────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', fn () => view('admin.dashboard'))->name('dashboard');
});

// ── Segnalatore ───────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:segnalatore'])->prefix('segnalatore')->name('segnalatore.')->group(function () {
    Route::get('/', fn () => view('segnalatore.dashboard'))->name('dashboard');
});

// ── Imprese ───────────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:impresa'])->prefix('imprese')->name('imprese.')->group(function () {
    Route::get('/', fn () => view('imprese.dashboard'))->name('dashboard');
});

// ── Profilo (tutti gli utenti autenticati) ────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
