#!/bin/bash

#######################################################################
# PrimeHub Systems - Automated VPS Setup Script
#
# This script automates the complete server setup for a Laravel + React
# application on Ubuntu 24.04 LTS (Hostinger VPS or similar).
#
# Usage:
#   1. SSH into your VPS as root
#   2. Upload this script or copy/paste it
#   3. Make it executable: chmod +x setup-vps.sh
#   4. Run it: ./setup-vps.sh
#
# What this script does:
#   - Updates system packages
#   - Installs Nginx, PHP 8.4, MySQL, Node.js, Redis, Supervisor
#   - Creates application user and directory
#   - Configures Nginx for your domain
#   - Sets up SSL with Let's Encrypt
#   - Configures queue workers and cron jobs
#
#######################################################################

set -e  # Exit on any error

# =============================================================================
# CONFIGURATION - EDIT THESE VALUES
# =============================================================================

DOMAIN="prmhubsystems.com"
APP_USER="primehub"
APP_DIR="/var/www/primehub-systems"
GITHUB_REPO="git@github.com:BisoyJan/primehub-systems.git"
GITHUB_BRANCH="main"

# Database Configuration
DB_NAME="primehub_systems"
DB_USER="primehub_user"
DB_PASS="Y8m'v'hMPXA,ZK)bGp(z"  # Change this to a secure password!

# Email for Let's Encrypt SSL
SSL_EMAIL="admin@primehubmail.com"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# =============================================================================
# HELPER FUNCTIONS
# =============================================================================

