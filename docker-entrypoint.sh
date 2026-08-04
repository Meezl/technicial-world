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


# ── 3. Storage directories ────────────────────────────────────────────────────
# These are created at build time too, but a Railway volume mounted at
# /app/storage masks whatever the image baked in — so re-create them here,
# after the mount exists. Idempotent.
echo "==> Ensuring storage directories exist..."
mkdir -p storage/app/public \
         storage/framework/sessions \
         storage/framework/views \
         storage/framework/cache/data \
         storage/logs
chmod -R 775 storage 2>/dev/null || true

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
echo "    FILESYSTEM_DISK=${FILESYSTEM_DISK:-not set}"
# Uploaded evidence lives on the 'public' disk. If this count resets to 0
# after a deploy, no persistent volume is attached and every photo the
# clients and technicians uploaded has just been deleted.
echo "    uploaded files on public disk: $(find storage/app/public -type f 2>/dev/null | wc -l | tr -d ' ')"
echo "    storage volume mounted: $(mountpoint -q /app/storage 2>/dev/null && echo yes || echo 'no / unknown')"

# ── 5. Queue worker (background, non-fatal) ──────────────────────────────────
echo "==> Starting queue worker in background..."
(php artisan queue:work --sleep=3 --tries=3 --timeout=90 2>&1 || echo "WARNING: Queue worker exited with error") &

echo "==> Startup complete. Launching web server..."

# ── 6. Hand off to the web server (FrankenPHP / Caddy) ────────────────────────
exec "$@"
