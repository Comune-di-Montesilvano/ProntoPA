<?php

use App\Http\Controllers\Api\SegnalazioneApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — ProntoPA
|--------------------------------------------------------------------------
|
| Endpoint REST per integrazione con il sito web del Comune (portale cittadini).
| Autenticazione: Laravel Sanctum (Bearer token).
|
| POST /api/segnalazioni              → crea segnalazione dal portale cittadini
| GET  /api/segnalazioni/{id}/stato   → legge stato corrente
|
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/segnalazioni', [SegnalazioneApiController::class, 'store'])
        ->name('api.segnalazioni.store');

    Route::get('/segnalazioni/{id}/stato', [SegnalazioneApiController::class, 'stato'])
        ->name('api.segnalazioni.stato');
});
