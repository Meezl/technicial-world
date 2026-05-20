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
# Use -L (symlink) not -d (directory) — a broken symlink passes -d but not -L
if [ ! -L "/app/public/storage" ]; then
    echo "==> Creating storage symlink..."
    php artisan storage:link
fi

# ── 4. Queue worker (background) ──────────────────────────────────────────────
echo "==> Starting queue worker in background..."
php artisan queue:work --sleep=3 --tries=3 --timeout=90 --daemon &

echo "==> Startup complete. Launching web server..."

# ── 5. Hand off to the web server (FrankenPHP / Caddy) ────────────────────────
exec "$@"
