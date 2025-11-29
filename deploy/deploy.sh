#!/usr/bin/env bash
set -euo pipefail

# Deploy script for primehub-systems on Hostinger VPS
# Usage: ./deploy.sh <git_repo_url> <branch> <env_file_path> <domain>

REPO_URL=${1:-""}
BRANCH=${2:-"main"}
ENV_SRC=${3:-""}
DOMAIN=${4:-"example.com"}

APP_USER="primehub"
APP_DIR="/var/www/primehub-systems"
PHP_VERSION="8.4"

if [[ -z "$REPO_URL" || -z "$ENV_SRC" ]]; then
  echo "Usage: $0 <git_repo_url> <branch> <env_file_path> <domain>"
  exit 1
fi

echo "[Deploy] Ensure directory ownership"
sudo mkdir -p ${APP_DIR}
sudo chown -R ${APP_USER}:www-data ${APP_DIR}
sudo chmod -R 775 ${APP_DIR}

echo "[Deploy] Clone or fetch repository"
if [[ ! -d "${APP_DIR}/.git" ]]; then
  sudo -u ${APP_USER} git clone --branch ${BRANCH} ${REPO_URL} ${APP_DIR}
else
  pushd ${APP_DIR}
  sudo -u ${APP_USER} git fetch --all
  sudo -u ${APP_USER} git checkout ${BRANCH}
  sudo -u ${APP_USER} git pull origin ${BRANCH}
  popd
fi

echo "[Deploy] Copy .env"
sudo cp "$ENV_SRC" ${APP_DIR}/.env
sudo chown ${APP_USER}:www-data ${APP_DIR}/.env
sudo chmod 640 ${APP_DIR}/.env

echo "[Deploy] Composer install"
pushd ${APP_DIR}
sudo -u ${APP_USER} composer install --no-dev --optimize-autoloader

echo "[Deploy] Generate Laravel app key if missing"
if ! sudo -u ${APP_USER} grep -q "^APP_KEY=base64:" .env; then
  sudo -u ${APP_USER} php artisan key:generate
fi

echo "[Deploy] Migrate and seed (if needed)"
sudo -u ${APP_USER} php artisan migrate --force

echo "[Deploy] Cache config and routes"
sudo -u ${APP_USER} php artisan config:clear
sudo -u ${APP_USER} php artisan route:clear
sudo -u ${APP_USER} php artisan view:clear
sudo -u ${APP_USER} php artisan config:cache
sudo -u ${APP_USER} php artisan route:cache

echo "[Deploy] Node install and build"
sudo -u ${APP_USER} pnpm i || sudo -u ${APP_USER} npm i
sudo -u ${APP_USER} pnpm run build || sudo -u ${APP_USER} npm run build

echo "[Deploy] Set storage permissions"
sudo chown -R ${APP_USER}:www-data ${APP_DIR}/storage ${APP_DIR}/bootstrap/cache
sudo chmod -R 775 ${APP_DIR}/storage ${APP_DIR}/bootstrap/cache

echo "[Deploy] Restart PHP-FPM and Nginx"
sudo systemctl restart php${PHP_VERSION}-fpm
sudo systemctl reload nginx

echo "[Deploy] Obtain/renew SSL certificates"
sudo certbot --nginx -d ${DOMAIN} -d www.${DOMAIN} --non-interactive --agree-tos -m admin@${DOMAIN} || true

echo "[Deploy] Restart Supervisor queue"
sudo supervisorctl restart primehub-queue || sudo supervisorctl start primehub-queue

echo "[Deploy] Done"
popd || true
