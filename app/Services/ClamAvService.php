<?php

namespace App\Services;

use App\Models\Impostazione;
use Illuminate\Support\Facades\Log;

class ClamAvService
{
    /**
     * Esito di uno stream inviato in scansione: null=non riscontrabile
     * (verdetto non ottenuto — clamd giù, timeout, errore protocollo),
     * true=pulito, false=infetto.
     */
    public const int CHUNK_SIZE = 8192;

    public function isEnabled(): bool
    {
        return (bool) Impostazione::get('antivirus_enabled', false);
    }

    /**
     * Scansiona un file locale via protocollo INSTREAM di clamd.
     * Degrada in silenzio (torna null) se il servizio non è abilitato o
     * non è raggiungibile — mai bloccante sul flusso di upload.
     */
    public function scanPath(string $absolutePath): ?bool
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            Log::warning('ClamAvService::scanPath: impossibile aprire il file', ['path' => $absolutePath]);
            return null;
        }

        try {
            return $this->scanStream($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $stream
     */
    private function scanStream($stream): ?bool
    {
        $host    = (string) config('services.clamav.host');
        $port    = (int) config('services.clamav.port');
        $timeout = (int) config('services.clamav.timeout');

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            Log::warning('ClamAvService: connessione a clamd fallita', ['errno' => $errno, 'errstr' => $errstr]);
            return null;
        }

        try {
            stream_set_timeout($socket, $timeout);
            fwrite($socket, "zINSTREAM\0");

            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)) . $chunk);
            }

            // Chunk di dimensione zero = fine stream, come da protocollo.
            fwrite($socket, pack('N', 0));

            $response = '';
            while (! feof($socket)) {
                $response .= fread($socket, self::CHUNK_SIZE);
                if (str_contains($response, "\0") || strlen($response) > 4096) {
                    break;
                }
            }

            $response = trim(str_replace("\0", '', $response));

            if (str_contains($response, 'FOUND')) {
                return false;
            }

            if (str_contains($response, 'OK')) {
                return true;
            }

            Log::warning('ClamAvService: risposta inattesa da clamd', ['response' => $response]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('ClamAvService::scanStream failed', ['error' => $e->getMessage()]);
            return null;
        } finally {
            fclose($socket);
        }
    }
}
