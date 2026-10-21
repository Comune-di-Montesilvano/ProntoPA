#!/bin/sh
set -e

# Credenziali lette da getenv() e non interpolate nel codice PHP: una
# password con apici o "$" romperebbe lo script.
echo "==> Waiting for database..."
until php -r "new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" 2>/dev/null; do
    sleep 2
done
echo "==> Database ready."

echo "==> Running migrations..."
php artisan migrate --force --no-interaction

# A ogni avvio, prima installazione compresa: dati di riferimento, ruoli e
# permessi, impostazioni introdotti dagli aggiornamenti. Idempotente, non
# tocca mai dati già configurati né i ruoli degli utenti. Nessun admin creato
# qui: si entra con un utente AD del gruppo PRONTOPA_ADMIN.
echo "==> Allineamento dati di riferimento, ruoli e impostazioni..."
php artisan db:seed --class="Database\\Seeders\\AllineamentoSeeder" --force --no-interaction

echo "==> Starting php-fpm..."
exec php-fpm
