#!/bin/bash

#######################################################################
# Quick Update Script - Pull latest code and rebuild
# Usage: ./update.sh
#######################################################################

set -e

APP_DIR="/var/www/primehub-systems"
APP_USER="primehub"
BRANCH="main"

echo "========================================="
echo "Updating PrimeHub Systems"
echo "========================================="
echo ""

echo "1. Enabling maintenance mode..."
cd $APP_DIR
sudo -u $APP_USER php artisan down || true
echo "   ✓ Maintenance mode enabled"
echo ""

echo "2. Pulling latest code from GitHub..."
cd $APP_DIR
sudo -u $APP_USER git fetch --all
sudo -u $APP_USER git pull origin $BRANCH
echo "   ✓ Code updated"
echo ""

echo "3. Installing/updating Composer dependencies..."
sudo -u $APP_USER composer install --no-dev --optimize-autoloader --no-interaction
echo "   ✓ Composer dependencies updated"
echo ""

echo "4. Installing/updating Node dependencies..."
sudo -u $APP_USER pnpm install || sudo -u $APP_USER npm install
echo "   ✓ Node dependencies updated"
echo ""

echo "5. Building frontend assets..."
sudo -u $APP_USER pnpm run build || sudo -u $APP_USER npm run build
echo "   ✓ Frontend built"
echo ""

echo "6. Running database migrations..."
sudo -u $APP_USER php artisan migrate --force
echo "   ✓ Migrations completed"
echo ""

echo "7. Clearing and caching configuration..."
sudo -u $APP_USER php artisan config:clear
sudo -u $APP_USER php artisan route:clear
sudo -u $APP_USER php artisan view:clear
sudo -u $APP_USER php artisan wayfinder:generate
sudo -u $APP_USER php artisan config:cache
sudo -u $APP_USER php artisan route:cache
echo "   ✓ Cache updated"
echo ""

echo "8. Fixing permissions..."
sudo chown -R $APP_USER:www-data $APP_DIR/storage
sudo chown -R $APP_USER:www-data $APP_DIR/bootstrap/cache
sudo chmod -R 775 $APP_DIR/storage
sudo chmod -R 775 $APP_DIR/bootstrap/cache
echo "   ✓ Permissions fixed"
echo ""

echo "9. Restarting services..."
sudo systemctl restart php8.4-fpm
sudo systemctl reload nginx
sudo supervisorctl restart primehub-queue 2>/dev/null || true
echo "   ✓ Services restarted"
echo ""

echo "10. Disabling maintenance mode..."
sudo -u $APP_USER php artisan up
echo "   ✓ Application is live!"
echo ""

echo "========================================="
echo "✓ Update Complete!"
echo "========================================="
echo ""
echo "Your application is now running the latest code."
echo "Visit: https://prmhubsystems.com"
echo ""
