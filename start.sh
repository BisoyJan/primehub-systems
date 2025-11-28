#!/bin/bash
set -e

echo "========================================"
echo "  Starting PrimeHub Systems"
echo "========================================"

# Ensure platform env vars take precedence
if [ -f .env ]; then
    echo "[0/5] Removing local .env to use platform secrets..."
    rm .env
fi

# Ensure no stale cached config is pointing to sqlite
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true
php artisan view:clear >/dev/null 2>&1 || true
php artisan event:clear >/dev/null 2>&1 || true

# Debug the runtime database configuration
echo "DB_CONNECTION=${DB_CONNECTION:-unset}"
echo "DB_HOST=${DB_HOST:-unset}"
echo "DB_DATABASE=${DB_DATABASE:-unset}"

# Create storage symlink
echo "[1/5] Creating storage link..."
php artisan storage:link 2>/dev/null || true

# Wait for database connection
echo "[2/5] Waiting for database..."
max_attempts=30
attempt=0

until php artisan tinker --execute="DB::connection()->getPdo();" 2>/dev/null; do
    attempt=$((attempt + 1))
    if [ $attempt -ge $max_attempts ]; then
        echo "WARNING: Database not available after $max_attempts attempts"
        break
    fi
    echo "  Attempt $attempt/$max_attempts - waiting 2s..."
    sleep 2
done
echo "  Database connected!"

# Run database migrations
echo "[3/5] Running migrations..."
php artisan migrate --force || echo "WARNING: Migration failed"

# Optimize application caches
echo "[4/5] Optimizing application..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true
php artisan event:cache || true

# Start web server
PORT=${PORT:-8080}
echo "[5/5] Starting PHP server on port $PORT..."
echo "========================================"
exec php -d variables_order=EGPCS -S 0.0.0.0:$PORT -t public public/index.php
