<?php

namespace Tests\Feature\Backup;

use App\Models\Impostazione;
use App\Models\User;
use App\Services\Backup\SegretiCifrati;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SegretiCifratiTest extends TestCase
{
    use RefreshDatabase;

    public function test_segreti_sopravvivono_al_cambio_app_key(): void
    {
        Impostazione::set('oidc_client_secret', Crypt::encryptString('segreto-oidc'));
        $user = User::factory()->create(['two_factor_secret' => encrypt('ABC')]);

        $segreti = (new SegretiCifrati)->estrai();
        $this->assertContains('segreto-oidc', array_column($segreti, 'valore'));

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        Impostazione::get('oidc_client_secret'); // scalda la cache col cifrato della vecchia chiave
        (new SegretiCifrati)->applica($segreti);

        $this->assertSame('segreto-oidc', Crypt::decryptString((string) Impostazione::get('oidc_client_secret')));
        $this->assertSame('segreto-oidc', Crypt::decryptString((string) Impostazione::find('oidc_client_secret')->valore));
        $this->assertSame('ABC', decrypt($user->fresh()->getRawOriginal('two_factor_secret')));
    }

    public function test_valore_non_decifrabile_saltato_senza_loggarlo(): void
    {
        Impostazione::set('oidc_client_secret', 'testo-non-cifrato-XYZ');
        Log::shouldReceive('warning')->once()->withArgs(
            fn ($msg, $ctx = []) => ! str_contains(json_encode($ctx), 'testo-non-cifrato-XYZ')
        );

        $this->assertSame([], (new SegretiCifrati)->estrai());
    }
}
