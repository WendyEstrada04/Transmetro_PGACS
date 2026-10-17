#!/bin/sh
set -e
php artisan config:clear
php artisan migrate --force
php artisan optimize

# Motor de la simulación en segundo plano: calcula la operación las 24 horas, limpia los datos viejos cada hora
# y mantiene despierto el servidor. Se reinicia cada hora (y si se detiene por un error) para liberar memoria.
( while true; do php artisan transmetro:motor --pausa=10 --minutos=60 || sleep 10; done ) &

exec frankenphp php-server --root public/ --listen ":${PORT:-8080}"
