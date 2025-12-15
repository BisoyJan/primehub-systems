# PHP Extensions Setup for QR Code Functionality

## Overview
The QR code generation feature requires two PHP extensions to be enabled:
1. **zip** - For creating ZIP archives of multiple QR codes
2. **gd** - For generating PNG image files

## Production Environment Setup

### For Ubuntu/Debian Linux Servers

#### 1. Install Required Extensions

```bash
# Update package list
sudo apt update

# Install PHP GD extension
sudo apt install php-gd

# Install PHP ZIP extension
sudo apt install php-zip

# Restart PHP-FPM (adjust version number as needed)
sudo systemctl restart php8.2-fpm

# If using Apache
sudo systemctl restart apache2

# If using Nginx
sudo systemctl restart nginx
```

#### 2. Verify Extensions are Loaded

```bash
# Check if extensions are enabled
php -m | grep -i gd
php -m | grep -i zip

# Should output:
# gd
# zip
```

### For CentOS/RHEL Linux Servers

#### 1. Install Required Extensions

```bash
# Install PHP GD extension
sudo yum install php-gd

# Install PHP ZIP extension
sudo yum install php-zip

# Restart PHP-FPM (adjust version number as needed)
sudo systemctl restart php-fpm

# If using Apache
sudo systemctl restart httpd

# If using Nginx
sudo systemctl restart nginx
```

#### 2. Verify Extensions are Loaded

```bash
php -m | grep -i gd
php -m | grep -i zip
```

### For Windows Servers

#### 1. Locate php.ini File

```cmd
# Find php.ini location
php --ini
```

This will show the path to your php.ini file (e.g., `C:\php\php.ini`)

#### 2. Enable Extensions

1. Open `php.ini` in a text editor (as Administrator)
2. Find these lines:
   ```ini
   ;extension=gd
   ;extension=zip
   ```
3. Remove the semicolon (`;`) to uncomment them:
   ```ini
   extension=gd
   extension=zip
   ```
4. Save the file

#### 3. Restart Web Server

- **IIS**: Restart IIS Manager or run `iisreset`
- **Apache**: Restart Apache service
- **Built-in PHP Server**: Stop and restart `php artisan serve`

#### 4. Verify Extensions

```cmd
php -m | findstr /i "gd zip"
```

### For Docker Environments

If you're using Docker, add these extensions to your Dockerfile:

```dockerfile
# For PHP 8.2+ official images
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    && docker-php-ext-install gd zip \
    && docker-php-ext-enable gd zip

# Clean up
RUN apt-get clean && rm -rf /var/lib/apt/lists/*
```

Then rebuild your Docker image:

```bash
docker-compose build --no-cache
docker-compose up -d
```

### For Shared Hosting (cPanel/Plesk)

#### cPanel with MultiPHP Manager

1. Log into cPanel
2. Go to **Software** → **Select PHP Version**
3. Find and check the boxes for:
   - `gd`
   - `zip`
4. Click **Save**
5. Verify in **PHP Information** (phpinfo)

#### Plesk

1. Log into Plesk
2. Go to **Websites & Domains** → Select your domain
3. Click **PHP Settings**
4. Under PHP extensions, enable:
   - `gd`
   - `zip`
5. Click **OK**

## Verification After Setup

### Method 1: Command Line

```bash
# Check if both extensions are loaded
php -r "echo extension_loaded('gd') ? 'GD: ✓ Enabled' : 'GD: ✗ Disabled'; echo PHP_EOL;"
php -r "echo extension_loaded('zip') ? 'ZIP: ✓ Enabled' : 'ZIP: ✗ Disabled'; echo PHP_EOL;"
```

### Method 2: Create a Test Script

Create `test-extensions.php` in your project root:

