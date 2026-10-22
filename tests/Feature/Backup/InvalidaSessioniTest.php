<?php

namespace Tests\Feature\Backup;

use App\Models\Impostazione;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvalidaSessioniTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $u = User::factory()->create(['attivo' => true, 'approval_status' => 'approved']);
        $u->syncRoles(['admin']);

        return $u;
    }

    public function test_sessione_aperta_prima_del_ripristino_fa_logout(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('admin.dashboard'))->assertOk();

        Impostazione::set('ripristino_id', (string) Str::uuid());

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_sessione_con_id_corrente_resta_valida(): void
    {
        Impostazione::set('ripristino_id', $id = (string) Str::uuid());
        $this->actingAs($this->admin())->withSession(['ripristino_id' => $id]);

        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertAuthenticated();
    }

    public function test_senza_ripristino_nessun_logout(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertAuthenticated();
    }

    public function test_login_salva_id_corrente_in_sessione(): void
    {
        Impostazione::set('ripristino_id', $id = (string) Str::uuid());
        event(new \Illuminate\Auth\Events\Login('web', $this->admin(), false));

        $this->assertSame($id, session('ripristino_id'));
    }

    public function test_senza_ripristino_nessuna_query_per_richiesta(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('admin.dashboard'))->assertOk();

        $letture = 0;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$letture) {
            if (str_contains($q->sql, 'impostazioni') && in_array('ripristino_id', $q->bindings, true)) {
                $letture++;
            }
        });
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.dashboard'))->assertOk();

        $this->assertSame(0, $letture);
        $this->assertAuthenticated();

        // Il valore in cache segue Impostazione::set: il ripristino continua a chiudere le sessioni.
        Impostazione::set('ripristino_id', (string) Str::uuid());
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_ripristino_id_non_compare_ne_si_modifica_dalle_impostazioni(): void
    {
        Impostazione::set('ripristino_id', $id = (string) Str::uuid());
        $this->actingAs($this->admin())->withSession(['ripristino_id' => $id]);
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);

        $this->get(route('admin.impostazioni.index'))->assertOk()->assertDontSee($id)->assertDontSee('ripristino_id');
        $this->patch(route('admin.impostazioni.update'), ['impostazioni' => ['ripristino_id' => 'manomesso', 'ente_nome' => 'X']]);

        $this->assertSame($id, Impostazione::query()->find('ripristino_id')?->valore);
        $this->assertSame('X', Impostazione::get('ente_nome'));
    }
}
