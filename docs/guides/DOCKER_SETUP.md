# Docker Setup Guide for PrimeHub Systems

This guide will help you set up and run the PrimeHub Systems Laravel + React application using Docker.

## Prerequisites

- Docker Desktop installed (Windows/Mac) or Docker Engine (Linux)
- Docker Compose installed (usually included with Docker Desktop)
- Git (optional, for version control)

## Quick Start

### 1. Initial Setup

First, copy the Docker environment file:

```bash
cp .env.docker .env
```

### 2. Generate Application Key

```bash
docker-compose run --rm app php artisan key:generate
```

### 3. Start Docker Containers

```bash
docker-compose up -d
```

This will start all services:
- **app**: PHP-FPM application server
- **nginx**: Web server (accessible at http://localhost)
- **db**: MySQL database
- **redis**: Redis for caching and queues
- **node**: Vite dev server for hot module reloading
- **queue**: Laravel queue worker

### 4. Install Dependencies

Install PHP dependencies:
```bash
docker-compose exec app composer install
```

Install Node dependencies:
```bash
docker-compose exec node npm install
```

### 5. Run Database Migrations

```bash
docker-compose exec app php artisan migrate
```

### 6. Seed Database (Optional)

```bash
docker-compose exec app php artisan db:seed
```

### 7. Set Permissions

```bash
docker-compose exec app chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
docker-compose exec app chmod -R 775 /var/www/storage /var/www/bootstrap/cache
```

### 8. Access the Application

- **Frontend**: http://localhost
- **Vite Dev Server**: http://localhost:5173
- **MySQL**: localhost:3306
- **Redis**: localhost:6379

## Common Commands

### View Running Containers
```bash
docker-compose ps
```

### View Logs
```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f app
docker-compose logs -f nginx
docker-compose logs -f queue
```

### Execute Artisan Commands
```bash
docker-compose exec app php artisan [command]

# Examples:
docker-compose exec app php artisan migrate
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan route:list
```

### Access Container Shell
```bash
# PHP container
docker-compose exec app bash

# MySQL container
docker-compose exec db mysql -u primehub_user -psecret primehub

# Node container
docker-compose exec node sh
```

### Rebuild Containers
```bash
# Rebuild all
docker-compose build

# Rebuild specific service
docker-compose build app

# Rebuild and restart
docker-compose up -d --build
```

### Stop Containers
```bash
# Stop all
docker-compose stop

# Stop specific service
docker-compose stop app
```

### Remove Containers
```bash
# Stop and remove all containers
docker-compose down

# Remove containers and volumes (WARNING: deletes database data)
docker-compose down -v
```

### Restart Services
```bash
# Restart all
docker-compose restart

# Restart specific service
docker-compose restart app
docker-compose restart queue
```

## Development Workflow

### Hot Module Reloading (HMR)

The Vite dev server runs in the `node` container and provides hot module reloading:

1. Make changes to your React files in `resources/js/`
2. Changes will automatically reflect in the browser
3. If HMR doesn't work, check Vite logs: `docker-compose logs -f node`

### Building for Production

```bash
# Build frontend assets
docker-compose exec node npm run build

# Or rebuild the app container (includes build step)
docker-compose build app
```

### Running Tests

```bash
# PHP tests
docker-compose exec app php artisan test

# Or using PHPUnit directly
docker-compose exec app vendor/bin/phpunit
```

### Queue Management

The queue worker runs automatically in the `queue` container. To monitor or restart:

```bash
# View queue worker logs
docker-compose logs -f queue

# Restart queue worker
docker-compose restart queue

# Manually run queue worker
docker-compose exec app php artisan queue:work --tries=3
```

## Troubleshooting

### Port Already in Use

If you get port conflicts:

1. Check what's using the port:
   ```bash
   # Windows (PowerShell)
   netstat -ano | findstr :80
   
   # Linux/Mac
   lsof -i :80
   ```

2. Change ports in `docker-compose.yml`:
   ```yaml
   nginx:
     ports:
       - "8080:80"  # Change 80 to 8080
   ```

### Permission Issues

If you encounter permission errors:

```bash
docker-compose exec app chown -R www-data:www-data /var/www
docker-compose exec app chmod -R 775 /var/www/storage /var/www/bootstrap/cache
```

### Database Connection Issues

1. Ensure database container is running:
   ```bash
   docker-compose ps db
   ```

2. Check database logs:
   ```bash
   docker-compose logs db
   ```

3. Verify `.env` configuration:
   ```env
   DB_HOST=db
   DB_PORT=3306
   DB_DATABASE=primehub
   DB_USERNAME=primehub_user
   DB_PASSWORD=secret
   ```

### Vite/HMR Not Working

1. Update your `.env` file:
   ```env
   VITE_HOST=0.0.0.0
   VITE_PORT=5173
   ```

2. Access via `http://localhost` (not `http://localhost:5173`)

3. Check node container logs:
   ```bash
   docker-compose logs -f node
   ```

### Clearing All Caches

```bash
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan route:clear
docker-compose exec app php artisan view:clear
```

## Production Deployment

For production, you should:

1. **Use a different Dockerfile** optimized for production (no dev dependencies)
2. **Build assets** before deploying
3. **Use environment variables** for sensitive data
4. **Enable HTTPS** with proper SSL certificates
5. **Use managed database services** instead of containerized MySQL
6. **Set up proper logging** and monitoring
7. **Configure health checks** and auto-restart policies

Example production `.env` changes:
```env
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

## Database Backups

### Create Backup
```bash
docker-compose exec db mysqldump -u primehub_user -psecret primehub > backup_$(date +%Y%m%d_%H%M%S).sql
```

### Restore Backup
```bash
docker-compose exec -T db mysql -u primehub_user -psecret primehub < backup_20250101_120000.sql
```

## Advanced Configuration

### Custom PHP Configuration

Create `docker/php/php.ini`:
```ini
upload_max_filesize = 100M
post_max_size = 100M
memory_limit = 256M
```

Add to `docker-compose.yml`:
```yaml
app:
  volumes:
    - ./docker/php/php.ini:/usr/local/etc/php/conf.d/custom.ini
```

### Adding SSL/HTTPS

1. Generate certificates (for development):
   ```bash
   mkdir -p docker/nginx/ssl
   openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
     -keyout docker/nginx/ssl/nginx.key \
     -out docker/nginx/ssl/nginx.crt
   ```

2. Update nginx configuration in `docker/nginx/conf.d/app.conf`

## Support

For issues or questions:
1. Check Docker logs: `docker-compose logs -f`
2. Verify all containers are running: `docker-compose ps`
3. Check Laravel logs: `storage/logs/laravel.log`

## Cleanup

To completely remove all Docker resources for this project:

```bash
# Stop and remove containers
docker-compose down

# Remove volumes (WARNING: deletes all data)
docker-compose down -v

# Remove images
docker-compose down --rmi all
```