```php
<?php
echo "PHP Version: " . PHP_VERSION . "\n\n";

echo "GD Extension: ";
if (extension_loaded('gd')) {
    echo "✓ Enabled\n";
    $info = gd_info();
    echo "  - GD Version: " . $info['GD Version'] . "\n";
    echo "  - PNG Support: " . ($info['PNG Support'] ? 'Yes' : 'No') . "\n";
} else {
    echo "✗ Disabled\n";
}

echo "\nZIP Extension: ";
if (extension_loaded('zip')) {
    echo "✓ Enabled\n";
    echo "  - ZipArchive class: " . (class_exists('ZipArchive') ? 'Available' : 'Not Available') . "\n";
} else {
    echo "✗ Disabled\n";
}
```

Run it:

```bash
php test-extensions.php
```

Expected output:

```
PHP Version: 8.2.x

GD Extension: ✓ Enabled
  - GD Version: bundled (2.1.0 compatible)
  - PNG Support: Yes

ZIP Extension: ✓ Enabled
  - ZipArchive class: Available
```

### Method 3: Laravel Artisan Command

Create a custom artisan command to check extensions:

```bash
php artisan tinker
```

Then run:

```php
echo extension_loaded('gd') ? "GD: Enabled\n" : "GD: Disabled\n";
echo extension_loaded('zip') ? "ZIP: Enabled\n" : "ZIP: Disabled\n";
```

## Troubleshooting

### Common Issues

#### 1. Extensions Enabled but Not Working

**Solution:** Make sure you're editing the correct php.ini file:

```bash
# Find which php.ini is being used
php --ini

# Check the "Loaded Configuration File" line
```

#### 2. Different php.ini for CLI vs Web

Some systems have separate php.ini files for CLI and web server.

**Solution:**

```bash
# Check CLI php.ini
php --ini

# Check web server php.ini (create phpinfo.php)
<?php phpinfo(); ?>
# Upload to your web server and access via browser
# Look for "Loaded Configuration File"
```

#### 3. Permission Issues (Linux)

**Error:** Cannot write to php.ini

**Solution:**

```bash
# Edit with sudo
sudo nano /etc/php/8.2/fpm/php.ini
sudo nano /etc/php/8.2/cli/php.ini
```

#### 4. Service Restart Required

Always restart your web server after modifying php.ini:

```bash
# PHP-FPM
sudo systemctl restart php8.2-fpm

# Apache
sudo systemctl restart apache2

# Nginx
sudo systemctl restart nginx
```

## Laravel Application Setup

After enabling the extensions, clear Laravel's cache:

```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Security Considerations

### Production Recommendations

1. **Limit Upload Size** - Configure max file size in php.ini:
   ```ini
   upload_max_filesize = 10M
   post_max_size = 10M
   ```

2. **Set Memory Limit**:
   ```ini
   memory_limit = 256M
   ```

3. **Disable phpinfo() in Production** - Remove any test files after verification

4. **Set Proper Permissions** on storage directory:
   ```bash
   sudo chown -R www-data:www-data storage/
   sudo chmod -R 775 storage/
   ```

## Testing the QR Code Feature

After enabling extensions, test the QR code functionality:

1. Log into your application
2. Navigate to PC Specs page
3. Select one or more PCs
4. Click "Download as ZIP"
5. Verify the ZIP file downloads and contains QR code images with PC numbers

## Support

If you encounter issues:

1. Check Laravel logs: `storage/logs/laravel.log`
2. Check PHP error logs:
   - Ubuntu/Debian: `/var/log/php8.2-fpm.log`
   - CentOS/RHEL: `/var/log/php-fpm/error.log`
   - Windows: `C:\xampp\php\logs\php_error_log`
3. Verify extension versions are compatible with your PHP version

## Quick Reference Commands

```bash
# Check PHP version
php -v

# List all loaded extensions
php -m

# Check specific extension
php -m | grep -i gd
php -m | grep -i zip

# Find php.ini location
php --ini

# Test extension loading
php -r "var_dump(extension_loaded('gd'), extension_loaded('zip'));"

# Restart services
sudo systemctl restart php8.2-fpm
sudo systemctl restart apache2    # or nginx
```

---

---

**Last Updated:** December 15, 2025  
**PHP Version Tested:** 8.2+, 8.3, 8.4  
**Laravel Version:** 11.x

*Last updated: December 15, 2025*
