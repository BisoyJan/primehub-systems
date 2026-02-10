# Hostinger VPS Deployment

This guide automates provisioning and deployment for the Laravel + React (Vite, Inertia) app.

## Prerequisites
- Hostinger VPS with Ubuntu 22.04+.
- SSH access as a sudo-capable user.
- DNS A/AAAA records pointing `example.com` and `www.example.com` to the VPS.
- A MySQL/MariaDB database and user created (can be local on VPS). Update `.env` accordingly.

## One-time Provisioning
1. Copy scripts to server or pull repo.
2. Edit `deploy/provision.sh` and set `DOMAIN` and desired `APP_USER`.
3. Run:
   ```bash
   chmod +x deploy/provision.sh
   ./deploy/provision.sh
   ```

This installs Nginx, PHP 8.4 + extensions, Composer, Node.js LTS, pnpm, Redis, Supervisor, Certbot; configures firewall, Nginx site, Supervisor, cron, and PHP upload limits (10MB).

## Prepare .env
Create a production `.env` locally with values:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://<your-domain>`
- `ASSET_URL=https://<your-domain>` (optional)
- `SESSION_DRIVER=file|redis`
- `CACHE_DRIVER=file|redis`
- `QUEUE_CONNECTION=redis|database|sync`
- `DB_CONNECTION=mysql`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` (if using Redis)
- Mail settings per `config/mail.php`

## Deploy
Run deploy script passing repo, branch, env path, and domain:

```bash
chmod +x deploy/deploy.sh
./deploy/deploy.sh git@github.com:BisoyJan/primehub-systems.git main /path/to/prod.env yourdomain.com
```

Actions: clones/pulls repo into `/var/www/primehub-systems`, installs Composer deps, sets app key, runs migrations, caches config/routes, installs Node deps and builds assets, sets permissions, configures SSL with Certbot, restarts services, and starts queue worker.

## SSL
The script uses Certbot with Nginx to obtain/renew SSL. Ensure DNS is correct and ports 80/443 are open.

## Supervisor & Cron
- Queue: managed via Supervisor (`primehub-queue`). Logs in `/var/log/supervisor/primehub-queue.log`.
- Scheduler: cron entry runs `php artisan schedule:run` every minute.

## Nginx Config
Template available in `deploy/nginx.conf`. The provision script writes a similar config to `/etc/nginx/sites-available/primehub-systems.conf`.

## Troubleshooting
- Nginx: `sudo nginx -t && sudo systemctl status nginx`
- PHP-FPM: `sudo systemctl status php8.4-fpm`
- App logs: `storage/logs/laravel.log`
- Permissions: ensure `storage/` and `bootstrap/cache` are writable by `www-data`.
- Vite build: confirm `npm run build` outputs to `public/build/` and is referenced by Blade/Inertia (default Laravel Vite).
- **File Upload Issues**: If medical certificates or large files fail to upload, increase PHP limits:
  ```bash
  sudo chmod +x deploy/configure-php-limits.sh
  sudo ./deploy/configure-php-limits.sh
  ```

## Updating
Redeploy by re-running `deploy/deploy.sh` with the same parameters after pushing changes.
