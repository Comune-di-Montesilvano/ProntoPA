<?php

namespace App\Services\Scuole;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Anagrafe scuole statali MIUR (open data dati.istruzione.it) ridotta a un
 * indice compatto su disco: il file originale pesa ~50 MB e decodificarlo a
 * ogni ricerca costerebbe centinaia di MB di RAM.
 */
class AnagrafeMiur
{
    public const GREZZO = 'miur/anagrafe.json';
    public const DOWNLOAD = 'miur/anagrafe.download.json';
    public const INDICE = 'miur/indice.json';
    public const STATO = 'miur/stato.json';

    /** @var array<string, array<string, mixed>>|null codice sede → riga */
    private ?array $sedi = null;

    private function disco(): Filesystem
    {
        return Storage::disk('local');
    }

    public function indicizza(string $percorso): int
    {
        $dati = json_decode((string) $this->disco()->get($percorso), true);
        $righe = is_array($dati) ? ($dati['@graph'] ?? null) : null;

        if (! is_array($righe)) {
            throw new FormatoAnagrafeNonValido('formato del file MIUR non riconosciuto (manca @graph)');
        }

        $indice = [];
        foreach ($righe as $riga) {
            $sede = is_array($riga) ? $this->normalizza($riga) : null;
            if ($sede !== null) {
                $indice[$sede['codice']] = $sede;
            }
        }
        unset($dati, $righe);

        if ($indice === []) {
            throw new FormatoAnagrafeNonValido('formato del file MIUR non riconosciuto (nessuna scuola con codice)');
        }

        // Scrittura atomica: l'indice in uso resta valido se qualcosa fallisce.
        $tmp = self::INDICE.'.tmp';
        $this->disco()->put($tmp, json_encode(array_values($indice), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->disco()->delete(self::INDICE);
        $this->disco()->move($tmp, self::INDICE);
        $this->sedi = $indice;

        return count($indice);
    }

    public function haIndice(): bool
    {
        return $this->disco()->exists(self::INDICE);
    }

    /** @return array<string, mixed>|null */
    public function sede(string $codice): ?array
    {
        return $this->sedi()[strtoupper(trim($codice))] ?? null;
    }

    /** @return array{codice: string, nome: string, email: string, sedi: list<array<string, mixed>>}|null */
    public function istituto(string $codice): ?array
    {
        $codice = strtoupper(trim($codice));
        $sedi = array_values(array_filter($this->sedi(), fn ($s) => $s['codice_istituto'] === $codice));

        if ($sedi === []) {
            return null;
        }

        usort($sedi, fn ($a, $b) => [! $a['sede_amministrativa'], $a['nome']] <=> [! $b['sede_amministrativa'], $b['nome']]);

        return [
            'codice' => $codice,
            'nome' => $sedi[0]['nome_istituto'],
            'email' => $this->emailIstituto($codice, $sedi),
            'sedi' => $sedi,
        ];
    }

    /** @return list<array{codice: string, nome: string, email: string, sedi: list<array<string, mixed>>}> */
    public function cerca(string $q, ?string $comune, int $limite = 50): array
    {
        $q = mb_strtoupper(trim($q));
        $comune = mb_strtoupper(trim((string) $comune));
        $codici = [];

        foreach ($this->sedi() as $sede) {
            if ($comune !== '' && $sede['comune'] !== $comune) {
                continue;
            }
            if ($q !== '' && ! str_contains(mb_strtoupper(implode(' ', [$sede['codice'], $sede['nome'], $sede['codice_istituto'], $sede['nome_istituto']])), $q)) {
                continue;
            }
            $codici[$sede['codice_istituto']] = true;
            if (count($codici) >= $limite) {
                break;
            }
        }

        $risultati = array_values(array_filter(array_map(fn ($c) => $this->istituto($c), array_keys($codici))));
        usort($risultati, fn ($a, $b) => $a['nome'] <=> $b['nome']);

        return $risultati;
    }

    /** @return array{stato: string, messaggio: ?string, url: ?string, scaricato_at: ?string, sedi: ?int} */
    public function stato(): array
    {
        $salvato = $this->disco()->exists(self::STATO)
            ? (array) json_decode((string) $this->disco()->get(self::STATO), true)
            : [];

        return array_merge(['stato' => 'vuoto', 'messaggio' => null, 'url' => null, 'scaricato_at' => null, 'sedi' => null], $salvato);
    }

    /** @param array<string, mixed> $stato */
    public function salvaStato(array $stato): void
    {
        $this->disco()->put(self::STATO, json_encode(array_merge($this->stato(), $stato), JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, array<string, mixed>> */
    private function sedi(): array
    {
        if ($this->sedi === null) {
            $righe = $this->haIndice() ? (array) json_decode((string) $this->disco()->get(self::INDICE), true) : [];
            $this->sedi = array_column($righe, null, 'codice');
        }

        return $this->sedi;
    }

    /** @param list<array<string, mixed>> $sedi */
    private function emailIstituto(string $codice, array $sedi): string
    {
        foreach ($sedi as $sede) {
            if ($sede['codice'] === $codice && $sede['email'] !== '') {
                return $sede['email'];
            }
        }

        // Omnicomprensivi/convitti senza riga sede direttivo: le sedi
        // riportano l'email dell'istituto.
        $email = array_count_values(array_filter(array_column($sedi, 'email')));
        arsort($email);

        return (string) array_key_first($email);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>|null
     */
    private function normalizza(array $r): ?array
    {
        $codice = strtoupper($this->testo($r['miur:CODICESCUOLA'] ?? ''));
        $codiceIstituto = strtoupper($this->testo($r['miur:CODICEISTITUTORIFERIMENTO'] ?? ''));
        $nome = $this->testo($r['miur:DENOMINAZIONESCUOLA'] ?? '');

        if ($codice === '' || $codiceIstituto === '' || $nome === '') {
            return null;
        }

        $grado = mb_strtoupper($this->testo($r['miur:DESCRIZIONETIPOLOGIAGRADOISTRUZIONESCUOLA'] ?? ''));
        $email = $this->testo($r['miur:INDIRIZZOEMAILSCUOLA'] ?? '');
        $cap = $r['miur:CAPSCUOLA'] ?? '';

        return [
            'codice' => $codice,
            'nome' => $nome,
            'codice_istituto' => $codiceIstituto,
            'nome_istituto' => $this->testo($r['miur:DENOMINAZIONEISTITUTORIFERIMENTO'] ?? '') ?: $nome,
            'grado' => $grado,
            'comune' => mb_strtoupper($this->testo($r['miur:DESCRIZIONECOMUNE'] ?? '')),
            'provincia' => mb_strtoupper($this->testo($r['miur:PROVINCIA'] ?? '')),
            'indirizzo' => $this->testo($r['miur:INDIRIZZOSCUOLA'] ?? ''),
            'cap' => is_numeric($cap) ? str_pad((string) (int) $cap, 5, '0', STR_PAD_LEFT) : $this->testo($cap),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            // Nei comprensivi/superiori la riga con codice = codice istituto è
            // la sede amministrativa; nella D.D. è una primaria vera.
            'sede_amministrativa' => $codice === $codiceIstituto && str_starts_with($grado, 'ISTITUTO'),
        ];
    }

    private function testo(mixed $valore): string
    {
        return is_scalar($valore) ? str_replace('\\', '', trim((string) $valore)) : '';
    }
}
