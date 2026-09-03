# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — Composer dependencies (production only)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY . .
# The bare composer image has no ext-intl (filament/support requires it); this
# stage only *downloads* dependencies — the runtime image in stage 3 installs
# every extension — so skip its platform checks.
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-interaction \
      --no-progress \
      --prefer-dist \
      --ignore-platform-reqs \
      --optimize-autoloader

# ---------------------------------------------------------------------------
# Stage 2 — Front-end asset build (Vite)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
# The admin theme CSS (@import + @source) pulls from Filament's PHP package, so
# the built vendor tree has to be present for the Tailwind/Vite build.
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3 — PHP-FPM application image
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS app

# System deps + PHP extensions
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN apk add --no-cache su-exec \
 && install-php-extensions \
      pdo_mysql \
      mbstring \
      bcmath \
      gd \
      zip \
      intl \
      exif \
      pcntl \
      opcache \
      redis

WORKDIR /var/www/html

# Application source + built dependencies
COPY --chown=www-data:www-data . .
COPY --from=vendor  --chown=www-data:www-data /app/vendor       ./vendor
COPY --from=assets  --chown=www-data:www-data /app/public/build ./public/build

# PHP config
COPY docker/php/php.ini     /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
 && mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Stage 4 — Nginx image (serves static files, proxies PHP to the app container)
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=assets /app/public /var/www/html/public
