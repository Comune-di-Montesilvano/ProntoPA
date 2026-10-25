<?php

use App\Http\Controllers\Admin\AnagrafeMiurController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\DelegheController;
use App\Http\Controllers\Deleghe\DecisioneDelegaController;
use App\Http\Controllers\Deleghe\RinnovoDelegheController;
use App\Http\Controllers\DelegheScuolaController;
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
use App\Http\Controllers\FascicoloPdfController;
use App\Http\Controllers\SegnalazioneController;
use App\Http\Controllers\SegnalatoreDashboardController;
use App\Http\Controllers\SpidAttesaController;
use App\Http\Controllers\StatisticheController;
use App\Http\Controllers\TelegramAccountController;
use Illuminate\Support\Facades\Route;

// Home
Route::get('/', [PublicHomeController::class, 'index'])->name('home');
Route::get('/brand/logo-ente', \App\Http\Controllers\BrandLogoController::class)->name('brand.logo-ente');

// Dashboard — dispatcher per ruolo
Route::get('/dashboard', [RoleDashboardController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

// Scuole (SPID) senza delega: confinate qui da LimitaAccessoSpid
Route::get('/scuola/attesa', SpidAttesaController::class)
    ->middleware('auth')
    ->name('spid.attesa');

// Deleghe dell'utente scuola (SPID): richiesta, elenco, rinuncia
Route::middleware('auth')->prefix('scuola/deleghe')->name('scuola.deleghe.')->group(function () {
    Route::get('/', [DelegheScuolaController::class, 'index'])->name('index');
    Route::get('/richiedi/{istituto}', [DelegheScuolaController::class, 'create'])->name('create');
    Route::post('/richiedi/{istituto}', [DelegheScuolaController::class, 'store'])
        ->middleware('throttle:10,1')->name('store');
    Route::post('/{delega}/rinuncia', [DelegheScuolaController::class, 'rinuncia'])->name('rinuncia');
});

// Deleghe — pagine della segreteria scolastica: nessun login, link firmato con
// token monouso. La GET non cambia nulla, decide solo il POST.
Route::middleware('throttle:20,1')->group(function () {
    Route::get('deleghe/decidi/{token}', [DecisioneDelegaController::class, 'show'])->name('deleghe.decidi');
    Route::post('deleghe/decidi/{token}', [DecisioneDelegaController::class, 'store'])->name('deleghe.decidi.store');
    Route::get('deleghe/rinnovo/{token}', [RinnovoDelegheController::class, 'show'])->name('deleghe.rinnovo');
    Route::post('deleghe/rinnovo/{token}', [RinnovoDelegheController::class, 'store'])->name('deleghe.rinnovo.store');
});

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
    Route::post('segnalazioni/{segnalazione}/sollecita', [SegnalazioneController::class, 'sollecita'])
        ->name('segnalazioni.sollecita');
    Route::post('segnalazioni/{segnalazione}/fine-lavori', [SegnalazioneController::class, 'fineLavori'])
        ->name('segnalazioni.fine-lavori');

    Route::post('segnalazioni/{segnalazione}/evidenza', [SegnalazioneController::class, 'toggleEvidenza'])
        ->name('segnalazioni.evidenza');

    Route::get('segnalazioni/{segnalazione}/stampa', [SegnalazioneController::class, 'stampa'])
        ->name('segnalazioni.stampa');

    Route::get('segnalazioni/{segnalazione}/fascicolo-pdf', [FascicoloPdfController::class, 'fascicolo'])
        ->name('segnalazioni.fascicolo-pdf');

    Route::patch('segnalazioni/{segnalazione}/priorita', [SegnalazioneController::class, 'aggiornaPriorita'])
        ->name('segnalazioni.priorita');

    Route::patch('segnalazioni/{segnalazione}/toggle-riservata', [SegnalazioneController::class, 'toggleRiservata'])
        ->name('segnalazioni.toggle-riservata');

    Route::post('segnalazioni/{segnalazione}/applica-triage', [AiTriageController::class, 'applicaTriage'])
        ->name('segnalazioni.applica-triage');
});

// ── Gestione (admin + gestore) ────────────────────────────────────────────────
Route::middleware(['auth', 'role:admin|gestore'])->prefix('gestione')->name('gestione.')->group(function () {
    Route::get('/', [GestioneController::class, 'index'])->name('dashboard');
    Route::get('/stampa', [GestioneController::class, 'stampaLista'])->name('stampa');
    Route::get('/stampa-schede', [GestioneController::class, 'stampaSchede'])->name('stampa-schede');
    Route::get('/export-csv', [GestioneController::class, 'exportCsv'])->name('export-csv');
    Route::get('/reports/mensile', [ReportController::class, 'mensileGestore'])->name('reports.mensile');
    Route::get('/reports/mensile/xlsx', [ReportController::class, 'mensileGestoreXlsx'])->name('reports.mensile.xlsx');
});

