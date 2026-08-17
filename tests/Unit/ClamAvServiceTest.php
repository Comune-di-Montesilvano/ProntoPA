<?php

namespace Tests\Unit;

use App\Models\Impostazione;
use App\Services\ClamAvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClamAvServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_disattivato_di_default(): void
    {
        $service = new ClamAvService();

        $this->assertFalse($service->isEnabled());
    }

    public function test_attivabile_da_impostazione(): void
    {
        Impostazione::set('antivirus_enabled', true);

        $this->assertTrue((new ClamAvService())->isEnabled());
    }

    public function test_scanpath_torna_null_se_disattivato(): void
    {
        $service = new ClamAvService();

        $this->assertNull($service->scanPath('/tmp/qualsiasi'));
    }

    public function test_scanpath_torna_null_se_clamd_irraggiungibile(): void
    {
        Impostazione::set('antivirus_enabled', true);
        config(['services.clamav.host' => '127.0.0.1', 'services.clamav.port' => 65530, 'services.clamav.timeout' => 1]);

        $tmp = tempnam(sys_get_temp_dir(), 'clamtest');
        file_put_contents($tmp, 'contenuto');

        $this->assertNull((new ClamAvService())->scanPath($tmp));

        unlink($tmp);
    }

    /**
     * Fa parlare il protocollo INSTREAM a un vero socket TCP locale (un fake
     * server minimale, non clamd) per verificare che l'implementazione del
     * client (handshake, chunking, parsing risposta) sia corretta — non
     * fidarsi solo del "non esplode", il protocollo l'ho scritto a mano.
     */
    private function conFakeClamd(string $rispostaClamd, callable $test): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "Impossibile aprire socket di test: $errstr");

        $address = stream_socket_get_name($server, false);
        $port    = (int) substr($address, strrpos($address, ':') + 1);

        Impostazione::set('antivirus_enabled', true);
        config(['services.clamav.host' => '127.0.0.1', 'services.clamav.port' => $port, 'services.clamav.timeout' => 5]);

        $pid = pcntl_fork();
        if ($pid === 0) {
            // Processo figlio: fa da clamd finto per una connessione.
            $conn = stream_socket_accept($server, 5);
            if ($conn) {
                // Consuma handshake + chunk fino al terminatore a lunghezza zero.
                fread($conn, 10); // "zINSTREAM\0"
                while (true) {
                    $lenRaw = fread($conn, 4);
                    if ($lenRaw === false || strlen($lenRaw) < 4) {
                        break;
                    }
                    $len = unpack('N', $lenRaw)[1];
                    if ($len === 0) {
                        break;
                    }
                    fread($conn, $len);
                }
                fwrite($conn, $rispostaClamd . "\0");
                fclose($conn);
            }
            exit(0);
        }

        try {
            $tmp = tempnam(sys_get_temp_dir(), 'clamtest');
            file_put_contents($tmp, str_repeat('a', 20000)); // >1 chunk

            $test($tmp);

            unlink($tmp);
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($server);
        }
    }

    public function test_scanpath_pulito_su_risposta_ok(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl non disponibile in questo ambiente PHP.');
        }

        $this->conFakeClamd('stream: OK', function (string $tmp) {
            $this->assertTrue((new ClamAvService())->scanPath($tmp));
        });
    }

    public function test_scanpath_infetto_su_risposta_found(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl non disponibile in questo ambiente PHP.');
        }

        $this->conFakeClamd('stream: Eicar-Test-Signature FOUND', function (string $tmp) {
            $this->assertFalse((new ClamAvService())->scanPath($tmp));
        });
    }
}
