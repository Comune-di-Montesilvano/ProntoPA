#!/bin/sh
set -e

echo "==> Waiting for database..."
until php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 2
done
echo "==> Database ready."

echo "==> Running migrations..."
php artisan migrate --force --no-interaction

# Seed solo se nessun utente esiste (prima installazione)
USER_COUNT=$(php -r "
\$pdo = new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');
echo \$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
")
if [ "$USER_COUNT" = "0" ]; then
    echo "==> Seeding dati di riferimento..."
    php artisan db:seed --class="Database\\Seeders\\TabelleRiferimentoSeeder" --force --no-interaction
    php artisan db:seed --class="Database\\Seeders\\IstitutiPlessiSeeder" --force --no-interaction
    php artisan db:seed --class="Database\\Seeders\\ImpostazioniSeeder" --force --no-interaction
    php artisan db:seed --class="Database\\Seeders\\RolesAndPermissionsSeeder" --force --no-interaction
    # Nessun admin creato qui: si entra con un utente AD del gruppo PRONTOPA_ADMIN.
fi

echo "==> Starting php-fpm..."
exec php-fpm
