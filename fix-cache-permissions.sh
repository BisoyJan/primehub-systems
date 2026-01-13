#!/bin/bash
# Fix cache permissions for production server
# Run this on the VPS as root or with sudo
#
# Usage: sudo ./fix-cache-permissions.sh

set -e

APP_DIR="/var/www/primehub-systems"
APP_USER="primehub"

echo "========================================="
echo "Fixing Cache Permissions"
echo "========================================="

# Check if we're in the right directory
if [ ! -d "$APP_DIR" ]; then
    echo "Error: Application directory $APP_DIR not found!"
    exit 1
fi

cd $APP_DIR

echo "→ Setting ownership to $APP_USER:www-data..."
chown -R $APP_USER:www-data storage
chown -R $APP_USER:www-data bootstrap/cache

echo "→ Setting permissions to 775..."
chmod -R 775 storage
chmod -R 775 bootstrap/cache

echo "→ Creating cache subdirectories if missing..."
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs

echo "→ Fixing cache directory permissions specifically..."
chown -R $APP_USER:www-data storage/framework/cache
chmod -R 775 storage/framework/cache
chmod -R 775 storage/framework/cache/data
chmod -R 775 storage/framework/sessions
chmod -R 775 storage/framework/views

echo "→ Fixing logs directory..."
chown -R $APP_USER:www-data storage/logs
chmod -R 775 storage/logs

echo ""
echo "→ Clearing all caches..."
sudo -u $APP_USER php artisan cache:clear
sudo -u $APP_USER php artisan config:clear
sudo -u $APP_USER php artisan route:clear
sudo -u $APP_USER php artisan view:clear

echo ""
echo "→ Rebuilding caches for production..."
sudo -u $APP_USER php artisan config:cache
sudo -u $APP_USER php artisan route:cache
sudo -u $APP_USER php artisan view:cache

echo ""
echo "========================================="
echo "✓ Cache permissions fixed!"
echo "✓ Cache cleared and optimized"
echo "========================================="
echo ""

# Test cache write
echo "→ Testing cache write..."
sudo -u $APP_USER php artisan tinker --execute="
    Cache::put('permission_test', 'success', 60);
    echo 'Cache test: ' . Cache::get('permission_test') . PHP_EOL;
    Cache::forget('permission_test');
" && echo "✓ Cache write test PASSED" || echo "✗ Cache write test FAILED"

echo ""
echo "Current storage permissions:"
ls -la storage/ | head -10

echo ""
echo "Current cache permissions:"
ls -la storage/framework/cache/ 2>/dev/null || echo "Cache directory structure:"
find storage/framework/cache -type d -exec ls -ld {} \; 2>/dev/null | head -5

echo ""
echo "Done! Dashboard should work now."
