#!/usr/bin/env bash
set -euo pipefail

# Configure PHP upload limits for production
# Run with: sudo ./deploy/configure-php-limits.sh

PHP_VERSION="8.4"
PHP_INI="/etc/php/${PHP_VERSION}/fpm/php.ini"

echo "[Config] Updating PHP upload limits in ${PHP_INI}"

# Backup original file
sudo cp ${PHP_INI} ${PHP_INI}.bak.$(date +%Y%m%d_%H%M%S)

# Update upload_max_filesize
sudo sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 10M/' ${PHP_INI}

# Update post_max_size
sudo sed -i 's/^post_max_size = .*/post_max_size = 10M/' ${PHP_INI}

# Verify changes
echo "[Config] Verifying changes..."
grep "upload_max_filesize" ${PHP_INI}
grep "post_max_size" ${PHP_INI}

# Restart PHP-FPM
echo "[Config] Restarting PHP-FPM..."
sudo systemctl restart php${PHP_VERSION}-fpm

# Check status
sudo systemctl status php${PHP_VERSION}-fpm --no-pager

echo "[Config] PHP limits updated successfully!"
echo "[Config] upload_max_filesize: 10M"
echo "[Config] post_max_size: 10M"
