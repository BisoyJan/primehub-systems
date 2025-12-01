#!/bin/bash

#######################################################################
# Database Connection Fix Script
# Fixes "Connection refused" MySQL errors
#######################################################################

set -e

APP_DIR="/var/www/primehub-systems"
APP_USER="primehub"

echo "========================================="
echo "Database Connection Diagnostics & Fix"
echo "========================================="
echo ""

echo "1. Checking MySQL service status..."
systemctl status mysql --no-pager || true
echo ""

echo "2. Starting MySQL if stopped..."
systemctl start mysql
systemctl enable mysql
echo "   ✓ MySQL started"
echo ""

echo "3. Checking if MySQL is listening..."
netstat -tuln | grep 3306 || ss -tuln | grep 3306 || echo "MySQL not listening on port 3306"
echo ""

echo "4. Checking .env database configuration..."
cd $APP_DIR
echo "   DB_HOST: $(grep DB_HOST .env | cut -d'=' -f2)"
echo "   DB_PORT: $(grep DB_PORT .env | cut -d'=' -f2)"
echo "   DB_DATABASE: $(grep DB_DATABASE .env | cut -d'=' -f2)"
echo "   DB_USERNAME: $(grep DB_USERNAME .env | cut -d'=' -f2)"
echo ""

echo "5. Testing database connection..."
DB_NAME=$(grep DB_DATABASE .env | cut -d'=' -f2)
DB_USER=$(grep DB_USERNAME .env | cut -d'=' -f2)
DB_PASS=$(grep DB_PASSWORD .env | cut -d'=' -f2)

# Test MySQL connection
if mysql -u "$DB_USER" -p"$DB_PASS" -e "USE $DB_NAME;" 2>/dev/null; then
    echo "   ✓ Database connection successful!"
else
    echo "   ✗ Database connection failed!"
    echo ""
    echo "Creating database and user..."
    
    # Try to connect as root and create database/user
    mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
    
    echo "   ✓ Database and user created"
fi
echo ""

echo "6. Running database migrations..."
cd $APP_DIR
sudo -u $APP_USER php artisan migrate --force
echo "   ✓ Migrations completed"
echo ""

echo "7. Clearing Laravel cache..."
sudo -u $APP_USER php artisan config:clear
sudo -u $APP_USER php artisan cache:clear
echo "   ✓ Cache cleared"
echo ""

echo "8. Restarting services..."
systemctl restart mysql
systemctl restart php8.4-fpm
supervisorctl restart primehub-queue 2>/dev/null || supervisorctl start primehub-queue
echo "   ✓ Services restarted"
echo ""

echo "========================================="
echo "✓ Database Fix Complete!"
echo "========================================="
echo ""
echo "Try accessing your application now."
echo ""
