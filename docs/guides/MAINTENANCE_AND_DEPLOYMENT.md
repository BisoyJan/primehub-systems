# Maintenance Mode & Deployment Guide

This comprehensive guide covers maintenance mode operations and git pull deployment commands for the PrimeHub Systems Laravel + React application.

---

## Table of Contents

1. [Maintenance Mode Overview](#maintenance-mode-overview)
2. [Enabling Maintenance Mode](#enabling-maintenance-mode)
3. [Disabling Maintenance Mode](#disabling-maintenance-mode)
4. [Database Migration Workflow](#database-migration-workflow)
5. [Git Pull Deployment Commands](#git-pull-deployment-commands)
6. [Quick Reference Scripts](#quick-reference-scripts)
7. [Troubleshooting](#troubleshooting)

---

## Maintenance Mode Overview

Laravel's maintenance mode allows you to gracefully take the application offline for updates, migrations, or other maintenance tasks. During maintenance mode:

- All requests return a `503 Service Unavailable` response
- Queued jobs continue processing (unless you stop them)
- Specific IPs can be allowed access during maintenance
- A secret token can be used to bypass maintenance mode

### When to Use Maintenance Mode

| Scenario | Maintenance Mode? | Notes |
|----------|-------------------|-------|
| Database migrations | ✅ Yes | Prevents data corruption |
| Major code deployments | ✅ Yes | Ensures consistency |
| Minor frontend changes | ❌ No | Usually safe to deploy live |
| Adding new config | ⚠️ Depends | Use if config affects critical paths |
| Emergency hotfix | ✅ Yes | Prevents users from hitting broken code |

---

## Enabling Maintenance Mode

### Basic Maintenance Mode

```bash
# SSH into your server
ssh primehub@your-server-ip

# Navigate to application directory
cd /var/www/primehub-systems

# Enable maintenance mode
php artisan down
```

### Maintenance Mode with Custom Message

```bash
php artisan down --render="errors::503" --retry=60
```

### Allow Specific IPs (Admin Access)

```bash
# Allow your IP to bypass maintenance mode
php artisan down --allow=192.168.1.100 --allow=192.168.1.101

# Allow multiple IPs
php artisan down --allow=YOUR_OFFICE_IP --allow=YOUR_HOME_IP
```

### Secret Token Access

```bash
# Generate maintenance mode with secret bypass token
php artisan down --secret="your-secret-token-here"
```

Users can bypass by visiting: `https://yourdomain.com/your-secret-token-here`

This sets a cookie allowing continued access during maintenance.

### Maintenance Mode with Redirect

```bash
# Redirect all traffic to a status page
php artisan down --redirect=/maintenance-status
```

### Full Options Example

```bash
php artisan down \
    --secret="primehub-maint-2024" \
    --render="errors::503" \
    --retry=60 \
    --refresh=30 \
    --allow=YOUR_IP_ADDRESS
```

**Options Explained:**
- `--secret` - Token to bypass maintenance
- `--render` - Custom view to render
- `--retry` - HTTP Retry-After header value (seconds)
- `--refresh` - Browser auto-refresh interval (seconds)
- `--allow` - IP addresses to bypass maintenance

---

## Disabling Maintenance Mode

### Basic Command

```bash
php artisan up
```

### Verify Application is Up

```bash
# Check if maintenance mode is off
curl -I https://yourdomain.com

# Should return HTTP/2 200, not 503
```

---

## Database Migration Workflow

### Standard Migration Deployment

Follow this sequence for safe database migrations:

```bash
# Step 1: SSH into server
ssh primehub@your-server-ip

# Step 2: Navigate to app directory
cd /var/www/primehub-systems

# Step 3: Enable maintenance mode
php artisan down --secret="migration-in-progress-2024"

# Step 4: Stop queue workers (prevents jobs from running with old schema)
sudo supervisorctl stop primehub-queue

# Step 5: Pull latest code
git pull origin main

# Step 6: Install/update dependencies
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Step 7: Run migrations
php artisan migrate --force

# Step 8: Clear and rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Step 9: Restart queue workers
sudo supervisorctl start primehub-queue

# Step 10: Disable maintenance mode
php artisan up

# Step 11: Verify deployment
curl -I https://yourdomain.com
```

### Migration Rollback (If Something Goes Wrong)

```bash
# Rollback last batch of migrations
php artisan migrate:rollback

# Rollback specific number of migrations
php artisan migrate:rollback --step=3

# Rollback all migrations (DANGEROUS - drops all tables)
php artisan migrate:reset
```

### Preview Migration Changes (Before Running)

```bash
# Show SQL that would be executed
php artisan migrate --pretend

# Check migration status
php artisan migrate:status
```

---

## Git Pull Deployment Commands

### Quick Deployment (No Downtime for Minor Changes)

```bash
cd /var/www/primehub-systems

# Pull latest changes
git pull origin main

# Install dependencies (if composer.json changed)
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Install npm packages (if package.json changed)
npm ci

# Build frontend assets
npm run build

# Clear and rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:clear
php artisan view:cache

# Restart queue to pick up code changes
php artisan queue:restart
```

### Full Deployment (With Migrations)

```bash
#!/bin/bash
# Full deployment script

set -e  # Exit on error

APP_DIR="/var/www/primehub-systems"
BRANCH="main"

echo "🔧 Starting deployment..."

cd $APP_DIR

# Enable maintenance mode
echo "⚠️  Enabling maintenance mode..."
php artisan down --secret="deploy-secret-2024"

# Stop queue workers
echo "⏸️  Stopping queue workers..."
sudo supervisorctl stop primehub-queue

# Pull latest code
echo "📥 Pulling latest code from $BRANCH..."
git fetch origin
git reset --hard origin/$BRANCH

# Install PHP dependencies
echo "📦 Installing Composer dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Install Node dependencies
echo "📦 Installing Node dependencies..."
npm ci

# Build frontend
echo "🔨 Building frontend assets..."
npm run build

# Run migrations
echo "🗄️  Running database migrations..."
php artisan migrate --force

# Generate Wayfinder routes
echo "🛤️  Generating Wayfinder types..."
php artisan wayfinder:generate --with-form

# Cache optimization
echo "⚡ Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Set permissions
echo "🔒 Setting permissions..."
sudo chown -R primehub:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Restart queue workers
echo "▶️  Starting queue workers..."
sudo supervisorctl start primehub-queue

# Clear opcache (if using)
echo "🧹 Clearing opcache..."
sudo systemctl restart php8.4-fpm

# Disable maintenance mode
echo "✅ Disabling maintenance mode..."
php artisan up

echo "🎉 Deployment complete!"
```

### Backend-Only Deployment (PHP Changes Only)

```bash
cd /var/www/primehub-systems

# Pull changes
git pull origin main

# Update Composer dependencies
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart queue workers to pick up changes
php artisan queue:restart

# Restart PHP-FPM to clear opcache
sudo systemctl restart php8.4-fpm
```

### Frontend-Only Deployment (JS/CSS Changes Only)

```bash
cd /var/www/primehub-systems

# Pull changes
git pull origin main

# Install npm packages
npm ci

# Build frontend assets
npm run build

# Generate Wayfinder routes (if routes changed)
php artisan wayfinder:generate --with-form
```

### Hotfix Deployment (Emergency)

```bash
cd /var/www/primehub-systems

# Fetch and checkout specific commit or branch
git fetch origin
git checkout hotfix/critical-fix  # or specific commit hash

# Quick cache clear
php artisan cache:clear
php artisan config:clear

# Restart queue
php artisan queue:restart

# Restart PHP-FPM
sudo systemctl restart php8.4-fpm
```

---

## Quick Reference Scripts

### Create Deploy Script

Save this as `/var/www/primehub-systems/deploy.sh`:

```bash
#!/bin/bash
#######################################################################
# PrimeHub Systems - Deployment Script
#
# Usage:
#   ./deploy.sh              # Full deployment with migrations
#   ./deploy.sh --quick      # Quick deployment (no migrations)
#   ./deploy.sh --frontend   # Frontend only
#   ./deploy.sh --backend    # Backend only
#######################################################################

set -e

APP_DIR="/var/www/primehub-systems"
BRANCH="main"

cd $APP_DIR

deploy_full() {
    echo "🚀 Full Deployment"
    
    php artisan down --secret="deploy-$(date +%Y%m%d)"
    sudo supervisorctl stop primehub-queue
    
    git pull origin $BRANCH
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
    npm ci
    npm run build
    
    php artisan migrate --force
    php artisan wayfinder:generate --with-form
    
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    
    sudo chown -R primehub:www-data storage bootstrap/cache
    sudo chmod -R 775 storage bootstrap/cache
    
    sudo supervisorctl start primehub-queue
    sudo systemctl restart php8.4-fpm
    
    php artisan up
    echo "✅ Full deployment complete!"
}

deploy_quick() {
    echo "⚡ Quick Deployment (no migrations)"
    
    git pull origin $BRANCH
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
    npm ci
    npm run build
    
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    
    php artisan queue:restart
    echo "✅ Quick deployment complete!"
}

deploy_frontend() {
    echo "🎨 Frontend Deployment"
    
    git pull origin $BRANCH
    npm ci
    npm run build
    php artisan wayfinder:generate --with-form
    
    echo "✅ Frontend deployment complete!"
}

deploy_backend() {
    echo "⚙️  Backend Deployment"
    
    git pull origin $BRANCH
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
    
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    
    php artisan queue:restart
    sudo systemctl restart php8.4-fpm
    
    echo "✅ Backend deployment complete!"
}

case "${1:-full}" in
    --quick|-q)
        deploy_quick
        ;;
    --frontend|-f)
        deploy_frontend
        ;;
    --backend|-b)
        deploy_backend
        ;;
    --full|*)
        deploy_full
        ;;
esac
```

Make it executable:
```bash
chmod +x /var/www/primehub-systems/deploy.sh
```

### Usage Examples

```bash
# Full deployment
./deploy.sh

# Quick deployment (skip migrations)
./deploy.sh --quick

# Frontend only
./deploy.sh --frontend

# Backend only
./deploy.sh --backend
```

---

## Troubleshooting

### Common Issues

#### 1. Application Stuck in Maintenance Mode

```bash
# Force disable maintenance mode by removing the file
rm storage/framework/down

# Then properly enable/disable
php artisan up
```

#### 2. Migration Failed - How to Recover

```bash
# Check migration status
php artisan migrate:status

# Rollback failed migration
php artisan migrate:rollback

# Fix the migration file, then retry
php artisan migrate --force

# If still stuck, bring app up first
php artisan up
```

#### 3. Queue Workers Not Processing

```bash
# Check supervisor status
sudo supervisorctl status

# Restart queue workers
sudo supervisorctl restart primehub-queue

# Check queue worker logs
tail -f /var/log/supervisor/primehub-queue.log
```

#### 4. Permission Issues After Deployment

```bash
sudo chown -R primehub:www-data /var/www/primehub-systems
sudo chmod -R 775 /var/www/primehub-systems/storage
sudo chmod -R 775 /var/www/primehub-systems/bootstrap/cache
```

#### 5. Cache Not Clearing

```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Rebuild caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart PHP-FPM to clear opcache
sudo systemctl restart php8.4-fpm
```

#### 6. Git Pull Conflicts

```bash
# Force pull (overwrites local changes)
git fetch origin
git reset --hard origin/main

# Or stash local changes first
git stash
git pull origin main
git stash pop  # Apply stashed changes back
```

#### 7. NPM Build Failing

```bash
# Clear npm cache
npm cache clean --force

# Remove node_modules and reinstall
rm -rf node_modules package-lock.json
npm install
npm run build
```

#### 8. Composer Memory Issues

```bash
# Increase PHP memory limit for composer
php -d memory_limit=-1 /usr/local/bin/composer install --no-dev
```

---

## Service Control Commands

### Queue Worker Management

```bash
# View status
sudo supervisorctl status primehub-queue

# Stop
sudo supervisorctl stop primehub-queue

# Start
sudo supervisorctl start primehub-queue

# Restart
sudo supervisorctl restart primehub-queue

# Reload config
sudo supervisorctl reread
sudo supervisorctl update
```

### Nginx Management

```bash
# Test configuration
sudo nginx -t

# Restart
sudo systemctl restart nginx

# Reload (graceful)
sudo systemctl reload nginx

# View logs
tail -f /var/log/nginx/primehub_error.log
```

### PHP-FPM Management

```bash
# Restart
sudo systemctl restart php8.4-fpm

# Check status
sudo systemctl status php8.4-fpm

# View logs
tail -f /var/log/php8.4-fpm.log
```

### Redis Management

```bash
# Restart
sudo systemctl restart redis-server

# Check status
sudo systemctl status redis-server

# Clear Redis cache
redis-cli FLUSHALL
```

---

## Pre-Deployment Checklist

Before deploying, verify:

- [ ] All tests pass locally (`php artisan test`)
- [ ] Frontend builds successfully (`npm run build`)
- [ ] No ESLint errors (`npm run lint`)
- [ ] TypeScript compiles (`npm run types`)
- [ ] Database backup exists (if running migrations)
- [ ] Team is notified of deployment window
- [ ] Rollback plan is ready

---

## Post-Deployment Verification

After deploying, verify:

- [ ] Application responds (`curl -I https://yourdomain.com`)
- [ ] No 500 errors in logs (`tail storage/logs/laravel.log`)
- [ ] Queue workers running (`sudo supervisorctl status`)
- [ ] Key features work (login, navigation, CRUD operations)
- [ ] Check browser console for JS errors

---

## Database Backup Before Migration

Always backup before migrations:

```bash
# Create backup directory
mkdir -p /var/backups/primehub

# Backup database
mysqldump -u primehub_user -p primehub_systems > /var/backups/primehub/backup_$(date +%Y%m%d_%H%M%S).sql

# Verify backup exists
ls -la /var/backups/primehub/
```

Restore if needed:
```bash
mysql -u primehub_user -p primehub_systems < /var/backups/primehub/backup_YYYYMMDD_HHMMSS.sql
```

---

## Summary Commands Cheat Sheet

| Task | Command |
|------|---------|
| Enable maintenance | `php artisan down` |
| Enable with secret | `php artisan down --secret="token"` |
| Disable maintenance | `php artisan up` |
| Pull code | `git pull origin main` |
| Install PHP deps | `composer install --no-dev` |
| Install Node deps | `npm ci` |
| Build frontend | `npm run build` |
| Run migrations | `php artisan migrate --force` |
| Cache config | `php artisan config:cache` |
| Cache routes | `php artisan route:cache` |
| Restart queue | `php artisan queue:restart` |
| Restart PHP-FPM | `sudo systemctl restart php8.4-fpm` |
| Stop queue worker | `sudo supervisorctl stop primehub-queue` |
| Start queue worker | `sudo supervisorctl start primehub-queue` |
| Check logs | `tail -f storage/logs/laravel.log` |

---

*Last updated: December 2024*
