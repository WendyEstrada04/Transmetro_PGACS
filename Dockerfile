# Sistema de Control Transmetro — imagen para publicar en Render (servicio web con Docker).
# 1) Compila los estilos y el JavaScript con Vite.
FROM node:22-bookworm-slim AS estilos
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# 2) Aplicación Laravel servida con FrankenPHP (PHP 8.4).
FROM dunglas/frankenphp:1-php8.4-bookworm
RUN install-php-extensions pdo_pgsql pgsql intl zip opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/transmetro.ini

WORKDIR /app
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    COMPOSER_ALLOW_SUPERUSER=1

# Dependencias de PHP (sin las de desarrollo ni pruebas)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
COPY --from=estilos /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && sed -i 's/\r$//' docker/iniciar.sh \
    && chmod +x docker/iniciar.sh

EXPOSE 8080
CMD ["sh", "docker/iniciar.sh"]
