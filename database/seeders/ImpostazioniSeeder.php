<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ImpostazioniSeeder extends Seeder
{
    public function run(): void
    {
        $impostazioni = [
            // Brand
            [
                'chiave'      => 'ente_nome',
                'valore'      => 'ProntoPA',
                'tipo'        => 'text',
                'gruppo'      => 'brand',
                'descrizione' => 'Nome dell\'ente o dell\'istanza (mostrato in header e notifiche)',
            ],
            [
                'chiave'      => 'ente_logo_url',
                'valore'      => null,
                'tipo'        => 'image',
                'gruppo'      => 'brand',
                'descrizione' => 'URL o path del logo (lascia vuoto per usare il logo di default)',
            ],
            [
                'chiave'      => 'ente_colore_primario',
                'valore'      => '#1D4ED8',
                'tipo'        => 'color',
                'gruppo'      => 'brand',
                'descrizione' => 'Colore primario dell\'interfaccia (hex)',
            ],
            [
                'chiave'      => 'ente_colore_secondario',
                'valore'      => '#1E40AF',
                'tipo'        => 'color',
                'gruppo'      => 'brand',
                'descrizione' => 'Colore secondario / accento (hex)',
            ],
            [
                'chiave'      => 'ente_sito_url',
                'valore'      => null,
                'tipo'        => 'url',
                'gruppo'      => 'brand',
                'descrizione' => 'URL del sito web dell\'ente (usato nei link footer)',
            ],

            // Email
            [
                'chiave'      => 'mail_from_address',
                'valore'      => 'noreply@comune.example.it',
                'tipo'        => 'text',
                'gruppo'      => 'email',
                'descrizione' => 'Indirizzo email mittente per le notifiche',
            ],
            [
                'chiave'      => 'mail_from_name',
                'valore'      => 'ProntoPA',
                'tipo'        => 'text',
                'gruppo'      => 'email',
                'descrizione' => 'Nome mittente email (es. "Comune di Montesilvano")',
            ],

            // Mappa OpenStreetMap
            [
                'chiave'      => 'osm_lat',
                'valore'      => '41.9028',
                'tipo'        => 'text',
                'gruppo'      => 'mappa',
                'descrizione' => 'Latitudine del centro mappa di default',
            ],
            [
                'chiave'      => 'osm_lng',
                'valore'      => '12.4964',
                'tipo'        => 'text',
                'gruppo'      => 'mappa',
                'descrizione' => 'Longitudine del centro mappa di default',
            ],
            [
                'chiave'      => 'osm_zoom',
                'valore'      => '13',
                'tipo'        => 'integer',
                'gruppo'      => 'mappa',
                'descrizione' => 'Livello di zoom default (1-19)',
            ],

            // Pubblicazione automatica
            [
                'chiave'      => 'publication_enabled',
                'valore'      => '0',
                'tipo'        => 'boolean',
                'gruppo'      => 'pubblicazione',
                'descrizione' => 'Abilita pubblicazione automatica segnalazioni quando raggiungono uno stato configurato',
            ],
            [
                'chiave'      => 'publication_auto_state_id',
                'valore'      => null,
                'tipo'        => 'integer',
                'gruppo'      => 'pubblicazione',
                'descrizione' => 'ID stato a partire da cui le segnalazioni diventano pubbliche (es. 2=In carico). NULL=disabilitato',
            ],

            // Webhook (integrazione sito Comune)
            [
                'chiave'      => 'webhook_cittadini_url',
                'valore'      => null,
                'tipo'        => 'url',
                'gruppo'      => 'webhook',
                'descrizione' => 'URL endpoint del sito web Comune per notifiche cambio stato',
            ],
            [
                'chiave'      => 'webhook_cittadini_secret',
                'valore'      => null,
                'tipo'        => 'text',
                'gruppo'      => 'webhook',
                'descrizione' => 'Secret HMAC per firmare i payload webhook in uscita',
            ],
            [
                'chiave'      => 'telegram_bot_token',
                'valore'      => null,
                'tipo'        => 'text',
                'gruppo'      => 'telegram',
                'descrizione' => 'Token API del bot Telegram generato da BotFather',
            ],
            [
                'chiave'      => 'telegram_bot_username',
                'valore'      => null,
                'tipo'        => 'text',
                'gruppo'      => 'telegram',
                'descrizione' => 'Username del bot Telegram senza URL completo (es. pronto_pa_bot)',
            ],
            [
                'chiave'      => 'telegram_webhook_secret',
                'valore'      => null,
                'tipo'        => 'text',
                'gruppo'      => 'telegram',
                'descrizione' => 'Secret inviato da Telegram nell\'header del webhook',
            ],

            // Notifiche Telegram — toggle per tipo evento
            [
                'chiave'      => 'telegram_notifica_operatore_assegnazione',
                'valore'      => '1',
                'tipo'        => 'boolean',
                'gruppo'      => 'notifiche_telegram',
                'descrizione' => 'Notifica Telegram quando viene assegnato un operatore',
            ],
            [
                'chiave'      => 'telegram_notifica_stato_critico',
                'valore'      => '1',
                'tipo'        => 'boolean',
                'gruppo'      => 'notifiche_telegram',
                'descrizione' => 'Notifica Telegram per segnalazioni in stato critico/urgente',
            ],
            [
                'chiave'      => 'telegram_notifica_sla_warning',
                'valore'      => '1',
                'tipo'        => 'boolean',
                'gruppo'      => 'notifiche_telegram',
                'descrizione' => 'Notifica Telegram per avvisi SLA in scadenza',
            ],
            [
                'chiave'      => 'telegram_notifica_nuova_nota',
                'valore'      => '0',
                'tipo'        => 'boolean',
                'gruppo'      => 'notifiche_telegram',
                'descrizione' => 'Notifica Telegram quando viene aggiunta una nota visibile',
            ],

            // Allegati segnalazioni
            [
                'chiave'      => 'allegati_max_size_mb',
                'valore'      => '10',
                'tipo'        => 'integer',
                'gruppo'      => 'allegati',
                'descrizione' => 'Dimensione massima singolo allegato in MB',
            ],
            [
                'chiave'      => 'allegati_max_per_request',
                'valore'      => '5',
                'tipo'        => 'integer',
                'gruppo'      => 'allegati',
                'descrizione' => 'Numero massimo di file caricabili per singola richiesta',
            ],
            [
                'chiave'      => 'allegati_mime_consentiti',
                'valore'      => 'image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime',
                'tipo'        => 'text',
                'gruppo'      => 'allegati',
                'descrizione' => 'Elenco MIME consentiti separati da virgola',
            ],
            [
                'chiave'      => 'allegati_storage_disk',
                'valore'      => 'local',
                'tipo'        => 'text',
                'gruppo'      => 'allegati',
                'descrizione' => 'Disk di storage per gli allegati (local oppure s3)',
            ],

            // Workflow
            [
                'chiave'      => 'reopen_days_limit',
                'valore'      => '30',
                'tipo'        => 'integer',
                'gruppo'      => 'workflow',
                'descrizione' => 'Giorni entro cui è consentita la riapertura di una segnalazione chiusa (0 = mai)',
            ],

            // Verifica annuale account
            [
                'chiave'      => 'account_verification_enabled',
                'valore'      => '1',
                'tipo'        => 'boolean',
                'gruppo'      => 'verifica_account',
                'descrizione' => 'Abilita il controllo annuale account e invio richieste di conferma email istituzionale',
            ],
            [
                'chiave'      => 'account_verification_annual_days',
                'valore'      => '365',
                'tipo'        => 'integer',
                'gruppo'      => 'verifica_account',
                'descrizione' => 'Giorni massimi tra una conferma annuale e la successiva',
            ],
            [
                'chiave'      => 'account_verification_token_days',
                'valore'      => '30',
                'tipo'        => 'integer',
                'gruppo'      => 'verifica_account',
                'descrizione' => 'Validita in giorni del link di conferma annuale',
            ],
            [
                'chiave'      => 'account_verification_reminder_days',
                'valore'      => '15',
                'tipo'        => 'integer',
                'gruppo'      => 'verifica_account',
                'descrizione' => 'Invia reminder quando la scadenza e entro N giorni',
            ],
            [
                'chiave'      => 'account_verification_auto_suspend',
                'valore'      => '1',
                'tipo'        => 'boolean',
                'gruppo'      => 'verifica_account',
                'descrizione' => 'Sospende automaticamente gli account che non confermano entro la scadenza',
            ],
        ];

        foreach ($impostazioni as $impostazione) {
            DB::table('impostazioni')->updateOrInsert(
                ['chiave' => $impostazione['chiave']],
                array_merge($impostazione, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