// ── Imprese: persone delegate (SPID/CIE) e disattivazione (admin + gestore) ──
Route::middleware(['auth', 'role:admin|gestore'])->prefix('imprese/{impresa}')->name('imprese.')->group(function () {
    Route::post('deleghe', [\App\Http\Controllers\DelegheImpresaController::class, 'store'])->name('deleghe.store');
    Route::delete('deleghe/{delega}', [\App\Http\Controllers\DelegheImpresaController::class, 'destroy'])->name('deleghe.destroy');
    Route::post('disattiva', [\App\Http\Controllers\DelegheImpresaController::class, 'disattiva'])->name('disattiva');
    Route::post('riattiva', [\App\Http\Controllers\DelegheImpresaController::class, 'riattiva'])->name('riattiva');
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
    Route::post('/impostazioni/pubblica-esistenti', [ImpostazioniController::class, 'pubblicaEsistenti'])->name('impostazioni.pubblica-esistenti');

    Route::patch('utenti/{utente}/attivo', [UtentiController::class, 'toggleAttivo'])
        ->name('utenti.toggle-attivo');

    Route::resource('utenti', UtentiController::class)
        ->except(['show', 'create', 'store'])
        ->parameters(['utenti' => 'utente']);

    Route::resource('organizzazioni', OrganizzazioniController::class)
        ->except(['show'])
        ->parameters(['organizzazioni' => 'organizzazione']);

    Route::resource('sedi', SediController::class)
        ->except(['show'])
        ->parameters(['sedi' => 'sede']);
    Route::post('sedi/{sede}/unisci', [SediController::class, 'unisci'])->name('sedi.unisci');

    Route::get('backup', [BackupController::class, 'index'])->name('backup.index');
    Route::post('backup', [BackupController::class, 'crea'])->name('backup.crea');
    Route::post('backup/carica', [BackupController::class, 'carica'])->name('backup.carica');
    Route::get('backup/ripristino/{token}', [BackupController::class, 'attesa'])->name('backup.attesa');
    Route::get('backup/{nome}/scarica', [BackupController::class, 'scarica'])->name('backup.scarica');
    Route::delete('backup/{nome}', [BackupController::class, 'elimina'])->name('backup.elimina');
    Route::get('backup/{nome}/ripristina', [BackupController::class, 'anteprima'])->name('backup.anteprima');
    Route::post('backup/{nome}/anteprima', [BackupController::class, 'anteprimaConPassphrase'])->name('backup.anteprima-passphrase');
    Route::post('backup/{nome}/ripristina', [BackupController::class, 'ripristina'])->name('backup.ripristina');

    Route::get('anagrafe-miur', [AnagrafeMiurController::class, 'index'])->name('anagrafe-miur.index');
    Route::post('anagrafe-miur/scarica', [AnagrafeMiurController::class, 'scarica'])->name('anagrafe-miur.scarica');
    Route::get('anagrafe-miur/{codice}', [AnagrafeMiurController::class, 'show'])
        ->where('codice', '[A-Za-z0-9]{10}')->name('anagrafe-miur.show');
    Route::post('anagrafe-miur/{codice}', [AnagrafeMiurController::class, 'salva'])
        ->where('codice', '[A-Za-z0-9]{10}')->name('anagrafe-miur.salva');

    Route::get('deleghe', [DelegheController::class, 'index'])->name('deleghe.index');
    Route::get('deleghe/predelega', [DelegheController::class, 'create'])->name('deleghe.create');
    Route::post('deleghe/predelega', [DelegheController::class, 'store'])->name('deleghe.store');
    Route::post('deleghe/gruppo/{gruppo}/attiva', [DelegheController::class, 'attiva'])->whereUuid('gruppo')->name('deleghe.attiva');
    Route::post('deleghe/gruppo/{gruppo}/reinvia', [DelegheController::class, 'reinvia'])->whereUuid('gruppo')->name('deleghe.reinvia');
    Route::post('deleghe/{delega}/revoca', [DelegheController::class, 'revoca'])->name('deleghe.revoca');
    Route::post('deleghe/sblocca/{utente}', [DelegheController::class, 'sblocca'])->name('deleghe.sblocca');
    Route::post('deleghe/verifica/{utente}', [DelegheController::class, 'reinviaVerifica'])
        ->middleware('throttle:6,1')->name('deleghe.reinvia-verifica');

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

require __DIR__.'/auth.php';
