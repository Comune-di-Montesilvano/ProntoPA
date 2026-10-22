<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CreaBackup;
use App\Jobs\EseguiRipristino;
use App\Services\Backup\Anteprima;
use App\Services\Backup\ArchivioBackup;
use App\Services\Backup\BackupInfo;
use App\Services\Backup\BackupNonLeggibile;
use App\Services\Backup\BackupProtetto;
use App\Services\Backup\BackupService;
use App\Services\Backup\CatalogoBackup;
use App\Services\Backup\RipristinoService;
use App\Services\Backup\StatoRipristino;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupController extends Controller
{
    public function __construct(
        private readonly CatalogoBackup $catalogo,
        private readonly ArchivioBackup $archivio,
        private readonly RipristinoService $ripristino,
    ) {}

    public function index(): View
    {
        return view('admin.backup.index', [
            'backups' => $this->catalogo->elenco(),
            'passphraseMancante' => $this->passphraseLocale() === null,
            'dimensioneBlocco' => $this->dimensioneBlocco(),
        ]);
    }

    public function crea(): RedirectResponse
    {
        CreaBackup::dispatch('manuale');

        return redirect()->route('admin.backup.index')->with('status', 'Backup accodato: comparirà nell\'elenco appena terminato.');
    }

    public function scarica(string $nome): BinaryFileResponse
    {
        $percorso = $this->percorso($nome);
        abort_unless(is_file($percorso), 404);

        return response()->download($percorso, $nome);
    }

    public function elimina(string $nome): RedirectResponse
    {
        $this->percorso($nome);

        try {
            $this->catalogo->elimina($nome);
        } catch (BackupProtetto $e) {
            return redirect()->route('admin.backup.index')->with('errore', $e->getMessage());
        }

        return redirect()->route('admin.backup.index')->with('status', "Backup $nome eliminato.");
    }

    /** Un blocco per richiesta: i file di backup superano i limiti di upload di nginx/PHP. */
    public function carica(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'upload_id' => ['required', 'uuid'],
            'indice' => ['required', 'integer', 'min:0'],
            'totale' => ['required', 'integer', 'min:1', 'max:20000'],
            'blocco' => ['required', 'file'],
            'passphrase' => ['nullable', 'string', 'max:500'],
        ]);
        if ($v->fails()) {
            return $this->errore($v->errors()->first());
        }

        $this->catalogo->pulisciTemporanei();

        $id = (string) $request->input('upload_id');
        $indice = (int) $request->input('indice');
        $totale = (int) $request->input('totale');
        $file = $request->file('blocco');
        $max = $this->dimensioneBlocco();
        $ultimo = $indice === $totale - 1;

        if ($indice >= $totale) {
            return $this->errore('Indice del blocco non valido.');
        }
        if ($indice === 0 && $totale * $max > (int) config('backup.max_upload_mb') * 1048576) {
            return $this->errore('File troppo grande: il limite è '.(int) config('backup.max_upload_mb').' MB.');
        }
        $dimensione = (int) $file->getSize();
        if ($dimensione > $max || $dimensione === 0 || (! $ultimo && $dimensione !== $max)) {
            @unlink($this->catalogo->cartella().'/tmp/upload-'.$id.'.part');

            return $this->errore('Dimensione del blocco non valida.');
        }

        $parte = $this->catalogo->cartella().'/tmp/upload-'.$id.'.part';
        clearstatcache(true, $parte);
        $ricevuto = is_file($parte) ? (int) filesize($parte) : 0;
        // Ogni blocco precedente è esattamente $max byte: l'indice atteso è determinato dalla dimensione.
        if ($ricevuto !== $indice * $max) {
            @unlink($parte);

            return $this->errore('Blocco fuori sequenza: riavvia il caricamento.');
        }

        if (! is_dir(dirname($parte))) {
            mkdir(dirname($parte), 0775, true);
        }
        $in = fopen($file->getRealPath(), 'rb');
        $out = fopen($parte, 'ab');
        if ($in === false || $out === false || stream_copy_to_stream($in, $out) !== $dimensione) {
            @unlink($parte);

            return $this->errore('Scrittura del blocco non riuscita (spazio su disco?).');
        }
        fclose($in);
        fclose($out);

        if (! $ultimo) {
            return response()->json(['ok' => true]);
        }

        try {
            $nome = $this->completaCaricamento($parte, (string) $request->input('passphrase', ''));
        } catch (BackupNonLeggibile|InvalidArgumentException $e) {
            return $this->errore($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->errore('Caricamento non riuscito: '.$e->getMessage());
        } finally {
            @unlink($parte);
        }

        return response()->json(['nome' => $nome]);
    }

    public function anteprima(string $nome): View
    {
        $percorso = $this->percorso($nome);
        abort_unless(is_file($percorso), 404);

        return $this->vistaAnteprima($nome, $percorso, null);
    }

    public function anteprimaConPassphrase(Request $request, string $nome): View
    {
        $percorso = $this->percorso($nome);
        abort_unless(is_file($percorso), 404);
        $dati = $request->validate(['passphrase' => ['required', 'string', 'max:500']]);

        return $this->vistaAnteprima($nome, $percorso, $dati['passphrase']);
    }

    public function ripristina(Request $request, string $nome): RedirectResponse|View
    {
        $percorso = $this->percorso($nome);
        abort_unless(is_file($percorso), 404);
        $v = Validator::make($request->all(), [
            'conferma' => ['required', 'in:RIPRISTINA'],
            'passphrase' => ['nullable', 'string', 'max:500'],
        ], ['conferma.in' => 'Per confermare scrivi esattamente RIPRISTINA, in maiuscolo.']);

        // Errori: si ripresenta l'anteprima (niente redirect, niente old input) così la
        // passphrase digitata non finisce in sessione e l'utente non perde lo stato.
        $form = (string) $request->input('passphrase', '');
        $formOpt = $form !== '' ? $form : null;
        if ($v->fails()) {
            return $this->vistaAnteprima($nome, $percorso, $formOpt)->withErrors($v);
        }

        $passphrase = $formOpt ?? $this->passphraseLocale();
        if ($passphrase === null) {
            return $this->vistaAnteprima($nome, $percorso, null)->withErrors(['conferma' => 'Serve la passphrase del backup.']);
        }

        // Ricontrollo lato server: la pagina di anteprima può essere vecchia.
        try {
            $anteprima = $this->ripristino->anteprima($percorso, $passphrase);
        } catch (BackupNonLeggibile $e) {
            return $this->vistaAnteprima($nome, $percorso, $formOpt)->withErrors(['conferma' => $e->getMessage()]);
        }
        if ($anteprima->bloccante !== null) {
            return $this->vistaAnteprima($nome, $percorso, $formOpt)->withErrors(['conferma' => $anteprima->bloccante]);
        }

        $token = StatoRipristino::nuovoToken();
        StatoRipristino::fase($token, 'in coda', 'Ripristino accodato.');
        EseguiRipristino::dispatch($percorso, $passphrase, $token);

        // Pagina direttamente dalla POST: con un redirect il worker potrebbe mettere l'app in
        // manutenzione prima della GET (503) e il token andrebbe perso.
        return view('admin.backup.attesa', ['token' => $token]);
    }

    public function attesa(string $token): View
    {
        try {
            abort_if(StatoRipristino::leggi($token) === null, 404);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return view('admin.backup.attesa', ['token' => $token]);
    }

    /** Fuori dal gruppo web (vedi bootstrap/app.php): legge solo il file di stato, mai il DB. */
    public function stato(string $token): JsonResponse
    {
        try {
            $stato = StatoRipristino::leggi($token);
        } catch (InvalidArgumentException) {
            abort(404);
        }
        abort_if($stato === null, 404);

        return response()->json($stato);
    }

    private function vistaAnteprima(string $nome, string $percorso, ?string $passphraseForm): View
    {
        $passphrase = $passphraseForm ?? $this->passphraseLocale();
        $anteprima = null;
        $errore = null;
        $chiediPassphrase = false;

        if ($passphrase === null) {
            $chiediPassphrase = true;
        } else {
            try {
                $anteprima = $this->ripristino->anteprima($percorso, $passphrase);
            } catch (BackupNonLeggibile $e) {
                $errore = $e->getMessage();
                $chiediPassphrase = $passphraseForm !== null || str_contains(mb_strtolower($errore), 'passphrase');
            }
        }

        return view('admin.backup.anteprima', [
            'nome' => $nome,
            'anteprima' => $anteprima,
            'errore' => $errore,
            'chiediPassphrase' => $chiediPassphrase,
            'passphrase' => $anteprima !== null ? $passphraseForm : null,
            'totaleRighe' => $anteprima instanceof Anteprima ? $this->totaleRighe($anteprima) : 0,
        ]);
    }

    private function totaleRighe(Anteprima $a): int
    {
        return (int) array_sum(array_map('intval', (array) ($a->manifest['conteggi'] ?? [])));
    }

    /** @return string nome del file caricato */
    private function completaCaricamento(string $parte, #[\SensitiveParameter] string $passphraseForm): string
    {
        $locale = $this->passphraseLocale();
        if ($locale === null) {
            throw new InvalidArgumentException('Imposta BACKUP_PASSPHRASE nella stack prima di caricare un backup: senza, il file non si potrebbe riaprire.');
        }
        $da = $passphraseForm !== '' ? $passphraseForm : $locale;

        $this->archivio->verifica($parte, $da);
        if ($da !== $locale) {
            $this->archivio->ricifra($parte, $da, $locale);
        }
        $manifest = $this->archivio->manifest($parte, $locale);

        $creato = CarbonImmutable::parse((string) ($manifest['creato_il'] ?? 'now'));
        $versione = BackupService::versionePerNomeFile((string) ($manifest['versione'] ?? ''));
        $nome = sprintf('prontopa-%s-%s-caricato.zip', $creato->format('Ymd-His'), $versione);
        $destinazione = $this->catalogo->percorso($nome);
        if (file_exists($destinazione)) {
            throw new InvalidArgumentException("Esiste già un backup caricato con questo nome ($nome).");
        }

        rename($parte, $destinazione);
        $this->catalogo->salvaInfo(new BackupInfo(
            $nome, 'caricato', $creato, $versione, (int) filesize($destinazione), true, null, null,
        ));

        return $nome;
    }

    private function percorso(string $nome): string
    {
        try {
            return $this->catalogo->percorso($nome);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    private function passphraseLocale(): ?string
    {
        $p = config('backup.passphrase');

        return is_string($p) && $p !== '' ? $p : null;
    }

    private function dimensioneBlocco(): int
    {
        return max(1, (int) config('backup.dimensione_blocco'));
    }

    private function errore(string $messaggio): JsonResponse
    {
        return response()->json(['errore' => $messaggio], 422);
    }
}
