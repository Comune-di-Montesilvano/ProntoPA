<?php

use App\Http\Controllers\Admin\ImpostazioniController;
use App\Http\Controllers\AppaltiController;
use App\Http\Controllers\GestioneController;
use App\Http\Controllers\ImpreseCRUDController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleDashboardController;
use App\Http\Controllers\SegnalazioneController;
use App\Http\Controllers\SegnalatoreDashboardController;
use App\Http\Controllers\StatisticheController;
use Illuminate\Support\Facades\Route;

// Home
Route::get('/', function () {
    return view('welcome');
});

// Dashboard — dispatcher per ruolo
Route::get('/dashboard', [RoleDashboardController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

// ── Segnalazioni (tutti gli autenticati) ──────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::resource('segnalazioni', SegnalazioneController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['segnalazioni' => 'segnalazione']);

    Route::post('segnalazioni/{segnalazione}/azione', [SegnalazioneController::class, 'eseguiAzione'])
        ->name('segnalazioni.azione');

    Route::post('segnalazioni/{segnalazione}/nota', [SegnalazioneController::class, 'aggiungiNota'])
        ->name('segnalazioni.nota');

    Route::post('segnalazioni/{segnalazione}/evidenza', [SegnalazioneController::class, 'toggleEvidenza'])
        ->name('segnalazioni.evidenza');
});

// ── Gestione (admin + gestore) ────────────────────────────────────────────────
Route::middleware(['auth', 'role:admin|gestore'])->prefix('gestione')->name('gestione.')->group(function () {
    Route::get('/', [GestioneController::class, 'index'])->name('dashboard');
});

// ── Imprese CRUD (admin + gestore) ────────────────────────────────────────────
Route::middleware(['auth', 'role:admin|gestore'])
    ->resource('imprese', ImpreseCRUDController::class)
    ->except(['show'])
    ->names('imprese')
    ->parameters(['imprese' => 'impresa']);

// ── Appalti CRUD (admin + gestore) ────────────────────────────────────────────
Route::middleware(['auth', 'role:admin|gestore'])
    ->resource('appalti', AppaltiController::class)
    ->except(['show'])
    ->names('appalti')
    ->parameters(['appalti' => 'appalto']);

// ── Statistiche (admin + gestore) ─────────────────────────────────────────────
Route::middleware(['auth', 'role:admin|gestore'])
    ->get('/statistiche', [StatisticheController::class, 'index'])
    ->name('statistiche.index');

// ── Admin ─────────────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', fn () => view('admin.dashboard'))->name('dashboard');

    Route::get('/impostazioni', [ImpostazioniController::class, 'index'])->name('impostazioni.index');
    Route::patch('/impostazioni', [ImpostazioniController::class, 'update'])->name('impostazioni.update');
});

// ── Segnalatore ───────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:segnalatore'])->prefix('segnalatore')->name('segnalatore.')->group(function () {
    Route::get('/', [SegnalatoreDashboardController::class, 'index'])->name('dashboard');
});

// ── Imprese (portale impresa) ─────────────────────────────────────────────────
Route::middleware(['auth', 'role:impresa'])->prefix('imprese-portale')->name('imprese.')->group(function () {
    Route::get('/dashboard', fn () => view('imprese.dashboard'))->name('dashboard');
});

// ── Profilo (tutti gli utenti autenticati) ────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
