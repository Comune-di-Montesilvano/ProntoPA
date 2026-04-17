<?php

use App\Http\Controllers\Admin\ImpostazioniController;
use App\Http\Controllers\Admin\OrganizzazioniController;
use App\Http\Controllers\Admin\ProfiliController;
use App\Http\Controllers\Admin\ProvenienzaController;
use App\Http\Controllers\Admin\SediController;
use App\Http\Controllers\Admin\UtentiController;
use App\Http\Controllers\AppaltiController;
use App\Http\Controllers\GestioneController;
use App\Http\Controllers\ImpreseCRUDController;
use App\Http\Controllers\PublicHomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleDashboardController;
use App\Http\Controllers\SegnalazioneController;
use App\Http\Controllers\SegnalatoreDashboardController;
use App\Http\Controllers\StatisticheController;
use App\Http\Controllers\TelegramAccountController;
use Illuminate\Support\Facades\Route;

// Home
Route::get('/', [PublicHomeController::class, 'index'])->name('home');

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

    Route::get('segnalazioni/{segnalazione}/stampa', [SegnalazioneController::class, 'stampa'])
        ->name('segnalazioni.stampa');

    Route::patch('segnalazioni/{segnalazione}/toggle-riservata', [SegnalazioneController::class, 'toggleRiservata'])
        ->name('segnalazioni.toggle-riservata');
});

// ── Gestione (admin + gestore) ────────────────────────────────────────────────
Route::middleware(['auth', 'role:admin|gestore'])->prefix('gestione')->name('gestione.')->group(function () {
    Route::get('/', [GestioneController::class, 'index'])->name('dashboard');
    Route::get('/stampa', [GestioneController::class, 'stampaLista'])->name('stampa');
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

    Route::patch('utenti/{utente}/attivo', [UtentiController::class, 'toggleAttivo'])
        ->name('utenti.toggle-attivo');

    Route::resource('utenti', UtentiController::class)
        ->except(['show'])
        ->parameters(['utenti' => 'utente']);

    Route::resource('organizzazioni', OrganizzazioniController::class)
        ->except(['show'])
        ->parameters(['organizzazioni' => 'organizzazione']);

    Route::resource('sedi', SediController::class)
        ->except(['show'])
        ->parameters(['sedi' => 'sede']);

    Route::resource('profili', ProfiliController::class)
        ->except(['show'])
        ->parameters(['profili' => 'profilo']);

    Route::resource('provenienze', ProvenienzaController::class)
        ->except(['show'])
        ->parameters(['provenienze' => 'provenienza']);
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
    Route::post('/profile/telegram/link', [TelegramAccountController::class, 'store'])->name('profile.telegram.store');
    Route::delete('/profile/telegram/link', [TelegramAccountController::class, 'destroy'])->name('profile.telegram.destroy');
});

require __DIR__.'/auth.php';
