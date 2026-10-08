<?php

namespace Tests\Feature\Scuole;

use App\Models\Istituto;
use App\Models\Plesso;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FormMiurTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['attivo' => true, 'approval_status' => 'approved']);
        $this->admin->syncRoles(['admin']);
    }

    private function istituto(string $fonte): Istituto
    {
        return Istituto::create([
            'descrizione' => 'IC SILONE', 'codice_meccanografico' => 'PEIC828004', 'email' => 'peic828004@istruzione.it',
            'tipo' => 'Scuola', 'tipo_ente' => 'scuola', 'fonte_dati' => $fonte, 'attivo' => true,
        ]);
    }

    private function payloadIstituto(): array
    {
        return [
            'descrizione' => 'NOME CAMBIATO', 'tipo' => 'Scuola', 'tipo_ente' => 'scuola',
            'codice_meccanografico' => 'XXXX', 'email' => 'altro@example.it',
            'dirigente' => 'Dott.ssa Rossi', 'recapiti' => '085 123', 'attivo' => '1',
        ];
    }

    public function test_istituto_miur_ignora_campi_miur_ma_salva_gli_altri(): void
    {
        $ist = $this->istituto('miur');

        $this->actingAs($this->admin)->patch(route('admin.organizzazioni.update', $ist), $this->payloadIstituto())->assertRedirect();

        $ist->refresh();
        $this->assertSame('IC SILONE', $ist->descrizione);
        $this->assertSame('PEIC828004', $ist->codice_meccanografico);
        $this->assertSame('peic828004@istruzione.it', $ist->email);
        $this->assertSame('Dott.ssa Rossi', $ist->dirigente);
    }

    public function test_istituto_manuale_modificabile(): void
    {
        $ist = $this->istituto('manuale');

        $this->actingAs($this->admin)->patch(route('admin.organizzazioni.update', $ist), $this->payloadIstituto())->assertRedirect();

        $this->assertSame('NOME CAMBIATO', $ist->fresh()->descrizione);
    }

    public function test_sede_miur_ignora_campi_miur(): void
    {
        $ist = $this->istituto('miur');
        $altro = Istituto::create(['descrizione' => 'Altro', 'codice_meccanografico' => '']);
        $sede = Plesso::create(['id_istituto' => $ist->id_istituto, 'nome' => 'MEDIA', 'codice_meccanografico' => 'PEMM828015', 'indirizzo' => 'VIA S.GOTTARDO', 'fonte_dati' => 'miur']);

        $this->actingAs($this->admin)->patch(route('admin.sedi.update', $sede), [
            'id_istituto' => $altro->id_istituto, 'nome' => 'X', 'codice_meccanografico' => 'Y',
            'indirizzo' => 'Z', 'email' => 'z@example.it', 'referente' => 'Bidello Mario', 'recapiti' => '085 1',
        ])->assertRedirect();

        $sede->refresh();
        $this->assertSame($ist->id_istituto, $sede->id_istituto);
        $this->assertSame('MEDIA', $sede->nome);
        $this->assertSame('Bidello Mario', $sede->referente);
    }

    public function test_sede_manuale_accetta_nome_lungo(): void
    {
        $ist = $this->istituto('manuale');
        $sede = Plesso::create(['id_istituto' => $ist->id_istituto, 'nome' => 'Palestra']);

        $this->actingAs($this->admin)->patch(route('admin.sedi.update', $sede), [
            'id_istituto' => $ist->id_istituto, 'nome' => str_repeat('N', 120),
        ])->assertSessionHasNoErrors();

        $this->assertSame(120, strlen($sede->fresh()->nome));
    }

    public function test_form_modifica_miur_mostra_sola_lettura(): void
    {
        $ist = $this->istituto('miur');

        $this->actingAs($this->admin)->get(route('admin.organizzazioni.edit', $ist))
            ->assertOk()
            ->assertSee('Dati da anagrafe MIUR')
            ->assertSee('readonly', false);
    }

    public function test_form_modifica_sede_miur_rende_select_disabilitata(): void
    {
        $ist = $this->istituto('miur');
        $sede = Plesso::create(['id_istituto' => $ist->id_istituto, 'nome' => 'MEDIA', 'codice_meccanografico' => 'PEMM828015', 'fonte_dati' => 'miur']);

        $this->actingAs($this->admin)->get(route('admin.sedi.edit', $sede))
            ->assertOk()
            ->assertSee('Dati da anagrafe MIUR')
            ->assertSee('name="id_istituto" disabled', false);
    }
}
