# Stage 1: Install PHP dependencies (pinned to PHP 8.3 to match the runner)
FROM php:8.3-cli-alpine AS composer-builder
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache unzip git
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# Stage 2: Build frontend assets
FROM node:20 AS frontend-builder
WORKDIR /app
COPY package.json ./
RUN npm install
COPY . .
COPY --from=composer-builder /app/vendor/tightenco/ziggy /app/vendor/tightenco/ziggy
RUN npm run build

# Stage 3: Final application server
FROM dunglas/frankenphp:1-php8.3 AS runner

# Install recommended PHP extensions
# redis is pinned to a specific PECL release so the build does not depend on
# the pecl.php.net channel.xml lookup, which has intermittently returned 504s.
RUN install-php-extensions \
    bcmath \
    gd \
    intl \
    opcache \
    pcntl \
    pdo_mysql \
    pdo_pgsql \
    redis-6.1.0 \
    zip

WORKDIR /app

# Copy application files from composer-builder
COPY --from=composer-builder /app /app

# Copy built frontend assets from frontend-builder
COPY --from=frontend-builder /app/public/build /app/public/build
COPY --from=frontend-builder /app/bootstrap/ssr /app/bootstrap/ssr

# Copy custom Caddyfile
COPY Caddyfile /etc/caddy/Caddyfile

# Copy and prepare entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

# Ensure storage directories exist and are writable
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Environment defaults
ENV PORT=8080
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr
ENV QUEUE_CONNECTION=redis
ENV CACHE_STORE=redis
ENV SESSION_DRIVER=redis
ENV APP_MAINTENANCE_DRIVER=file
ENV INERTIA_SSR_ENABLED=false

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
