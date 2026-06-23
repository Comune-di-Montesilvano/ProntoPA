<?php

use App\Http\Controllers\Admin\ImpostazioniController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\OrganizzazioniController;
use App\Http\Controllers\Admin\ProfiliController;
use App\Http\Controllers\Admin\ProvenienzaController;
use App\Http\Controllers\Admin\SediController;
use App\Http\Controllers\Admin\SlaController;
use App\Http\Controllers\Admin\SquadreController;
use App\Http\Controllers\Admin\UtentiController;
use App\Http\Controllers\AdesioniSegnalazioniController;
use App\Http\Controllers\AllegatiSegnalazioniController;
use App\Http\Controllers\Auth\AnnualAccountVerificationController;
use App\Http\Controllers\AppaltiController;
use App\Http\Controllers\GestioneController;
use App\Http\Controllers\ImpreseDashboardController;
use App\Http\Controllers\ImpreseCRUDController;
use App\Http\Controllers\OperaioDashboardController;
use App\Http\Controllers\PublicHomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleDashboardController;
use App\Http\Controllers\AiTriageController;
use App\Http\Controllers\SegnalazioneController;
use App\Http\Controllers\SegnalatoreDashboardController;
use App\Http\Controllers\StatisticheController;
use App\Http\Controllers\TelegramAccountController;
use Illuminate\Support\Facades\Route;

// Home
Route::get('/', [PublicHomeController::class, 'index'])->name('home');

Route::get('/account/verify-annual/{user}/{token}', [AnnualAccountVerificationController::class, 'verify'])
    ->middleware('signed')
    ->name('account.verification.annual');

// Dashboard — dispatcher per ruolo
Route::get('/dashboard', [RoleDashboardController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

// ── Segnalazioni (tutti gli autenticati) ──────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('segnalazioni/simili', [SegnalazioneController::class, 'simili'])
        ->name('segnalazioni.simili');

    Route::resource('segnalazioni', SegnalazioneController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->parameters(['segnalazioni' => 'segnalazione']);

    Route::post('segnalazioni/{segnalazione}/allegati', [AllegatiSegnalazioniController::class, 'store'])
        ->name('segnalazioni.allegati.store');

    Route::get('segnalazioni/{segnalazione}/allegati/{allegato}/download', [AllegatiSegnalazioniController::class, 'download'])
        ->name('segnalazioni.allegati.download');

    Route::delete('segnalazioni/{segnalazione}/allegati/{allegato}', [AllegatiSegnalazioniController::class, 'destroy'])
        ->name('segnalazioni.allegati.destroy');

    Route::post('segnalazioni/{segnalazione}/adesioni', [AdesioniSegnalazioniController::class, 'store'])
        ->name('segnalazioni.adesioni.store');

    Route::post('segnalazioni/{segnalazione}/unisci', [SegnalazioneController::class, 'unisci'])
        ->name('segnalazioni.unisci');

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

    Route::post('segnalazioni/{segnalazione}/applica-triage', [AiTriageController::class, 'applicaTriage'])
        ->name('segnalazioni.applica-triage');
});

// ── Gestione (admin + gestore) ────────────────────────────────────────────────
Route::middleware(['auth', 'role:admin|gestore'])->prefix('gestione')->name('gestione.')->group(function () {
    Route::get('/', [GestioneController::class, 'index'])->name('dashboard');
    Route::get('/stampa', [GestioneController::class, 'stampaLista'])->name('stampa');
    Route::get('/export-csv', [GestioneController::class, 'exportCsv'])->name('export-csv');
    Route::get('/reports/mensile', [ReportController::class, 'mensileGestore'])->name('reports.mensile');
    Route::get('/reports/mensile/xlsx', [ReportController::class, 'mensileGestoreXlsx'])->name('reports.mensile.xlsx');
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
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::get('/impostazioni', [ImpostazioniController::class, 'index'])->name('impostazioni.index');
    Route::patch('/impostazioni', [ImpostazioniController::class, 'update'])->name('impostazioni.update');

    Route::patch('utenti/{utente}/attivo', [UtentiController::class, 'toggleAttivo'])
        ->name('utenti.toggle-attivo');

    Route::patch('utenti/{utente}/approve', [UtentiController::class, 'approve'])
        ->name('utenti.approve');

    Route::patch('utenti/{utente}/reject', [UtentiController::class, 'reject'])
        ->name('utenti.reject');

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

    Route::resource('sla', SlaController::class)
        ->except(['show'])
        ->parameters(['sla' => 'sla']);

    Route::resource('squadre', SquadreController::class)
        ->except(['show'])
        ->parameters(['squadre' => 'squadra']);
});

// ── Operaio ───────────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:operaio'])->prefix('operaio')->name('operaio.')->group(function () {
    Route::get('/dashboard', [OperaioDashboardController::class, 'index'])->name('dashboard');
    Route::get('/statistiche', [OperaioDashboardController::class, 'statistiche'])->name('statistiche');
    Route::get('/mappa', [OperaioDashboardController::class, 'mappa'])->name('mappa');
    Route::post('/segnalazioni/{segnalazione}/smista', [OperaioDashboardController::class, 'smista'])->name('smista');
});

// ── Segnalatore ───────────────────────────────────────────────────────────────
Route::middleware(['auth', 'role:segnalatore'])->prefix('segnalatore')->name('segnalatore.')->group(function () {
    Route::get('/', [SegnalatoreDashboardController::class, 'index'])->name('dashboard');
});

// ── Imprese (portale impresa) ─────────────────────────────────────────────────
Route::middleware(['auth', 'role:impresa'])->prefix('imprese-portale')->name('imprese.')->group(function () {
    Route::get('/dashboard', [ImpreseDashboardController::class, 'index'])->name('dashboard');
    Route::get('/reports/riepilogo', [ReportController::class, 'riepilogoImpresa'])->name('reports.riepilogo');
    Route::get('/reports/riepilogo/xlsx', [ReportController::class, 'riepilogoImpresaXlsx'])->name('reports.riepilogo.xlsx');
});

// ── Profilo (tutti gli utenti autenticati) ────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/telegram/link', [TelegramAccountController::class, 'store'])->name('profile.telegram.store');
    Route::delete('/profile/telegram/link', [TelegramAccountController::class, 'destroy'])->name('profile.telegram.destroy');
});

// ── Magic links per ditte (non autenticate, protette da firma) ─────────────────
Route::middleware('signed')->group(function () {
    Route::get('/magic/segnalazione/{segnalazione}', [\App\Http\Controllers\MagicLinkController::class, 'show'])
        ->name('magic-link.show');
    Route::post('/magic/segnalazione/{segnalazione}', [\App\Http\Controllers\MagicLinkController::class, 'eseguiAzione'])
        ->name('magic-link.azione');
});

require __DIR__.'/auth.php';
