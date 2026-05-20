#!/bin/sh
set -e

# Run standard Laravel caching optimizations if APP_KEY is set
if [ -n "$APP_KEY" ]; then
    echo "Caching Laravel bootstrap configurations..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
else
    echo "Warning: APP_KEY is not set. Skipping configuration caching."
fi

# Create public/storage symlink if it doesn't exist
if [ ! -d "/app/public/storage" ]; then
    php artisan storage:link
fi

# Execute the CMD passed to Docker
exec "$@"
