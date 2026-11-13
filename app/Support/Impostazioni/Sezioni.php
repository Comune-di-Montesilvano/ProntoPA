<?php

namespace App\Support\Impostazioni;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Mappa delle impostazioni modificabili, per sezione: chiave => tipo.
 * Tipi: testo url email intero bool colore ora decimale segreto
 * segreto_cifrato stato disco orario patrono. Le chiavi interne
 * (Impostazione::CHIAVI_INTERNE) non compaiono mai.
 */
final class Sezioni
{
    public const ASPETTO = [
        'ente_nome'              => 'testo',
        'ente_logo_url'          => 'url',
        'ente_colore_primario'   => 'colore',
        'ente_colore_secondario' => 'colore',
        'ente_sito_url'          => 'url',
        'mail_from_address'      => 'email',
        'mail_from_name'         => 'testo',
        'osm_lat'                => 'decimale',
        'osm_lng'                => 'decimale',
        'osm_zoom'               => 'intero',
        'geocoding_comune'       => 'testo',
    ];

    public const SCADENZE = [
        'orario_lun' => 'orario',
        'orario_mar' => 'orario',
        'orario_mer' => 'orario',
        'orario_gio' => 'orario',
        'orario_ven' => 'orario',
        'orario_sab' => 'orario',
        'orario_dom' => 'orario',
        'patrono'    => 'patrono',
    ];

    public const SEGNALAZIONI = [
        'reopen_days_limit'            => 'intero',
        'adesioni_enabled'             => 'bool',
        'adesioni_soglia_priorita'     => 'intero',
        'dedup_giorni'                 => 'intero',
        'publication_enabled'          => 'bool',
        'publication_auto_state_id'    => 'stato',
        'etichetta_ufficio_segnalante' => 'testo',
    ];

    public const AVVISI = [
        'digest_enabled'                           => 'bool',
        'digest_ora'                               => 'ora',
        'digest_skip_weekend'                      => 'bool',
        'telegram_notifica_operatore_assegnazione' => 'bool',
        'telegram_notifica_sla_warning'            => 'bool',
        'telegram_notifica_nuova_nota'             => 'bool',
    ];

    public const SQUADRE = [
        'squadre_enabled' => 'bool',
    ];

    public const INTEGRAZIONI_SPID = [
        'oidc_issuer'        => 'url',
        'oidc_client_id'     => 'testo',
        'oidc_client_secret' => 'segreto_cifrato',
    ];

    public const INTEGRAZIONI_TELEGRAM = [
        'telegram_bot_token'      => 'segreto',
        'telegram_bot_username'   => 'testo',
        'telegram_webhook_secret' => 'segreto',
    ];

    public const INTEGRAZIONI_WEBHOOK = [
        'webhook_cittadini_url'    => 'url',
        'webhook_cittadini_secret' => 'segreto',
    ];

    public const INTEGRAZIONI_AI = [
        'ai_enabled'           => 'bool',
        'ai_ollama_url'        => 'url',
        'ai_modello'           => 'testo',
        'ai_embedding_modello' => 'testo',
    ];

    public const INTEGRAZIONI_ANTIVIRUS = [
        'antivirus_enabled' => 'bool',
    ];

    public const INTEGRAZIONI_ALLEGATI = [
        'allegati_max_size_mb'     => 'intero',
        'allegati_max_per_request' => 'intero',
        'allegati_mime_consentiti' => 'testo',
        'allegati_storage_disk'    => 'disco',
    ];

    public const INTEGRAZIONI_MIUR = [
        'miur_anagrafe_url'   => 'url',
        'miur_comune_default' => 'testo',
    ];

    public const INTEGRAZIONI_DELEGHE = [
        'deleghe_max_pendenti'             => 'intero',
        'deleghe_giorni_stop_rifiuto'      => 'intero',
        'deleghe_giorni_scadenza_richiesta' => 'intero',
        'deleghe_email_giorno_istituto'    => 'intero',
        'deleghe_mesi_validita'            => 'intero',
        'deleghe_giorni_avviso_delegato'   => 'intero',
    ];

    /** @return array<string, array<string, string>> nome sezione => mappa chiave => tipo */
    public static function tutte(): array
    {
        return [
            'ASPETTO'               => self::ASPETTO,
            'SCADENZE'              => self::SCADENZE,
            'SEGNALAZIONI'          => self::SEGNALAZIONI,
            'AVVISI'                => self::AVVISI,
            'SQUADRE'               => self::SQUADRE,
            'INTEGRAZIONI_SPID'     => self::INTEGRAZIONI_SPID,
            'INTEGRAZIONI_TELEGRAM' => self::INTEGRAZIONI_TELEGRAM,
            'INTEGRAZIONI_WEBHOOK'  => self::INTEGRAZIONI_WEBHOOK,
            'INTEGRAZIONI_AI'       => self::INTEGRAZIONI_AI,
            'INTEGRAZIONI_ANTIVIRUS' => self::INTEGRAZIONI_ANTIVIRUS,
            'INTEGRAZIONI_ALLEGATI' => self::INTEGRAZIONI_ALLEGATI,
            'INTEGRAZIONI_MIUR'     => self::INTEGRAZIONI_MIUR,
            'INTEGRAZIONI_DELEGHE'  => self::INTEGRAZIONI_DELEGHE,
        ];
    }

    /** @return array<string, string> */
    public static function di(string $sezione): array
    {
        return self::tutte()[$sezione] ?? throw new NotFoundHttpException("Sezione impostazioni sconosciuta: {$sezione}");
    }
}