print_header() {
    echo ""
    echo -e "${BLUE}============================================================${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}============================================================${NC}"
    echo ""
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_info() {
    echo -e "${BLUE}→ $1${NC}"
}

# Check if running as root
check_root() {
    if [ "$EUID" -ne 0 ]; then
        print_error "This script must be run as root"
        exit 1
    fi
}

# =============================================================================
# MAIN SETUP FUNCTIONS
# =============================================================================

update_system() {
    print_header "Updating System Packages"
    apt update && apt upgrade -y
    print_success "System packages updated"
}

install_essentials() {
    print_header "Installing Essential Tools"
    apt install -y git curl wget ufw unzip software-properties-common \
        ca-certificates lsb-release apt-transport-https build-essential
    print_success "Essential tools installed"
}

configure_firewall() {
    print_header "Configuring Firewall (UFW)"
    ufw allow OpenSSH
    ufw allow 80/tcp
    ufw allow 443/tcp
    echo "y" | ufw enable
    print_success "Firewall configured"
}

install_nginx() {
    print_header "Installing Nginx"
    apt install -y nginx
    systemctl enable nginx
    systemctl start nginx
    print_success "Nginx installed and running"
}

install_php() {
    print_header "Installing PHP 8.4"
    add-apt-repository ppa:ondrej/php -y
    apt update
    apt install -y php8.4 php8.4-fpm php8.4-mbstring php8.4-xml php8.4-curl \
        php8.4-zip php8.4-mysql php8.4-gd php8.4-intl php8.4-bcmath php8.4-redis
    systemctl enable php8.4-fpm
    systemctl start php8.4-fpm
    print_success "PHP 8.4 installed"
}

install_mysql() {
    print_header "Installing MySQL"
    apt install -y mysql-server
    systemctl enable mysql
    systemctl start mysql

    # Create database and user
    print_info "Creating database and user..."

    # Escape single quotes in password for MySQL
    ESCAPED_PASS=$(echo "$DB_PASS" | sed "s/'/''/g")

    mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${ESCAPED_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

    print_success "MySQL installed and database created"
}

install_composer() {
    print_header "Installing Composer"
    curl -sS https://getcomposer.org/installer -o composer-setup.php
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm composer-setup.php
    print_success "Composer installed"
}

install_nodejs() {
    print_header "Installing Node.js & pnpm"
    curl -fsSL https://deb.nodesource.com/setup_lts.x | bash -
    apt install -y nodejs
    npm install -g pnpm
    print_success "Node.js and pnpm installed"
}

install_redis() {
    print_header "Installing Redis"
    apt install -y redis-server
    systemctl enable redis-server
    systemctl start redis-server
    print_success "Redis installed"
}

install_supervisor() {
    print_header "Installing Supervisor"
    apt install -y supervisor
    systemctl enable supervisor
    systemctl start supervisor
    print_success "Supervisor installed"
}

create_app_user() {
    print_header "Creating Application User"

    if id "$APP_USER" &>/dev/null; then
        print_warning "User $APP_USER already exists, skipping..."
    else
        adduser --disabled-password --gecos "" $APP_USER
        usermod -a -G www-data $APP_USER
        print_success "User $APP_USER created"
    fi

    # Create app directory
    mkdir -p $APP_DIR
    chown -R $APP_USER:www-data $APP_DIR
    chmod -R 775 $APP_DIR
    print_success "Application directory created"
}

setup_github_ssh() {
    print_header "Setting up GitHub SSH Key"

    SSH_DIR="/home/$APP_USER/.ssh"

    if [ -f "$SSH_DIR/id_ed25519" ]; then
        print_warning "SSH key already exists"
    else
        sudo -u $APP_USER mkdir -p $SSH_DIR
        sudo -u $APP_USER ssh-keygen -t ed25519 -C "$SSL_EMAIL" -f "$SSH_DIR/id_ed25519" -N ""
        chmod 700 $SSH_DIR
        chmod 600 "$SSH_DIR/id_ed25519"
        chmod 644 "$SSH_DIR/id_ed25519.pub"
    fi

    echo ""
    print_warning "Add this SSH key to your GitHub account:"
    echo ""
    cat "$SSH_DIR/id_ed25519.pub"
    echo ""
    print_info "Go to: GitHub → Settings → SSH and GPG keys → New SSH key"
    echo ""
    read -p "Press Enter after you've added the key to GitHub..."

    # Add GitHub to known hosts
    sudo -u $APP_USER ssh-keyscan -t ed25519 github.com >> "$SSH_DIR/known_hosts" 2>/dev/null

    print_success "GitHub SSH key configured"
}

clone_repository() {
    print_header "Cloning Repository"

    if [ -d "$APP_DIR/.git" ]; then
        print_warning "Repository already exists, pulling latest..."
        cd $APP_DIR
        sudo -u $APP_USER git pull origin $GITHUB_BRANCH
    else
        cd $APP_DIR
        sudo -u $APP_USER git clone $GITHUB_REPO .
    fi

    # Add safe directory for git
    git config --global --add safe.directory $APP_DIR

    print_success "Repository cloned"
}

create_env_file() {
    print_header "Creating Environment File"

    if [ -f "$APP_DIR/.env" ]; then
        print_warning ".env file already exists, skipping..."
        return
    fi

    # Generate a new APP_KEY
    APP_KEY=$(openssl rand -base64 32)

    cat > "$APP_DIR/.env" <<EOF
APP_NAME=PrimeHub
APP_ENV=production
APP_KEY=base64:${APP_KEY}
APP_DEBUG=false
APP_URL=https://${DOMAIN}
ASSET_URL=https://${DOMAIN}

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

PHP_CLI_SERVER_WORKERS=4

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=${DOMAIN}
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

INACTIVITY_TIMEOUT=60

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=file

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_FROM_ADDRESS=noreply@${DOMAIN}
MAIL_FROM_NAME="\${APP_NAME}"

VITE_APP_NAME="\${APP_NAME}"
EOF

    chown $APP_USER:www-data "$APP_DIR/.env"
    chmod 640 "$APP_DIR/.env"

    print_success "Environment file created"
    print_warning "Remember to update mail settings in .env!"
}

install_dependencies() {
    print_header "Installing Dependencies"

    cd $APP_DIR

    # PHP dependencies
    print_info "Installing PHP dependencies..."
    sudo -u $APP_USER composer install --no-dev --optimize-autoloader

    # Node dependencies
    print_info "Installing Node.js dependencies..."
    sudo -u $APP_USER pnpm install

    # Build frontend
    print_info "Building frontend..."
    sudo -u $APP_USER pnpm run build

    print_success "Dependencies installed and frontend built"
}

run_migrations() {
    print_header "Running Database Migrations"

    cd $APP_DIR
    sudo -u $APP_USER php artisan migrate --force

    print_success "Migrations completed"
}

configure_laravel() {
    print_header "Configuring Laravel"

    cd $APP_DIR

    # Clear and cache
    sudo -u $APP_USER php artisan config:clear
    sudo -u $APP_USER php artisan route:clear
    sudo -u $APP_USER php artisan view:clear
    sudo -u $APP_USER php artisan config:cache
    sudo -u $APP_USER php artisan route:cache

    # Storage link
    sudo -u $APP_USER php artisan storage:link 2>/dev/null || true

    # Set permissions
    chown -R $APP_USER:www-data $APP_DIR/storage
    chown -R $APP_USER:www-data $APP_DIR/bootstrap/cache
    chmod -R 775 $APP_DIR/storage
    chmod -R 775 $APP_DIR/bootstrap/cache

    print_success "Laravel configured"
}

configure_nginx() {
    print_header "Configuring Nginx"

    NGINX_CONF="/etc/nginx/sites-available/primehub-systems.conf"

    cat > $NGINX_CONF <<EOF
server {
    listen 80;
    server_name ${DOMAIN} www.${DOMAIN};

    root ${APP_DIR}/public;
    index index.php index.html;

    access_log /var/log/nginx/primehub_access.log;
    error_log  /var/log/nginx/primehub_error.log;

    client_max_body_size 50M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        try_files \$uri =404;
        expires 7d;
        add_header Cache-Control "public, no-transform";
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
    }

    location ~ /\.ht {
        deny all;
    }

    location ~ /\.git {
        deny all;
    }
}
EOF

    # Enable site
    ln -sf $NGINX_CONF /etc/nginx/sites-enabled/
    rm -f /etc/nginx/sites-enabled/default

    # Test and restart
    nginx -t
    systemctl restart nginx

    print_success "Nginx configured"
}

setup_ssl() {
    print_header "Setting up SSL with Let's Encrypt"

    # Check if domain resolves to this server
    SERVER_IP=$(curl -s ifconfig.me)
    DOMAIN_IP=$(dig +short $DOMAIN | head -1)

    if [ "$SERVER_IP" != "$DOMAIN_IP" ]; then
        print_warning "Domain $DOMAIN does not point to this server ($SERVER_IP)"
        print_warning "Domain resolves to: $DOMAIN_IP"
        print_warning "Skipping SSL setup. Run this later:"
        echo ""
        echo "  certbot --nginx -d $DOMAIN -d www.$DOMAIN"
        echo ""
        return
    fi

    # Install certbot
    snap install core 2>/dev/null || true
    snap refresh core 2>/dev/null || true
    snap install --classic certbot
    ln -sf /snap/bin/certbot /usr/bin/certbot

    # Get certificate
    certbot --nginx -d $DOMAIN -d www.$DOMAIN --non-interactive --agree-tos -m $SSL_EMAIL

    print_success "SSL certificate installed"
}

configure_supervisor() {
    print_header "Configuring Supervisor for Queue Workers"

    cat > /etc/supervisor/conf.d/primehub-queue.conf <<EOF
[program:primehub-queue]
command=/usr/bin/php ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
user=${APP_USER}
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/primehub-queue.log
stopwaitsecs=3600
EOF

    supervisorctl reread
    supervisorctl update
    supervisorctl start primehub-queue 2>/dev/null || true

    print_success "Supervisor configured"
}

configure_cron() {
    print_header "Configuring Laravel Scheduler (Cron)"

    CRON_JOB="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"

    # Add cron job if it doesn't exist
    (crontab -u $APP_USER -l 2>/dev/null | grep -v "schedule:run"; echo "$CRON_JOB") | crontab -u $APP_USER -

    print_success "Cron job configured"
}

restart_services() {
    print_header "Restarting All Services"

    systemctl restart php8.4-fpm
    systemctl restart nginx
    systemctl restart supervisor
    systemctl restart redis-server

    print_success "All services restarted"
}

print_summary() {
    print_header "🎉 Setup Complete!"

    echo ""
    echo -e "${GREEN}Your PrimeHub Systems application is now deployed!${NC}"
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    echo -e "  ${BLUE}Website:${NC}     https://${DOMAIN}"
    echo -e "  ${BLUE}App User:${NC}    ${APP_USER}"
    echo -e "  ${BLUE}App Dir:${NC}     ${APP_DIR}"
    echo -e "  ${BLUE}Database:${NC}    ${DB_NAME}"
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo ""
    echo -e "${YELLOW}Next Steps:${NC}"
    echo "  1. Update .env with your mail settings"
    echo "  2. Run: php artisan db:seed (if you want sample data)"
    echo "  3. Create admin user: php artisan tinker"
    echo ""
    echo -e "${YELLOW}Useful Commands:${NC}"
    echo "  - View Laravel logs: tail -f ${APP_DIR}/storage/logs/laravel.log"
    echo "  - View Nginx logs:   tail -f /var/log/nginx/primehub_error.log"
    echo "  - Restart queue:     supervisorctl restart primehub-queue"
    echo ""
}

# =============================================================================
# MAIN EXECUTION
# =============================================================================

main() {
    clear
    echo ""
    echo -e "${BLUE}╔═══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║                                                           ║${NC}"
    echo -e "${BLUE}║     PrimeHub Systems - Automated VPS Setup Script         ║${NC}"
    echo -e "${BLUE}║                                                           ║${NC}"
    echo -e "${BLUE}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""

    check_root

    echo -e "${YELLOW}This script will set up your VPS with:${NC}"
    echo "  • Nginx, PHP 8.4, MySQL, Redis, Supervisor"
    echo "  • Node.js, pnpm, Composer"
    echo "  • Your Laravel application from GitHub"
    echo "  • SSL certificate from Let's Encrypt"
    echo ""
    echo -e "${YELLOW}Configuration:${NC}"
    echo "  • Domain: ${DOMAIN}"
    echo "  • GitHub: ${GITHUB_REPO}"
    echo "  • Database: ${DB_NAME}"
    echo ""

    read -p "Continue with setup? (y/n): " -n 1 -r
    echo ""

    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Setup cancelled."
        exit 0
    fi

    # Run all setup steps
    update_system
    install_essentials
    configure_firewall
    install_nginx
    install_php
    install_mysql
    install_composer
    install_nodejs
    install_redis
    install_supervisor
    create_app_user
    setup_github_ssh
    clone_repository
    create_env_file
    install_dependencies
    run_migrations
    configure_laravel
    configure_nginx
    setup_ssl
    configure_supervisor
    configure_cron
    restart_services

    print_summary
}

# Run main function
main "$@"
