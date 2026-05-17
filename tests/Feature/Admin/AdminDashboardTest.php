<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_can_be_rendered_and_contains_shortcuts(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $admin = User::factory()->create([
            'amministratore' => true,
            'attivo' => true,
            'approval_status' => 'approved',
        ]);
        $admin->syncRoles(['admin']);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Utenti attivi');
        $response->assertSee('Utenti pending');
        $response->assertSee('Sedi');
        $response->assertSee('SLA');
        $response->assertSee(route('admin.sedi.index'));
        $response->assertSee(route('admin.sla.index'));
    }
}
