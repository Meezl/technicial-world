# Stage 1: Build frontend assets
FROM node:20 AS frontend-builder
WORKDIR /app
COPY package.json ./
RUN npm install
COPY . .
RUN npm run build

# Stage 2: Install PHP dependencies
FROM composer:2 AS composer-builder
WORKDIR /app
COPY composer*.json ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize

# Stage 3: Final application server
FROM dunglas/frankenphp:1-php8.3 AS runner

# Install recommended PHP extensions
RUN install-php-extensions \
    bcmath \
    gd \
    intl \
    opcache \
    pcntl \
    pdo_mysql \
    pdo_pgsql \
    redis \
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
ENV SERVER_NAME=:8080
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
