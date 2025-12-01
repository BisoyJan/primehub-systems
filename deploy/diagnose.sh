#!/bin/bash

#######################################################################
# Laravel Application Diagnostic Script
# Run this on your VPS to diagnose deployment issues
#######################################################################

set -e

APP_DIR="/var/www/primehub-systems"
APP_USER="primehub"

echo "========================================="
echo "Laravel Application Diagnostics"
echo "========================================="
echo ""

echo "1. Checking .env file..."
if [ -f "$APP_DIR/.env" ]; then
    echo "   ✓ .env exists"
    echo "   APP_KEY: $(grep APP_KEY $APP_DIR/.env | cut -d'=' -f2 | cut -c1-20)..."
    echo "   APP_ENV: $(grep APP_ENV $APP_DIR/.env | cut -d'=' -f2)"
    echo "   APP_DEBUG: $(grep APP_DEBUG $APP_DIR/.env | cut -d'=' -f2)"
else
    echo "   ✗ .env file NOT FOUND!"
fi
echo ""

echo "2. Checking storage permissions..."
ls -la $APP_DIR/storage/ | head -n 5
echo ""

echo "3. Checking bootstrap/cache permissions..."
ls -la $APP_DIR/bootstrap/cache/ | head -n 5
echo ""

echo "4. Checking database connection..."
cd $APP_DIR
sudo -u $APP_USER php artisan migrate:status 2>&1 | head -n 10
echo ""

echo "5. Checking Laravel configuration..."
cd $APP_DIR
sudo -u $APP_USER php artisan about 2>&1 || echo "Error running 'php artisan about'"
echo ""

echo "6. Viewing last 50 lines of Laravel log..."
echo "========================================="
tail -n 50 $APP_DIR/storage/logs/laravel.log 2>&1 || echo "No log file found"
echo ""

echo "========================================="
echo "7. Attempting fixes..."
echo "========================================="
echo ""

echo "Fixing storage permissions..."
sudo chown -R $APP_USER:www-data $APP_DIR/storage
sudo chown -R $APP_USER:www-data $APP_DIR/bootstrap/cache
sudo chmod -R 775 $APP_DIR/storage
sudo chmod -R 775 $APP_DIR/bootstrap/cache
echo "   ✓ Permissions fixed"
echo ""

echo "Clearing and caching configuration..."
cd $APP_DIR
sudo -u $APP_USER php artisan config:clear
sudo -u $APP_USER php artisan route:clear
sudo -u $APP_USER php artisan view:clear
echo "   ✓ Cache cleared"
echo ""

echo "Generating Wayfinder routes..."
sudo -u $APP_USER php artisan wayfinder:generate 2>&1 || echo "Wayfinder generation failed"
echo ""

echo "Caching configuration..."
sudo -u $APP_USER php artisan config:cache
sudo -u $APP_USER php artisan route:cache
echo "   ✓ Configuration cached"
echo ""

echo "Restarting services..."
sudo systemctl restart php8.4-fpm
sudo systemctl restart nginx
echo "   ✓ Services restarted"
echo ""

echo "========================================="
echo "Diagnostics Complete!"
echo "========================================="
echo ""
echo "Try visiting your site now: https://prmhubsystems.com"
echo ""
echo "If still not working, check:"
echo "  - tail -f /var/log/nginx/primehub_error.log"
echo "  - tail -f $APP_DIR/storage/logs/laravel.log"
