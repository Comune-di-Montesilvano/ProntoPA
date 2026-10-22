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
}
