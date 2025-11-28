# DigitalOcean Deployment Guide

Complete guide for deploying PrimeHub Systems to DigitalOcean.

## Table of Contents
- [Prerequisites](#prerequisites)
- [Option 1: Using DigitalOcean App Platform (Easiest)](#option-1-using-digitalocean-app-platform-easiest)
- [Option 2: Using Droplet with Laravel Forge](#option-2-using-droplet-with-laravel-forge)
- [Option 3: Manual Droplet Setup](#option-3-manual-droplet-setup)
- [Post-Deployment Configuration](#post-deployment-configuration)
- [CI/CD Setup](#cicd-setup)
- [Troubleshooting](#troubleshooting)

---

## Prerequisites

Before deploying, ensure you have:

- [x] DigitalOcean account ([Sign up here](https://www.digitalocean.com/))
- [x] GitHub repository with your code
- [x] Domain name (optional but recommended)
- [x] SSH key pair (for Droplet access)

### Cost Estimates
- **App Platform**: ~$22.50/month (Web: $5, Worker: $2.50, MySQL: $15)
- **Droplet + Managed Database**: $18-30/month
- **Droplet Only** (with local DB): $12/month

---

## Option 1: Using DigitalOcean App Platform (Easiest)

**Best for**: Quick deployment, automatic scaling, managed infrastructure

### Step 1: Prepare Your Repository

1. **Create a deployment branch** (optional but recommended):
   ```bash
   git checkout -b production
   git push origin production
   ```

2. **Add required files to your repository**:

   Create `.do/app.yaml` in your project root (already configured):
   ```yaml
   name: primehub-systems
   region: sgp1
   
   services:
   - name: web
     github:
       repo: BisoyJan/primehub-systems
       branch: main
       deploy_on_push: true
     
     build_command: |
       composer install --optimize-autoloader --no-dev
       php artisan wayfinder:generate --with-form
       npm ci
       npm run build
     
     run_command: |
       php artisan storage:link
       php artisan migrate --force
       php artisan config:cache
       php artisan route:cache
       php artisan view:cache
       heroku-php-apache2 -C apache.conf public/
     
     environment_slug: php
     http_port: 8080
     instance_count: 1
     instance_size_slug: basic-xs
     
     envs:
     - key: APP_NAME
       value: "PrimeHub Systems"
     - key: APP_ENV
       value: production
     - key: APP_DEBUG
       value: "false"
     - key: APP_KEY
       scope: RUN_AND_BUILD_TIME
       type: SECRET
     - key: APP_URL
       value: ${APP_URL}
     - key: LOG_CHANNEL
       value: errorlog
     - key: SESSION_DRIVER
       value: database
     - key: SESSION_ENCRYPT
       value: "true"
     - key: CACHE_STORE
       value: database
     - key: QUEUE_CONNECTION
       value: database
     - key: FILESYSTEM_DISK
       value: local
     - key: DB_CONNECTION
       value: mysql
     - key: DB_HOST
       value: ${db.HOSTNAME}
     - key: DB_PORT
       value: ${db.PORT}
     - key: DB_DATABASE
       value: ${db.DATABASE}
     - key: DB_USERNAME
       value: ${db.USERNAME}
     - key: DB_PASSWORD
       value: ${db.PASSWORD}
     - key: MAIL_MAILER
       value: smtp
     - key: MAIL_HOST
       scope: RUN_TIME
       type: SECRET
     - key: MAIL_PORT
       value: "587"
     - key: MAIL_USERNAME
       scope: RUN_TIME
       type: SECRET
     - key: MAIL_PASSWORD
       scope: RUN_TIME
       type: SECRET
     - key: MAIL_FROM_ADDRESS
       scope: RUN_TIME
       type: SECRET
     - key: MAIL_FROM_NAME
       value: "PrimeHub Systems"
   
   - name: worker
     github:
       repo: BisoyJan/primehub-systems
       branch: main
     
     build_command: |
       composer install --optimize-autoloader --no-dev
     
     run_command: php artisan queue:work --sleep=3 --tries=3 --max-time=3600
     
     environment_slug: php
     instance_count: 1
     instance_size_slug: basic-xxs
     
     envs:
     - key: APP_NAME
       value: "PrimeHub Systems"
     - key: APP_ENV
       value: production
     - key: APP_KEY
       scope: RUN_AND_BUILD_TIME
       type: SECRET
     - key: LOG_CHANNEL
       value: errorlog
     - key: DB_CONNECTION
       value: mysql
     - key: DB_HOST
       value: ${db.HOSTNAME}
     - key: DB_PORT
       value: ${db.PORT}
     - key: DB_DATABASE
       value: ${db.DATABASE}
     - key: DB_USERNAME
       value: ${db.USERNAME}
     - key: DB_PASSWORD
       value: ${db.PASSWORD}
     - key: QUEUE_CONNECTION
       value: database
     - key: CACHE_STORE
       value: database
   
   databases:
   - name: db
     engine: MYSQL
     version: "8"
     production: true
     size: db-s-1vcpu-1gb
   ```
   
   Create `apache.conf` in your project root (already configured):
   ```apache
   DocumentRoot /app/public
   
   <Directory /app/public>
       AllowOverride All
       Require all granted
       Options -Indexes +FollowSymLinks
   
       RewriteEngine On
       RewriteCond %{HTTP:Authorization} .
       RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
       RewriteCond %{REQUEST_FILENAME} !-d
       RewriteCond %{REQUEST_URI} (.+)/$
       RewriteRule ^ %1 [L,R=301]
       RewriteCond %{REQUEST_FILENAME} !-d
       RewriteCond %{REQUEST_FILENAME} !-f
       RewriteRule ^ index.php [L]
   </Directory>
   
   <FilesMatch "^\.">
       Require all denied
   </FilesMatch>
   ```

3. **Generate APP_KEY**:
   ```bash
   # Run locally to generate key
   php artisan key:generate --show
   ```
   Copy the generated key (e.g., `base64:xyz123...`)

4. **Add APP_KEY and other secrets to environment variables**:
   - In App Platform dashboard, go to **Settings** → **App-Level Environment Variables**
   - Add these secrets (mark all as **Encrypted**):
   
   | Variable | Description |
   |----------|-------------|
   | `APP_KEY` | Generated key from step above |
   | `MAIL_HOST` | SMTP host (e.g., `smtp.gmail.com`) |
   | `MAIL_USERNAME` | SMTP username |
   | `MAIL_PASSWORD` | SMTP password |
   | `MAIL_FROM_ADDRESS` | Sender email address |

5. **Set APP_URL after first deployment**:
   - Copy your app URL (e.g., `https://primehub-systems-xxxxx.ondigitalocean.app`)
   - Add `APP_URL` environment variable with this value

6. **Trigger deployment**:
   - Click **Actions** → **Force Rebuild and Deploy**

### Step 3: Verify Deployment

Migrations run automatically during deployment via the `run_command` in `app.yaml`.

1. **Check deployment status**:
   - In App Platform dashboard, monitor the **Activity** tab
   - Wait for deployment to complete (5-10 minutes)

2. **View Runtime Logs** if there are issues:
   - Click **Runtime Logs** tab
   - Look for any error messages

3. **Access your app**:
   - Click the app URL in the dashboard
   - You should see the login page

4. **Optional - Seed database via Console**:
   - Click **Console** tab → Select `web` → **Open Console**
   ```bash
   php artisan db:seed --force
   ```

### Step 4: Configure Domain (Optional)

1. In App Platform dashboard, go to **Settings** → **Domains**
2. Click **Add Domain**
3. Enter your domain name
4. Update your domain's DNS records with the provided CNAME

---

## Option 2: Using Droplet with Laravel Forge

**Best for**: More control, easier management, professional workflow

### Step 1: Create a Droplet

1. **Sign up for Laravel Forge**: [forge.laravel.com](https://forge.laravel.com/)
   - Links your DigitalOcean account
   - $12/month for Forge + Droplet costs

2. **Create Server via Forge**:
   - Click **Create Server**
   - Choose **DigitalOcean**
   - Select region (closest to your users)
   - Choose server size: **Basic** / **$12/mo** (2GB RAM, 1 vCPU)
   - Select **PHP 8.2**
   - Enable: MySQL 8.0, Redis, Node.js
   - Click **Create Server** (takes 5-10 minutes)

### Step 2: Create Site

1. **Add Site**:
   - Click **New Site**
   - Project Type: **Laravel**
   - Root Domain: `yourdomain.com` (or use Forge subdomain for testing)
   - Web Directory: `/public`
   - PHP Version: **8.2**

2. **Connect Repository**:
   - Go to **Git Repository**
   - Connect to GitHub
   - Select `BisoyJan/primehub-systems`
   - Branch: `main`
   - Enable **Quick Deploy** (auto-deploy on push)

### Step 3: Configure Environment

1. **Update Environment Variables**:
   - Go to **Environment** tab
   - Update `.env` values:
   ```env
   APP_NAME="PrimeHub Systems"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://yourdomain.com
   
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=forge
   DB_USERNAME=forge
   DB_PASSWORD=<auto-generated>
   
   SESSION_DRIVER=database
   CACHE_STORE=redis
   QUEUE_CONNECTION=redis
   
   REDIS_HOST=127.0.0.1
   REDIS_PASSWORD=null
   REDIS_PORT=6379
   ```

2. **Generate APP_KEY** (if not set):
   - Click **Generate Key** button in Forge

### Step 4: Deploy Application

1. **Install Composer Dependencies**:
   - Go to **Deployment** tab
   - Update deploy script:
   ```bash
   cd /home/forge/yourdomain.com
   git pull origin main
   
   composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
   
   echo "Running migrations..."
   php artisan migrate --force
   
   echo "Generating Wayfinder types..."
   php artisan wayfinder:generate --with-form
   
   echo "Building frontend assets..."
   npm ci
   npm run build
   
   echo "Optimizing application..."
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   
   echo "Restarting services..."
   php artisan queue:restart
   
   echo "Deployment complete!"
   ```

2. **Click Deploy Now**

### Step 5: Configure Queue Worker

1. Go to **Daemons** tab
2. Click **New Daemon**
3. Configure:
   - Command: `php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600`
   - Directory: `/home/forge/yourdomain.com`
   - User: `forge`
   - Click **Create**

### Step 6: Setup SSL Certificate

1. Go to **SSL** tab
2. Click **LetsEncrypt**
3. Enter your domains (e.g., `yourdomain.com`, `www.yourdomain.com`)
4. Click **Obtain Certificate** (takes 1-2 minutes)
5. Enable **Force HTTPS**

### Step 7: Schedule Tasks (Cron)

1. Go to **Scheduler** tab
2. Forge automatically sets up Laravel's scheduler
3. Verify: `php artisan schedule:run` runs every minute

---

## Option 3: Manual Droplet Setup

**Best for**: Maximum control, learning DevOps, custom requirements

### Step 1: Create Droplet

1. Go to [DigitalOcean Console](https://cloud.digitalocean.com/)
2. Click **Create** → **Droplets**
3. Configure:
   - **Image**: Ubuntu 22.04 LTS
   - **Plan**: Basic / Regular / $12/mo (2GB RAM, 1 vCPU, 50GB SSD)
   - **Datacenter**: Choose closest to users
   - **Authentication**: SSH Key (add your public key)
   - **Hostname**: `primehub-production`
4. Click **Create Droplet**

### Step 2: Initial Server Setup

1. **SSH into your Droplet**:
   ```bash
   ssh root@your_droplet_ip
   ```

2. **Update system packages**:
   ```bash
   apt update && apt upgrade -y
   ```

3. **Create deploy user**:
   ```bash
   adduser deploy
   usermod -aG sudo deploy
   
   # Copy SSH keys
   rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy
   
   # Switch to deploy user
   su - deploy
   ```

### Step 3: Install Required Software

1. **Install PHP 8.2**:
   ```bash
   sudo apt install -y software-properties-common
   sudo add-apt-repository ppa:ondrej/php -y
   sudo apt update
   
   sudo apt install -y php8.2 php8.2-fpm php8.2-cli php8.2-common \
     php8.2-mysql php8.2-zip php8.2-gd php8.2-mbstring \
     php8.2-curl php8.2-xml php8.2-bcmath php8.2-redis
   ```

2. **Install Composer**:
   ```bash
   curl -sS https://getcomposer.org/installer | php
   sudo mv composer.phar /usr/local/bin/composer
   composer --version
   ```

3. **Install Node.js & npm**:
   ```bash
   curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
   sudo apt install -y nodejs
   node --version
   npm --version
   ```

4. **Install MySQL 8**:
   ```bash
   sudo apt install -y mysql-server
   sudo mysql_secure_installation
   
   # Create database
   sudo mysql -e "CREATE DATABASE primehub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   sudo mysql -e "CREATE USER 'primehub'@'localhost' IDENTIFIED BY 'your_secure_password';"
   sudo mysql -e "GRANT ALL PRIVILEGES ON primehub.* TO 'primehub'@'localhost';"
   sudo mysql -e "FLUSH PRIVILEGES;"
   ```

5. **Install Redis**:
   ```bash
   sudo apt install -y redis-server
   sudo systemctl enable redis-server
   sudo systemctl start redis-server
   ```

6. **Install Nginx**:
   ```bash
   sudo apt install -y nginx
   sudo systemctl enable nginx
   sudo systemctl start nginx
   ```

### Step 4: Deploy Application

1. **Clone repository**:
   ```bash
   cd /var/www
   sudo mkdir -p primehub
   sudo chown deploy:deploy primehub
   cd primehub
   
   git clone https://github.com/BisoyJan/primehub-systems.git .
   ```

2. **Install dependencies**:
   ```bash
   composer install --optimize-autoloader --no-dev
   npm ci
   ```

3. **Configure environment**:
   ```bash
   cp .env.example .env
   nano .env
   ```
   
   Update these values:
   ```env
   APP_NAME="PrimeHub Systems"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=http://your_droplet_ip
   
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=primehub
   DB_USERNAME=primehub
   DB_PASSWORD=your_secure_password
   
   CACHE_STORE=redis
   SESSION_DRIVER=database
   QUEUE_CONNECTION=redis
   
   REDIS_HOST=127.0.0.1
   REDIS_PASSWORD=null
   REDIS_PORT=6379
   ```

4. **Generate application key**:
   ```bash
   php artisan key:generate
   ```

5. **Run migrations**:
   ```bash
   php artisan migrate --force
   php artisan db:seed --force  # Optional
   ```

6. **Build frontend assets**:
   ```bash
   php artisan wayfinder:generate --with-form
   npm run build
   ```

7. **Optimize application**:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

8. **Set permissions**:
   ```bash
   sudo chown -R deploy:www-data /var/www/primehub
   sudo chmod -R 775 /var/www/primehub/storage
   sudo chmod -R 775 /var/www/primehub/bootstrap/cache
   ```

### Step 5: Configure Nginx

1. **Create Nginx config**:
   ```bash
   sudo nano /etc/nginx/sites-available/primehub
   ```
   
   Add configuration:
   ```nginx
   server {
       listen 80;
       listen [::]:80;
       server_name your_domain_or_ip;
       root /var/www/primehub/public;
   
       add_header X-Frame-Options "SAMEORIGIN";
       add_header X-Content-Type-Options "nosniff";
   
       index index.php;
   
       charset utf-8;
   
       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }
   
       location = /favicon.ico { access_log off; log_not_found off; }
       location = /robots.txt  { access_log off; log_not_found off; }
   
       error_page 404 /index.php;
   
       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
           include fastcgi_params;
           fastcgi_hide_header X-Powered-By;
       }
   
       location ~ /\.(?!well-known).* {
           deny all;
       }
   }
   ```

2. **Enable site**:
   ```bash
   sudo ln -s /etc/nginx/sites-available/primehub /etc/nginx/sites-enabled/
   sudo nginx -t
   sudo systemctl reload nginx
   ```

### Step 6: Setup Queue Worker as Service

1. **Create systemd service file**:
   ```bash
   sudo nano /etc/systemd/system/primehub-worker.service
   ```
   
   Add:
   ```ini
   [Unit]
   Description=PrimeHub Queue Worker
   After=network.target
   
   [Service]
   Type=simple
   User=deploy
   Group=www-data
   Restart=always
   RestartSec=5
   WorkingDirectory=/var/www/primehub
   ExecStart=/usr/bin/php /var/www/primehub/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
   StandardOutput=append:/var/www/primehub/storage/logs/worker.log
   StandardError=append:/var/www/primehub/storage/logs/worker-error.log
   
   [Install]
   WantedBy=multi-user.target
   ```

2. **Enable and start service**:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable primehub-worker
   sudo systemctl start primehub-worker
   sudo systemctl status primehub-worker
   ```

### Step 7: Setup Laravel Scheduler

1. **Edit crontab**:
   ```bash
   sudo crontab -e -u deploy
   ```
   
   Add:
   ```cron
   * * * * * cd /var/www/primehub && php artisan schedule:run >> /dev/null 2>&1
   ```

### Step 8: Setup SSL with Let's Encrypt

1. **Install Certbot**:
   ```bash
   sudo apt install -y certbot python3-certbot-nginx
   ```

2. **Obtain certificate**:
   ```bash
   sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com
   ```

3. **Test auto-renewal**:
   ```bash
   sudo certbot renew --dry-run
   ```

### Step 9: Setup Firewall

1. **Configure UFW**:
   ```bash
   sudo ufw default deny incoming
   sudo ufw default allow outgoing
   sudo ufw allow ssh
   sudo ufw allow 'Nginx Full'
   sudo ufw enable
   sudo ufw status
   ```

---

## Post-Deployment Configuration

### 1. Create Admin User

SSH into your server and run:
```bash
php artisan tinker
```

Then:
```php
$user = new App\Models\User();
$user->name = 'Admin';
$user->email = 'admin@example.com';
$user->password = bcrypt('secure_password');
$user->save();
```

### 2. Setup Backups

#### Automated Database Backups

1. **Create backup script**:
   ```bash
   sudo nano /usr/local/bin/backup-database.sh
   ```
   
   Add:
   ```bash
   #!/bin/bash
   DATE=$(date +%Y%m%d_%H%M%S)
   BACKUP_DIR="/home/deploy/backups"
   mkdir -p $BACKUP_DIR
   
   mysqldump -u primehub -p'your_password' primehub | gzip > $BACKUP_DIR/primehub_$DATE.sql.gz
   
   # Keep only last 7 days
   find $BACKUP_DIR -name "primehub_*.sql.gz" -mtime +7 -delete
   ```

2. **Make executable and schedule**:
   ```bash
   sudo chmod +x /usr/local/bin/backup-database.sh
   sudo crontab -e
   ```
   
   Add:
   ```cron
   0 2 * * * /usr/local/bin/backup-database.sh
   ```

#### DigitalOcean Snapshots

1. Go to your Droplet in DO Console
2. Click **Snapshots** tab
3. Enable **Weekly Backups** ($1.20/week for 2GB droplet)

### 3. Monitoring & Logging

#### Install monitoring tools:
```bash
# Install htop for system monitoring
sudo apt install -y htop

# Monitor Laravel logs
tail -f /var/www/primehub/storage/logs/laravel.log

# Monitor Nginx logs
tail -f /var/log/nginx/error.log
tail -f /var/log/nginx/access.log

# Monitor queue worker
tail -f /var/www/primehub/storage/logs/worker.log
```

#### Setup log rotation:
```bash
sudo nano /etc/logrotate.d/primehub
```

Add:
```
/var/www/primehub/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 deploy www-data
    sharedscripts
}
```

### 4. Performance Optimization

```bash
# Enable OPcache
sudo nano /etc/php/8.2/fpm/php.ini
```

Update these values:
```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
opcache.fast_shutdown=1
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.2-fpm
```

---

## CI/CD Setup

### GitHub Actions Deployment

Create `.github/workflows/deploy.yml`:

```yaml
name: Deploy to DigitalOcean

on:
  push:
    branches: [ main ]

jobs:
  deploy:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v3
    
    - name: Deploy to Server
      uses: appleboy/ssh-action@master
      with:
        host: ${{ secrets.DO_HOST }}
        username: ${{ secrets.DO_USERNAME }}
        key: ${{ secrets.DO_SSH_KEY }}
        script: |
          cd /var/www/primehub
          git pull origin main
          composer install --optimize-autoloader --no-dev
          npm ci
          php artisan wayfinder:generate --with-form
          npm run build
          php artisan migrate --force
          php artisan config:cache
          php artisan route:cache
          php artisan view:cache
          php artisan queue:restart
          sudo systemctl reload php8.2-fpm
```

Add these secrets in GitHub repository settings:
- `DO_HOST`: Your Droplet IP
- `DO_USERNAME`: `deploy`
- `DO_SSH_KEY`: Your private SSH key

---

## Troubleshooting

### 403 Forbidden Error (App Platform)

This error typically occurs when Apache isn't configured correctly.

1. **Ensure `apache.conf` exists** in your project root with proper configuration
2. **Verify `app.yaml` uses the Apache config**:
   ```yaml
   run_command: |
     ...
     heroku-php-apache2 -C apache.conf public/
   ```
3. **Check Runtime Logs** in DigitalOcean dashboard for specific errors
4. **Force redeploy** after making changes

### 500 Internal Server Error

1. **Check Laravel logs**:
   ```bash
   tail -f /var/www/primehub/storage/logs/laravel.log
   ```

2. **Check Nginx error logs**:
   ```bash
   sudo tail -f /var/log/nginx/error.log
   ```

3. **Verify permissions**:
   ```bash
   sudo chown -R deploy:www-data /var/www/primehub
   sudo chmod -R 775 /var/www/primehub/storage
   sudo chmod -R 775 /var/www/primehub/bootstrap/cache
   ```

4. **Clear caches**:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   php artisan view:clear
   php artisan route:clear
   ```

### Queue Jobs Not Processing

1. **Check queue worker status**:
   ```bash
   sudo systemctl status primehub-worker
   ```

2. **View worker logs**:
   ```bash
   tail -f /var/www/primehub/storage/logs/worker.log
   ```

3. **Restart worker**:
   ```bash
   sudo systemctl restart primehub-worker
   ```

4. **Check Redis connection**:
   ```bash
   redis-cli ping  # Should return PONG
   ```

### Database Connection Issues

1. **Test MySQL connection**:
   ```bash
   mysql -u primehub -p primehub
   ```

2. **Check MySQL status**:
   ```bash
   sudo systemctl status mysql
   ```

3. **Verify credentials in `.env`**

### High Memory Usage

1. **Monitor resources**:
   ```bash
   htop
   ```

2. **Optimize Redis**:
   ```bash
   redis-cli
   > CONFIG SET maxmemory 256mb
   > CONFIG SET maxmemory-policy allkeys-lru
   ```

3. **Clear application caches**:
   ```bash
   php artisan cache:clear
   redis-cli FLUSHDB
   ```

### Frontend Assets Not Loading

1. **Rebuild assets**:
   ```bash
   cd /var/www/primehub
   npm run build
   ```

2. **Check build output directory**:
   ```bash
   ls -la /var/www/primehub/public/build
   ```

3. **Verify APP_URL in `.env`** matches your domain

4. **Clear browser cache**

---

## Useful Commands

### Application Management
```bash
# Clear all caches
php artisan optimize:clear

# Rebuild caches
php artisan optimize

# Put app in maintenance mode
php artisan down

# Bring app back up
php artisan up

# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all
```

### Server Management
```bash
# Restart all services
sudo systemctl restart nginx php8.2-fpm mysql redis-server primehub-worker

# View system resource usage
htop

# Check disk space
df -h

# View active connections
sudo netstat -tulpn | grep LISTEN

# Monitor live logs
tail -f /var/www/primehub/storage/logs/laravel.log
```

### Git Deployment
```bash
# Pull latest changes
cd /var/www/primehub
git pull origin main

# Full deployment
composer install --optimize-autoloader --no-dev
npm ci
php artisan wayfinder:generate --with-form
npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
```

---

## Security Best Practices

1. **Keep system updated**:
   ```bash
   sudo apt update && sudo apt upgrade -y
   ```

2. **Use strong passwords** for database and user accounts

3. **Enable firewall** (UFW)

4. **Disable root SSH login**:
   ```bash
   sudo nano /etc/ssh/sshd_config
   # Set: PermitRootLogin no
   sudo systemctl restart sshd
   ```

5. **Setup fail2ban**:
   ```bash
   sudo apt install -y fail2ban
   sudo systemctl enable fail2ban
   sudo systemctl start fail2ban
   ```

6. **Regular backups** (database + files)

7. **Monitor logs** for suspicious activity

8. **Keep Laravel and dependencies updated**

---

## Additional Resources

- [DigitalOcean Documentation](https://docs.digitalocean.com/)
- [Laravel Deployment Documentation](https://laravel.com/docs/deployment)
- [Laravel Forge](https://forge.laravel.com/) - Managed Laravel hosting
- [DigitalOcean Marketplace - LEMP](https://marketplace.digitalocean.com/apps/lemp) - Pre-configured stack

---

## Support

For deployment issues:
1. Check application logs: `/var/www/primehub/storage/logs/laravel.log`
2. Check web server logs: `/var/log/nginx/error.log`
3. Verify all services are running: `sudo systemctl status nginx php8.2-fpm mysql redis-server`
4. Review this troubleshooting guide

**Need help?** Contact the development team or check DigitalOcean Community Tutorials.
