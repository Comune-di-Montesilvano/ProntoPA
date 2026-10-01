<?php

namespace Tests\Unit\Oidc;

use App\Services\Oidc\ClaimsSpid;
use PHPUnit\Framework\TestCase;

class ClaimsSpidTest extends TestCase
{
    public function test_fiscal_number_con_prefisso_tinit(): void
    {
        $id = ClaimsSpid::estrai(['sub' => 's1', 'fiscal_number' => 'TINIT-rssmra80a01g482x', 'given_name' => 'Mario', 'family_name' => 'Rossi', 'email' => 'M.Rossi@Example.it']);

        $this->assertSame('RSSMRA80A01G482X', $id->codiceFiscale);
        $this->assertSame('Mario Rossi', $id->nomeCompleto());
        $this->assertSame('m.rossi@example.it', $id->email);
        $this->assertSame('s1', $id->subject);
    }

    public function test_varianti_uri_eidas_e_spid(): void
    {
        $this->assertSame('RSSMRA80A01G482X', ClaimsSpid::estrai(['sub' => 's', 'https://attributes.eid.gov.it/fiscal_number' => 'TINIT-RSSMRA80A01G482X'])->codiceFiscale);
        $this->assertSame('RSSMRA80A01G482X', ClaimsSpid::estrai(['sub' => 's', 'https://attributes.spid.gov.it/fiscalNumber' => 'RSSMRA80A01G482X'])->codiceFiscale);
        $this->assertSame('RSSMRA80A01G482X', ClaimsSpid::estrai(['sub' => 's', 'codice_fiscale' => 'RSSMRA80A01G482X'])->codiceFiscale);
    }

    public function test_nome_da_name_se_mancano_given_e_family(): void
    {
        $id = ClaimsSpid::estrai(['sub' => 's', 'fiscal_number' => 'RSSMRA80A01G482X', 'name' => 'Mario Rossi']);

        $this->assertSame('Mario Rossi', $id->nomeCompleto());
        $this->assertNull($id->email);
    }

    public function test_senza_codice_fiscale_o_formato_errato_restituisce_null(): void
    {
        $this->assertNull(ClaimsSpid::estrai(['sub' => 's', 'given_name' => 'Mario']));
        $this->assertNull(ClaimsSpid::estrai(['sub' => 's', 'fiscal_number' => 'TINIT-123']));
        $this->assertNull(ClaimsSpid::estrai(['fiscal_number' => 'RSSMRA80A01G482X']));
    }

    public function test_email_non_valida_scartata(): void
    {
        $this->assertNull(ClaimsSpid::estrai(['sub' => 's', 'fiscal_number' => 'RSSMRA80A01G482X', 'email' => 'non-una-email'])->email);
    }
}
