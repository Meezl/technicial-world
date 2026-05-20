#!/bin/sh
set -e

echo "==> Starting Technician World deployment..."

# ── 1. Migrations ──────────────────────────────────────────────────────────────
echo "==> Running database migrations..."
php artisan migrate --force

# ── 2. Laravel bootstrap cache ─────────────────────────────────────────────────
if [ -n "$APP_KEY" ]; then
    echo "==> Caching Laravel configuration..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
else
    echo "WARNING: APP_KEY is not set. Skipping configuration caching."
fi

# ── 3. Storage symlink ─────────────────────────────────────────────────────────
if [ ! -L "/app/public/storage" ]; then
    echo "==> Creating storage symlink..."
    php artisan storage:link
fi

# ── 4. Diagnostics ────────────────────────────────────────────────────────────
echo "==> Environment check:"
echo "    PORT=$PORT"
echo "    APP_ENV=$APP_ENV"
echo "    DB_CONNECTION=${DB_CONNECTION:-not set}"
echo "    QUEUE_CONNECTION=${QUEUE_CONNECTION:-not set}"
echo "    CACHE_STORE=${CACHE_STORE:-not set}"
echo "    SESSION_DRIVER=${SESSION_DRIVER:-not set}"
echo "    REDIS_HOST=${REDIS_HOST:-not set}"
echo "    REDIS_PORT=${REDIS_PORT:-not set}"
echo "    REDIS_URL=${REDIS_URL:+set (hidden)}"

# Test that the app can boot and diagnose driver issues
echo "==> Running driver diagnostics..."
php /app/diagnose-driver.php 2>&1 || echo "WARNING: Diagnostic failed"

# ── 5. Queue worker (background, non-fatal) ──────────────────────────────────
echo "==> Starting queue worker in background..."
(php artisan queue:work --sleep=3 --tries=3 --timeout=90 2>&1 || echo "WARNING: Queue worker exited with error") &

echo "==> Startup complete. Launching web server..."

# ── 6. Hand off to the web server (FrankenPHP / Caddy) ────────────────────────
exec "$@"
