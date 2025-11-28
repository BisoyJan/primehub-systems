#!/bin/bash
set -e

echo "Starting PrimeHub Systems..."

# Create storage link
php artisan storage:link 2>/dev/null || true

# Wait for database to be available
echo "Waiting for database..."
max_attempts=30
attempt=0
until php artisan tinker --execute="DB::connection()->getPdo();" 2>/dev/null; do
    attempt=$((attempt + 1))
    if [ $attempt -ge $max_attempts ]; then
        echo "Database not available after $max_attempts attempts, starting anyway..."
        break
    fi
    echo "Attempt $attempt/$max_attempts - Database not ready, waiting..."
    sleep 2
done

# Run migrations
echo "Running migrations..."
php artisan migrate --force || echo "Migration failed, continuing..."

# Cache configuration
echo "Caching configuration..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

echo "Starting Nginx..."
exec heroku-php-nginx -C nginx.conf public/
