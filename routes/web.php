<?php

use App\Http\Controllers\Admin\AnagrafeMiurController;
use App\Http\Controllers\Admin\AspettoController;
use App\Http\Controllers\Admin\TipologieController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\IntegrazioniController;
use App\Http\Controllers\Admin\DelegheController;
use App\Http\Controllers\Deleghe\DecisioneDelegaController;
use App\Http\Controllers\Deleghe\RinnovoDelegheController;
use App\Http\Controllers\DelegheScuolaController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\EntiController;
use App\Http\Controllers\Admin\CategorieEntiController;
use App\Http\Controllers\Admin\SediController;
use App\Http\Controllers\Admin\Regole\AvvisiController;
use App\Http\Controllers\Admin\Regole\ScadenzeController;
use App\Http\Controllers\Admin\Regole\SegnalazioniController as RegoleSegnalazioniController;
use App\Http\Controllers\Admin\SlaController;
use App\Http\Controllers\Organizzazione\SquadreController;
use App\Http\Controllers\Organizzazione\TipologieGestoriController;
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

    Route::post('segnalazioni/{segnalazione}/accorpa', [SegnalazioneController::class, 'accorpa'])
        ->name('segnalazioni.accorpa');
    Route::get('segnalazioni/{segnalazione}/candidati-accorpa', [SegnalazioneController::class, 'candidatiAccorpa'])
        ->name('segnalazioni.candidati-accorpa');
    Route::get('segnalazioni/{segnalazione}/candidati-collega', [SegnalazioneController::class, 'candidatiCollega'])
        ->name('segnalazioni.candidati-collega');

    Route::post('segnalazioni/{segnalazione}/azione', [SegnalazioneController::class, 'eseguiAzione'])
        ->name('segnalazioni.azione');

    Route::post('segnalazioni/{segnalazione}/nota', [SegnalazioneController::class, 'aggiungiNota'])
        ->name('segnalazioni.nota');
    Route::post('segnalazioni/{segnalazione}/sollecita', [SegnalazioneController::class, 'sollecita'])
        ->name('segnalazioni.sollecita');
    Route::post('segnalazioni/{segnalazione}/sollecito-segnalante', [SegnalazioneController::class, 'sollecitoSegnalante'])
        ->name('segnalazioni.sollecito-segnalante');
    Route::post('segnalazioni/{segnalazione}/fine-lavori', [SegnalazioneController::class, 'fineLavori'])
        ->name('segnalazioni.fine-lavori');
    Route::post('segnalazioni/{segnalazione}/rimanda', [SegnalazioneController::class, 'rimanda'])
        ->name('segnalazioni.rimanda');

    Route::post('segnalazioni/{segnalazione}/evidenza', [SegnalazioneController::class, 'toggleEvidenza'])
        ->name('segnalazioni.evidenza');

    Route::get('segnalazioni/{segnalazione}/stampa', [SegnalazioneController::class, 'stampa'])
        ->name('segnalazioni.stampa');

    Route::get('segnalazioni/{segnalazione}/fascicolo-pdf', [FascicoloPdfController::class, 'fascicolo'])
        ->name('segnalazioni.fascicolo-pdf');

    Route::patch('segnalazioni/{segnalazione}/priorita', [SegnalazioneController::class, 'aggiornaPriorita'])
        ->name('segnalazioni.priorita');
    Route::patch('segnalazioni/{segnalazione}/scadenza', [SegnalazioneController::class, 'aggiornaScadenza'])
        ->name('segnalazioni.scadenza');
    Route::patch('segnalazioni/{segnalazione}/scadenza-automatica', [SegnalazioneController::class, 'scadenzaAutomatica'])
        ->name('segnalazioni.scadenza-automatica');
    Route::patch('segnalazioni/{segnalazione}/titolo', [SegnalazioneController::class, 'aggiornaTitolo'])
        ->name('segnalazioni.titolo');

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
    Route::post('/azioni-multiple', \App\Http\Controllers\AzioniMultipleController::class)->name('azioni-multiple');
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

    // Vecchia pagina unica delle impostazioni (segnalibri, email): ora Aspetto.
    Route::get('impostazioni', fn () => redirect()->route('admin.aspetto', [], 301));

    Route::get('utenti/dominio', [UtentiController::class, 'dominio'])->name('utenti.dominio');
    Route::get('utenti/scuole', [UtentiController::class, 'scuole'])->name('utenti.scuole');
    Route::get('utenti/ditte', [UtentiController::class, 'ditte'])->name('utenti.ditte');
    Route::patch('utenti/{utente}/attivo', [UtentiController::class, 'toggleAttivo'])
        ->name('utenti.toggle-attivo');
    Route::post('utenti/sincronizza', [UtentiController::class, 'sincronizza'])
        ->name('utenti.sincronizza');

    Route::resource('utenti', UtentiController::class)
        ->except(['show', 'create', 'store'])
        ->parameters(['utenti' => 'utente']);

    // Tipologie (1.0.0): un solo elenco, le gestisce l'admin.
    Route::get('tipologie', [TipologieController::class, 'index'])->name('tipologie.index');
    Route::post('tipologie', [TipologieController::class, 'store'])->name('tipologie.store');
    Route::patch('tipologie/{tipologia}', [TipologieController::class, 'update'])->name('tipologie.update');
    Route::post('tipologie/{tipologia}/unisci', [TipologieController::class, 'unisci'])->name('tipologie.unisci');

    // Enti (ex Organizzazioni) e sedi in una sezione sola.
    Route::get('enti', [EntiController::class, 'index'])->name('enti.index');
    Route::get('enti/nuovo', [EntiController::class, 'create'])->name('enti.create');
    Route::post('enti', [EntiController::class, 'store'])->name('enti.store');
    Route::get('enti/{ente}', [EntiController::class, 'show'])->name('enti.show');
    Route::patch('enti/{ente}', [EntiController::class, 'update'])->name('enti.update');
    Route::delete('enti/{ente}', [EntiController::class, 'destroy'])->name('enti.destroy');
    Route::post('enti/{ente}/sedi', [EntiController::class, 'storeSede'])->name('enti.sedi.store');

    Route::post('categorie-enti', [CategorieEntiController::class, 'store'])->name('categorie-enti.store');
    Route::patch('categorie-enti/{categoria}', [CategorieEntiController::class, 'update'])->name('categorie-enti.update');
    Route::delete('categorie-enti/{categoria}', [CategorieEntiController::class, 'destroy'])->name('categorie-enti.destroy');

    // Vecchi URL (segnalibri): mai 404.
    Route::redirect('organizzazioni', '/admin/enti', 301);
    Route::redirect('organizzazioni/create', '/admin/enti', 301);
    Route::get('organizzazioni/{id}/edit', fn (int $id) => redirect()->route('admin.enti.show', $id, 301))->whereNumber('id');
    Route::get('sedi', fn (\Illuminate\Http\Request $request) => $request->filled('id_istituto')
        ? redirect()->route('admin.enti.show', (int) $request->query('id_istituto'), 301)
        : redirect()->route('admin.enti.index', [], 301));
    Route::redirect('sedi/create', '/admin/enti', 301);

    Route::post('sedi/geocodifica-mancanti', [SediController::class, 'geocodificaMancanti'])->name('sedi.geocodifica-mancanti');
    Route::post('sedi/{sede}/geocodifica', [SediController::class, 'geocodifica'])
        ->middleware('throttle:30,1')->name('sedi.geocodifica');
    Route::resource('sedi', SediController::class)
        ->only(['edit', 'update', 'destroy'])
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

    // Pagine rimosse: i vecchi URL (e sotto-percorsi) portano alla dashboard admin.
    Route::redirect('profili', '/admin', 301);
    Route::redirect('provenienze', '/admin', 301);
    Route::get('profili/{any}', fn () => redirect()->route('admin.dashboard', [], 301))->where('any', '.*');
    Route::get('provenienze/{any}', fn () => redirect()->route('admin.dashboard', [], 301))->where('any', '.*');

    Route::get('aspetto', [AspettoController::class, 'index'])->name('aspetto');
    Route::patch('aspetto', [AspettoController::class, 'update'])->name('aspetto.update');
    Route::get('integrazioni', [IntegrazioniController::class, 'index'])->name('integrazioni');
    Route::patch('integrazioni/{sezione}', [IntegrazioniController::class, 'update'])
        ->whereIn('sezione', array_keys(IntegrazioniController::SEZIONI))->name('integrazioni.update');
    Route::post('integrazioni/{sezione}/prova', [IntegrazioniController::class, 'prova'])
        ->whereIn('sezione', IntegrazioniController::CON_PROVA)->name('integrazioni.prova');

    Route::prefix('regole')->name('regole.')->group(function () {
        Route::get('scadenze', [ScadenzeController::class, 'index'])->name('scadenze');
        Route::patch('scadenze/orario', [ScadenzeController::class, 'orario'])->name('orario');
        Route::get('segnalazioni', [RegoleSegnalazioniController::class, 'index'])->name('segnalazioni');
        Route::patch('segnalazioni', [RegoleSegnalazioniController::class, 'update'])->name('segnalazioni.update');
        Route::post('segnalazioni/pubblica-esistenti', [RegoleSegnalazioniController::class, 'pubblicaEsistenti'])->name('pubblica-esistenti');
        Route::get('avvisi', [AvvisiController::class, 'index'])->name('avvisi');
        Route::patch('avvisi', [AvvisiController::class, 'update'])->name('avvisi.update');
    });

    Route::resource('sla', SlaController::class)
        ->except(['show'])
        ->parameters(['sla' => 'sla']);
});

// ── Organizzazione (admin + supervisori): squadre e tipologie dei gestori ─────
Route::middleware(['auth', 'can:gestisci-organizzazione'])->prefix('organizzazione')->name('organizzazione.')->group(function () {
    Route::patch('squadre/attiva', [SquadreController::class, 'attiva'])
        ->middleware('role:admin')->name('squadre.attiva');
    Route::resource('squadre', SquadreController::class)
        ->except(['show'])
        ->parameters(['squadre' => 'squadra']);

    Route::get('tipologie-gestori', [TipologieGestoriController::class, 'index'])->name('tipologie-gestori.index');
    Route::get('tipologie-gestori/{utente}', [TipologieGestoriController::class, 'edit'])->name('tipologie-gestori.edit');
    Route::put('tipologie-gestori/{utente}', [TipologieGestoriController::class, 'update'])->name('tipologie-gestori.update');
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
