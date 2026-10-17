#!/bin/sh
# Arranque en Render: aplica los cambios a la base de datos, guarda la configuración en caché
# y levanta el servidor web en el puerto que asigna Render.
set -e
php artisan config:clear
php artisan migrate --force
php artisan optimize
exec frankenphp php-server --root public/ --listen ":${PORT:-8080}"
