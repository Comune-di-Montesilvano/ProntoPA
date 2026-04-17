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
