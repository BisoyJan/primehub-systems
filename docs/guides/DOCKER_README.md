# Docker Setup - Quick Reference

## 🚀 Quick Start (Windows)

### Option 1: Automated Setup (Recommended)
```bash
# Run the setup script
./docker-setup.bat
```

### Option 2: Manual Setup
```bash
# 1. Copy environment file
cp .env.docker .env

# 2. Build and start containers
docker-compose up -d

# 3. Install dependencies
docker-compose exec app composer install
docker-compose exec node npm install

# 4. Generate key and migrate
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate
```

### Option 3: Using Make (if you have Make installed)
```bash
make setup
```

## 📦 What's Included

The Docker setup includes:

- **PHP 8.2 with FPM** - Laravel application server
- **Nginx** - Web server (port 80)
- **MySQL 8.0** - Database (port 3306)
- **Redis** - Cache and queue backend (port 6379)
- **Node.js 20** - Vite dev server with HMR (port 5173)
- **Queue Worker** - Background job processing

## 🌐 Access Points

After setup, access your application at:

- **Application**: http://localhost
- **Vite Dev**: http://localhost:5173 (auto-proxied through Nginx)
- **MySQL**: localhost:3306
  - Database: `primehub`
  - User: `primehub_user`
  - Password: `secret`
- **Redis**: localhost:6379

## ⚡ Common Commands

### Container Management
```bash
docker-compose ps              # View running containers
docker-compose logs -f         # View all logs
docker-compose logs -f app     # View app logs only
docker-compose restart         # Restart all services
docker-compose down            # Stop all services
```

### Laravel Commands
```bash
docker-compose exec app php artisan migrate
docker-compose exec app php artisan db:seed
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan queue:work
docker-compose exec app php artisan test
```

### Frontend Development
```bash
docker-compose exec node npm install
docker-compose exec node npm run build
docker-compose exec node npm run dev
```

### Database Access
```bash
# MySQL shell
docker-compose exec db mysql -u primehub_user -psecret primehub

# Create backup
docker-compose exec db mysqldump -u primehub_user -psecret primehub > backup.sql

# Restore backup
docker-compose exec -T db mysql -u primehub_user -psecret primehub < backup.sql
```

### Container Shell Access
```bash
docker-compose exec app bash     # PHP container
docker-compose exec node sh      # Node container
docker-compose exec db bash      # MySQL container
```

## 🛠️ Troubleshooting

### Ports Already in Use
Edit `docker-compose.yml` and change the port mappings:
```yaml
nginx:
  ports:
    - "8080:80"  # Change from 80 to 8080
```

### Permission Errors
```bash
docker-compose exec app chown -R www-data:www-data /var/www
docker-compose exec app chmod -R 775 /var/www/storage /var/www/bootstrap/cache
```

### Clear All Caches
```bash
docker-compose exec app php artisan optimize:clear
```

### Rebuild Containers
```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### HMR Not Working
1. Make sure you're accessing via http://localhost (not localhost:5173)
2. Check Vite logs: `docker-compose logs -f node`
3. Clear browser cache

## 📚 Documentation

For detailed documentation, see:
- **[DOCKER_SETUP.md](./DOCKER_SETUP.md)** - Complete Docker setup guide
- **[REFACTORING_GUIDE.md](./REFACTORING_GUIDE.md)** - Project refactoring guide

## 🏗️ Project Structure

```
.
├── docker/
│   ├── nginx/
│   │   └── conf.d/
│   │       └── app.conf          # Nginx configuration
│   └── supervisor/
│       └── supervisord.conf      # Supervisor config (production)
├── docker-compose.yml            # Main Docker Compose file
├── docker-compose.sail.yml       # Laravel Sail alternative
├── Dockerfile                    # Development Dockerfile
├── Dockerfile.production         # Production Dockerfile
├── .dockerignore                 # Docker ignore file
├── .env.docker                   # Docker environment template
├── docker-setup.bat              # Windows setup script
├── docker-setup.sh               # Linux/Mac setup script
├── Makefile                      # Make commands
└── DOCKER_SETUP.md              # Detailed documentation
```

## 🚢 Production Deployment

For production, use:
```bash
docker-compose -f docker-compose.production.yml up -d
```

See [DOCKER_SETUP.md](./DOCKER_SETUP.md) for production configuration details.

## 💡 Tips

1. **Start fresh**: `docker-compose down -v && docker-compose up -d`
2. **View specific logs**: `docker-compose logs -f [service-name]`
3. **Run commands without entering shell**: `docker-compose exec -T app [command]`
4. **Use Make commands**: `make help` to see all available commands
5. **Monitor resources**: `docker stats` to see container resource usage

## 🆘 Need Help?

1. Check logs: `docker-compose logs -f`
2. Verify containers: `docker-compose ps`
3. Check Laravel logs: `docker-compose exec app cat storage/logs/laravel.log`
4. Read full documentation: [DOCKER_SETUP.md](./DOCKER_SETUP.md)
