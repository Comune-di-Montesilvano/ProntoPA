#!/bin/sh
set -e

# Credenziali lette da getenv() e non interpolate nel codice PHP: una
# password con apici o "$" romperebbe lo script.
echo "==> Waiting for database..."
# Il messaggio PDO non contiene la password. Dopo ~30 s (e poi ogni 30 s) lo
# stampa: altrimenti un DB irraggiungibile o una password errata restano muti.
tentativi=0
while true; do
    errore=$(php -r "try { new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); } catch (Throwable \$e) { fwrite(STDERR, \$e->getMessage()); exit(1); }" 2>&1) && break
    tentativi=$((tentativi + 1))
    if [ "$tentativi" -ge 15 ] && [ $(( (tentativi - 15) % 15 )) -eq 0 ]; then
        echo "==> DB non raggiungibile: $errore"
        case "$errore" in
            *"Access denied"*)
                echo "==> DB_PASSWORD diversa da quella con cui è stato creato il volume mariadb_data (vale solo alla prima creazione)"
                ;;
        esac
    fi
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
