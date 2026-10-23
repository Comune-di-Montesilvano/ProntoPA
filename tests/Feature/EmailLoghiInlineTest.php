<?php

namespace Tests\Feature;

use App\Models\Impostazione;
use App\Models\User;
use Database\Seeders\ImpostazioniSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailLoghiInlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ImpostazioniSeeder::class);
        config(['mail.default' => 'array']);
        Storage::fake('local');
        File::deleteDirectory(storage_path('framework/cache/brand'));
    }

    private function inviaEmail(): Email
    {
        $user = User::factory()->create(['email' => 'dest@example.it']);
        $user->notify(new class extends Notification {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->subject('Prova')->line('Corpo')->action('Apri', 'https://prontopa.test/x');
            }
        });

        $messaggi = app('mailer')->getSymfonyTransport()->messages();

        return $messaggi->last()->getOriginalMessage();
    }

    private function contaCid(Email $email): int
    {
        return substr_count((string) $email->getHtmlBody(), 'src="cid:');
    }

    private function png(): string
    {
        return UploadedFile::fake()->image('l.png', 120, 40)->getContent();
    }

    public function test_email_contiene_loghi_inline_e_nessuna_immagine_esterna(): void
    {
        Storage::disk('local')->put('brand/logo-ente.png', $this->png());
        Impostazione::set('ente_logo_file', 'brand/logo-ente.png');

        $email = $this->inviaEmail();

        $this->assertStringNotContainsString('src="http', (string) $email->getHtmlBody());
        $this->assertSame(2, $this->contaCid($email));
        $this->assertCount(2, $email->getAttachments());
    }

    public function test_senza_logo_ente_solo_prontopa(): void
    {
        $email = $this->inviaEmail();

        $this->assertSame(1, $this->contaCid($email));
        $this->assertStringNotContainsString('src="http', (string) $email->getHtmlBody());
    }

    public function test_logo_da_url_viene_scaricato_e_incorporato(): void
    {
        Http::fake(['x.test/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        Impostazione::set('ente_logo_url', 'https://x.test/l.png');

        $email = $this->inviaEmail();

        $this->assertSame(2, $this->contaCid($email));
        $this->assertStringNotContainsString('src="http', (string) $email->getHtmlBody());
    }

    public function test_url_irraggiungibile_email_parte_con_solo_prontopa(): void
    {
        Http::fake(fn () => throw new ConnectionException('irraggiungibile'));
        Impostazione::set('ente_logo_url', 'https://x.test/l.png');

        $this->assertSame(1, $this->contaCid($this->inviaEmail()));
    }

    public function test_download_fallito_non_si_riprova_per_un_ora(): void
    {
        Http::fake(['x.test/*' => Http::response('', 500)]);
        Impostazione::set('ente_logo_url', 'https://x.test/l.png');

        $this->assertSame(1, $this->contaCid($this->inviaEmail()));
        $this->assertSame(1, $this->contaCid($this->inviaEmail()));
        Http::assertSentCount(1);
    }

    public function test_url_che_non_e_immagine_viene_ignorato(): void
    {
        Http::fake(['x.test/*' => Http::response('<html>nope</html>', 200, ['Content-Type' => 'text/html'])]);
        Impostazione::set('ente_logo_url', 'https://x.test/l.png');

        $this->assertSame(1, $this->contaCid($this->inviaEmail()));
    }

    public function test_url_relativo_risolto_su_public_senza_http(): void
    {
        Http::fake();
        Impostazione::set('ente_logo_url', '/apple-touch-icon.png');

        $this->assertSame(2, $this->contaCid($this->inviaEmail()));
        Http::assertNothingSent();
    }
}
