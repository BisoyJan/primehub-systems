#!/bin/bash
set -e

echo "🚀 Starting PrimeHub Systems..."

# Wait for database to be ready
echo "⏳ Waiting for database..."
until php artisan db:show 2>/dev/null; do
    echo "⏳ Database not ready yet, waiting..."
    sleep 2
done

echo "✅ Database is ready!"

# Install composer dependencies if vendor doesn't exist
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "📦 Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
else
    echo "✅ Composer dependencies already installed"
fi

# Generate app key if not set
if grep -q "APP_KEY=$" .env || ! grep -q "APP_KEY=" .env; then
    echo "🔑 Generating application key..."
    php artisan key:generate --no-interaction
fi

# Run migrations
echo "🗄️  Running database migrations..."
php artisan migrate --force

# Generate Wayfinder types for Vite
echo "🎯 Generating Wayfinder types..."
php artisan wayfinder:generate --with-form || echo "⚠️  Wayfinder generation skipped"

# Clear and cache config
echo "🔄 Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set permissions
echo "🔐 Setting permissions..."
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

echo "✅ Application ready!"

# Start PHP-FPM
exec php-fpm
