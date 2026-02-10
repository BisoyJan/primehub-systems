#!/usr/bin/env bash
set -euo pipefail

# Provision Hostinger Ubuntu VPS for Laravel + React (Vite) app
# Idempotent where possible. Requires sudo.

APP_USER="primehub"
APP_DIR="/var/www/primehub-systems"
DOMAIN="primehubmanagement-system.com"
PHP_VERSION="8.4"

echo "[Provision] Updating packages"
sudo apt-get update -y && sudo apt-get upgrade -y

echo "[Provision] Install base tools"
sudo apt-get install -y git curl ufw unzip software-properties-common ca-certificates lsb-release apt-transport-https \
  build-essential

echo "[Provision] Setup firewall (UFW)"
sudo ufw allow OpenSSH || true
sudo ufw allow 80/tcp || true
sudo ufw allow 443/tcp || true
echo "y" | sudo ufw enable || true

echo "[Provision] Install Nginx"
sudo apt-get install -y nginx
sudo systemctl enable nginx

echo "[Provision] Install PHP and extensions"
sudo add-apt-repository ppa:ondrej/php -y
sudo apt-get update -y
sudo apt-get install -y php${PHP_VERSION} php${PHP_VERSION}-fpm php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml \
  php${PHP_VERSION}-curl php${PHP_VERSION}-zip php${PHP_VERSION}-mysql php${PHP_VERSION}-gd php${PHP_VERSION}-intl
sudo systemctl enable php${PHP_VERSION}-fpm

echo "[Provision] Configure PHP upload limits"
PHP_INI="/etc/php/${PHP_VERSION}/fpm/php.ini"
sudo sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 10M/' ${PHP_INI}
sudo sed -i 's/^post_max_size = .*/post_max_size = 10M/' ${PHP_INI}
sudo systemctl restart php${PHP_VERSION}-fpm

echo "[Provision] Install Composer"
if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer -o composer-setup.php
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm composer-setup.php
fi

echo "[Provision] Install Node.js LTS"
if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
  sudo apt-get install -y nodejs
fi
sudo npm install -g pnpm@latest

echo "[Provision] Install Redis and Supervisor"
sudo apt-get install -y redis-server supervisor
sudo systemctl enable redis-server
sudo systemctl enable supervisor

echo "[Provision] Install Certbot (Let's Encrypt)"
sudo snap install core || true
sudo snap refresh core || true
sudo snap install --classic certbot || true
sudo ln -sf /snap/bin/certbot /usr/bin/certbot || true

echo "[Provision] Create deploy user and directory"
if ! id -u ${APP_USER} >/dev/null 2>&1; then
  sudo adduser --disabled-password --gecos "" ${APP_USER}
fi
sudo usermod -a -G www-data ${APP_USER}
sudo mkdir -p ${APP_DIR}
sudo chown -R ${APP_USER}:www-data ${APP_DIR}
sudo chmod -R 775 ${APP_DIR}

echo "[Provision] Configure Nginx site"
NGINX_CONF="/etc/nginx/sites-available/primehub-systems.conf"
sudo tee ${NGINX_CONF} >/dev/null <<'EOF'
server {
    listen 80;
    server_name primehubmanagement-system.com www.primehubmanagement-system.com;

    root /var/www/primehub-systems/public;
    index index.php index.html;

    access_log /var/log/nginx/primehub_access.log;
    error_log  /var/log/nginx/primehub_error.log;

    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
        try_files $uri =404;
        expires 7d;
        add_header Cache-Control "public, no-transform";
    }

    location ~ \.(php)$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.ht { deny all; }
}
EOF
sudo ln -sf ${NGINX_CONF} /etc/nginx/sites-enabled/primehub-systems.conf
sudo nginx -t
sudo systemctl restart nginx

echo "[Provision] Configure Supervisor for Laravel queue"
SUPERVISOR_CONF="/etc/supervisor/conf.d/primehub-queue.conf"
sudo tee ${SUPERVISOR_CONF} >/dev/null <<EOF
[program:primehub-queue]
command=/usr/bin/php /var/www/primehub-systems/artisan queue:work --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
user=${APP_USER}
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/primehub-queue.log
stopwaitsecs=3600
EOF
sudo supervisorctl reread
sudo supervisorctl update

echo "[Provision] Setup cron for Laravel schedule"
CRON_LINE="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
(sudo crontab -u ${APP_USER} -l 2>/dev/null; echo "$CRON_LINE") | sudo crontab -u ${APP_USER} -

echo "[Provision] Done. Next: deploy.sh to push code and configure .env"
