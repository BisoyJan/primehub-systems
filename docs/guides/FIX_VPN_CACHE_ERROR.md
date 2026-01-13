# Fix: 500 Error When Employees Use VPN (USA Location)

## Problem
Employees accessing the system via VPN (USA location) encounter a 500 Internal Server Error on the dashboard.

## Root Cause
The error is **NOT actually VPN/location-related**. The stack trace shows:
```
file_put_contents() failed
→ Cache\FileStore->put()
→ DashboardController.php(55): Cache::remember()
```

The issue is **cache directory permission problems** on the production server. When the dashboard tries to cache statistics, it fails to write cache files.

## Solution

### Step 1: Fix Cache Directory Permissions (Critical)

SSH into the production server and run:

```bash
cd /var/www/primehub-systems

# Fix storage permissions
sudo chown -R www-data:www-data storage
sudo chmod -R 775 storage

# Specifically fix cache directories
sudo chmod -R 775 storage/framework/cache
sudo chmod -R 775 storage/framework/cache/data
sudo chmod -R 775 storage/logs

# Verify permissions
ls -la storage/framework/cache
```

### Step 2: Clear and Rebuild Cache

```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Rebuild for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Step 3: Alternative - Use Redis/Database Cache (Recommended)

For better performance and to avoid file permission issues, switch to Redis or database caching.

#### Option A: Redis Cache (Best Performance)

1. Install Redis on the server:
```bash
sudo apt update
sudo apt install redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server
```

2. Install PHP Redis extension:
```bash
sudo apt install php-redis
sudo systemctl restart php8.1-fpm  # or your PHP version
```

3. Update `.env`:
```env
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

4. Clear and test:
```bash
php artisan cache:clear
php artisan config:cache
```

#### Option B: Database Cache (Simpler)

1. Create cache table:
```bash
php artisan cache:table
php artisan migrate
```

2. Update `.env`:
```env
CACHE_STORE=database
```

3. Clear and test:
```bash
php artisan cache:clear
php artisan config:cache
```

### Step 4: Verify Fix

1. Check that cache writes work:
```bash
php artisan tinker
```

Then run:
```php
Cache::put('test_key', 'test_value', 60);
Cache::get('test_key'); // Should return 'test_value'
exit
```

2. Access the dashboard via browser - should work now

3. Check error logs:
```bash
tail -f storage/logs/laravel.log
```

## Prevention

### Add to Deployment Script

Add these commands to your deployment process (in `deploy/provision.sh` or similar):

```bash
# After git pull and composer install
sudo chown -R www-data:www-data storage
sudo chmod -R 775 storage
php artisan cache:clear
php artisan config:cache
```

### Monitor Disk Space

The cache directory can grow large. Set up a cron job to clear old cache:

```bash
# Add to crontab
0 2 * * * cd /var/www/primehub-systems && php artisan cache:clear >/dev/null 2>&1
```

## Why VPN Users Noticed It First

The error affects **all users**, but VPN users might have experienced it first because:

1. Different cache keys per user session
2. VPN users hitting uncached dashboard endpoints
3. Timing - they accessed during cache write attempts

**The VPN/USA location is a red herring** - the actual issue is file permissions.

## Quick Test Script

Run this on the server to diagnose cache issues:

```bash
#!/bin/bash
echo "Testing cache permissions..."

# Test write permissions
touch storage/framework/cache/test_file && echo "✓ Can write to cache directory" || echo "✗ Cannot write to cache directory"
rm -f storage/framework/cache/test_file

# Check ownership
echo "Cache directory ownership:"
ls -la storage/framework/cache | head -3

# Test PHP cache
php artisan tinker --execute="Cache::put('test', 'value', 60); echo Cache::get('test') . PHP_EOL;"
```

## Related Files
- [app/Http/Controllers/DashboardController.php](../../app/Http/Controllers/DashboardController.php) - Line 55 (Cache::remember call)
- [storage/framework/cache/](../../storage/framework/cache/) - Cache storage directory
- [config/cache.php](../../config/cache.php) - Cache configuration

## See Also
- [MAINTENANCE_AND_DEPLOYMENT.md](./MAINTENANCE_AND_DEPLOYMENT.md) - Deployment best practices
- [config/cache.php](../../config/cache.php) - Cache driver configuration
