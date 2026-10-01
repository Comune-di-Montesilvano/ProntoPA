<?php

namespace App\Services\Oidc;

/**
 * Claim eterogenei di pa-sso-proxy (SPID, CIE, eIDAS) → identità. Il proxy
 * inoltra i valori dell'IdP così come arrivano: la normalizzazione (TINIT-,
 * maiuscole) spetta a noi. Stesse chiavi di ComunicaPA/palestre.
 */
final class ClaimsSpid
{
    private const CHIAVI_CF = [
        'fiscal_number',
        'https://attributes.eid.gov.it/fiscal_number',
        'https://attributes.spid.gov.it/fiscalNumber',
        'codice_fiscale',
        'cf',
        'codiceFiscale',
        'fiscalNumber',
        'fiscalCode',
    ];

    public static function normalizzaCodiceFiscale(string $raw): string
    {
        return (string) preg_replace('/^TIN[A-Z]{2}-/', '', strtoupper(trim($raw)));
    }

    /**
     * @param  array<string, mixed>  $claims  id_token + userinfo già uniti
     */
    public static function estrai(array $claims): ?IdentitaSpid
    {
        $subject = self::stringa($claims['sub'] ?? null);
        $cf = self::normalizzaCodiceFiscale(self::primo($claims, self::CHIAVI_CF));

        if ($subject === '' || ! preg_match('/^[A-Z0-9]{16}$/', $cf)) {
            return null;
        }

        $nome = self::primo($claims, ['given_name', 'first_name', 'givenName']);
        $cognome = self::primo($claims, ['family_name', 'last_name', 'familyName', 'surname', 'sn']);

        if ($nome === '' && $cognome === '') {
            $nome = self::stringa($claims['name'] ?? null);
        }

        $email = mb_strtolower(self::stringa($claims['email'] ?? null));

        return new IdentitaSpid(
            codiceFiscale: $cf,
            nome: $nome,
            cognome: $cognome,
            email: filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            subject: $subject,
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  list<string>  $chiavi
     */
    private static function primo(array $claims, array $chiavi): string
    {
        foreach ($chiavi as $chiave) {
            $valore = self::stringa($claims[$chiave] ?? null);
            if ($valore !== '') {
                return $valore;
            }
        }

        return '';
    }

    private static function stringa(mixed $valore): string
    {
        if (is_array($valore)) {
            $valore = $valore[0] ?? null;
        }

        return is_scalar($valore) ? trim((string) $valore) : '';
    }
}
