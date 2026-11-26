<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Segnalazioni\AllegatiTemporanei;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le foto caricate a blocchi (AllegatiTemporanei) arrivano come id in `allegati_caricati[]`:
 * qui tornano file in `allegati[]`, insieme a quelli eventualmente inviati col form.
 * Ogni punto di caricamento resta com'era (CaricaAllegati, validazione, antivirus).
 * Dopo la richiesta le foto usate si cancellano: sono state copiate dove servivano.
 */
class RicomponiAllegatiCaricati
{
    public const CAMPO = 'allegati_caricati';

    public function handle(Request $request, Closure $next): Response
    {
        $ids = $request->input(self::CAMPO);
        $utente = $request->user();

        if (! is_array($ids) || $ids === [] || ! $utente instanceof User || $request->isMethodSafe()) {
            return $next($request);
        }

        $ids = array_values(array_unique(array_filter(array_slice($ids, 0, AllegatiTemporanei::MAX_PER_UTENTE), 'is_string')));
        $file = array_values(array_filter(array_map(fn (string $id) => AllegatiTemporanei::file($utente, $id), $ids)));

        if ($file !== []) {
            $presenti = $request->files->get('allegati');
            $request->files->set('allegati', array_merge(is_array($presenti) ? array_values(array_filter($presenti)) : [], $file));
            // Request tiene in cache la conversione dei file: va rifatta con quelli nuovi.
            (fn () => $this->convertedFiles = null)->call($request);
        }

        try {
            return $next($request);
        } finally {
            foreach ($ids as $id) {
                AllegatiTemporanei::elimina($utente, $id);
            }
        }
    }
}
