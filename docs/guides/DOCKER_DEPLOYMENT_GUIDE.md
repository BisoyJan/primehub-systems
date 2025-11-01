# Docker Deployment Guide - PrimeHub Systems

## 🎯 Recommended Workflow for Multiple Computers

This guide explains the **recommended approach** for running PrimeHub Systems on multiple computers using Docker.

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Initial Setup (First Computer)](#initial-setup-first-computer)
4. [Setup on Additional Computers](#setup-on-additional-computers)
5. [Keeping Computers in Sync](#keeping-computers-in-sync)
6. [Transferring Database Data](#transferring-database-data)
7. [Alternative: Using Docker Hub](#alternative-using-docker-hub)
8. [Troubleshooting](#troubleshooting)

---

## Overview

### The Simple Approach (Recommended)

**You DON'T need to push Docker images to Docker Hub!**

Instead, use this workflow:
1. Keep your code in GitHub
2. Clone the repository on any computer
3. Run the automated setup script
4. Docker builds everything automatically

### Why This Works Best

✅ **Simpler** - One command does everything  
✅ **Always Up-to-Date** - Builds from latest code  
✅ **No Extra Services** - No Docker Hub account needed  
✅ **Faster Updates** - Just pull code and rebuild  
✅ **Less Maintenance** - No manual image management  

---

## Prerequisites

### Required Software

- **Docker Desktop** (Windows/Mac) or Docker Engine (Linux)
  - Download: https://www.docker.com/products/docker-desktop
  - Must be installed and running
- **Git**
  - Download: https://git-scm.com/downloads
- **GitHub Account**
  - Your code repository

### System Requirements

- **RAM**: Minimum 8GB (16GB recommended)
- **Disk Space**: At least 10GB free
- **Internet**: Required for first setup

---

## Initial Setup (First Computer)

### Step 1: Verify Docker Installation

```bash
# Check Docker is running
docker --version

# Should output: Docker version 24.x.x or higher
```

### Step 2: Push Your Code to GitHub

If you haven't already:

```bash
# Navigate to project directory
cd c:/Users/bisoy/Desktop/Projects/primehub-systems

# Add all files
git add .

# Commit changes
git commit -m "Initial Docker setup"

# Push to GitHub
git push origin main
```

### Step 3: Verify Everything Works

```bash
# Make sure everything runs on your current PC
docker-compose ps

# Should show all containers running:
# - primehub-app
# - primehub-nginx
# - primehub-db
# - primehub-redis
# - primehub-node
# - primehub-queue
```

---

## Setup on Additional Computers

Follow these steps on **any new computer** where you want to run the application.

### Step 1: Install Docker Desktop

1. Download Docker Desktop from https://www.docker.com/products/docker-desktop
2. Install and launch Docker Desktop
3. Wait for Docker to fully start (whale icon in system tray should be steady)

### Step 2: Clone the Repository

```bash
# Navigate to your projects folder
cd ~/Desktop/Projects

# Clone the repository
git clone https://github.com/BisoyJan/primehub-systems.git

# Enter the project directory
cd primehub-systems
```

### Step 3: Run Automated Setup

**On Windows:**
```bash
./docker-setup.bat
```

**On Linux/Mac:**
```bash
chmod +x docker-setup.sh
./docker-setup.sh
```

**Using Make:**
```bash
make setup
```

### Step 4: Wait for Setup to Complete

The setup script will automatically:

1. ✅ Create `.env` file from `.env.docker`
2. ✅ Build Docker containers (10-15 minutes first time)
3. ✅ Start all services
4. ✅ Install PHP dependencies (Composer)
5. ✅ Install Node dependencies (npm)
6. ✅ Generate application key
7. ✅ Run database migrations
8. ✅ Set proper permissions
9. ✅ Build frontend assets

**Expected Time:**
- **First Setup**: 10-15 minutes (downloads base images)
- **Subsequent Setups**: 5-10 minutes (images cached)

### Step 5: Access Your Application

Once setup completes, open your browser:

- 🌐 **Application**: http://localhost
- ⚡ **Vite Dev Server**: http://localhost:5173
- 🗄️ **MySQL**: localhost:3307
- 📦 **Redis**: localhost:6379

### Step 6: Create Initial User (if needed)

```bash
# Access the app container
docker-compose exec app bash

# Create an admin user (if using seeders)
php artisan db:seed --class=UserSeeder

# Or create manually via Tinker
php artisan tinker
>>> User::create([
    'name' => 'Admin',
    'email' => 'admin@example.com',
    'password' => bcrypt('password')
]);

# Exit container
exit
```

---

## Keeping Computers in Sync

### Scenario: You Made Changes on Computer 1

#### On Computer 1 (After Making Changes):

```bash
# Save your changes
git add .
git commit -m "Added new feature"
git push origin main
```

#### On Computer 2 (To Get Latest Changes):

```bash
# Navigate to project
cd ~/Desktop/Projects/primehub-systems

# Pull latest code
git pull origin main

# Rebuild and restart containers
docker-compose down
docker-compose up -d --build

# If there are new migrations
docker-compose exec app php artisan migrate

# Regenerate Wayfinder types (if routes changed)
docker-compose exec app php artisan wayfinder:generate --with-form

# If there are new dependencies
docker-compose exec app composer install
docker-compose exec node npm install

# Rebuild frontend assets
docker-compose exec node npm run build
```

### Quick Sync Commands

Create a bash script for easy syncing:

**sync.sh:**
```bash
#!/bin/bash
echo "🔄 Syncing with latest changes..."

# Pull latest code
git pull origin main

# Stop containers
docker-compose down

# Rebuild and start
docker-compose up -d --build

# Update dependencies
docker-compose exec -T app composer install
docker-compose exec -T node npm install

# Run migrations
docker-compose exec -T app php artisan migrate

# Generate Wayfinder types
docker-compose exec -T app php artisan wayfinder:generate --with-form

# Clear caches
docker-compose exec -T app php artisan optimize:clear

echo "✅ Sync complete!"
```

**Usage:**
```bash
chmod +x sync.sh
./sync.sh
```

---

## Transferring Database Data

If you want to move your database data between computers:

### Export Database from Computer 1

```bash
# Create backup
docker-compose exec db mysqldump -u primehub_user -psecret primehub > database_backup.sql

# Or with timestamp
docker-compose exec db mysqldump -u primehub_user -psecret primehub > database_backup_$(date +%Y%m%d_%H%M%S).sql
```

### Transfer the SQL File

Use one of these methods:
- Copy to USB drive
- Upload to cloud storage (Google Drive, Dropbox)
- Email to yourself
- Use Git (for small databases)

### Import Database to Computer 2

```bash
# Make sure containers are running
docker-compose ps

# Import the backup
docker-compose exec -T db mysql -u primehub_user -psecret primehub < database_backup.sql

# Or if file is large, copy to container first
docker cp database_backup.sql primehub-db:/tmp/
docker-compose exec db mysql -u primehub_user -psecret primehub < /tmp/database_backup.sql
```

### Automated Database Sync

Create a script to backup and restore:

**backup-db.sh:**
```bash
#!/bin/bash
BACKUP_FILE="database_backup_$(date +%Y%m%d_%H%M%S).sql"
docker-compose exec db mysqldump -u primehub_user -psecret primehub > "$BACKUP_FILE"
echo "✅ Database backed up to: $BACKUP_FILE"
```

**restore-db.sh:**
```bash
#!/bin/bash
if [ -z "$1" ]; then
    echo "Usage: ./restore-db.sh <backup-file.sql>"
    exit 1
fi

docker-compose exec -T db mysql -u primehub_user -psecret primehub < "$1"
echo "✅ Database restored from: $1"
```

---

## Alternative: Using Docker Hub

If you want **faster setup** on multiple computers, you can push pre-built images to Docker Hub.

### When to Use Docker Hub

Use Docker Hub if:
- ✅ You have **5+ computers** to set up
- ✅ You want **faster setup** (no build time)
- ✅ You're deploying to **production servers**
- ✅ You want **exact same environment** everywhere

### Setup with Docker Hub

#### Step 1: Create Docker Hub Account

1. Go to https://hub.docker.com
2. Sign up for free account
3. Create a repository: `bisoyjan/primehub-systems`

#### Step 2: Build and Push Images (Computer 1)

```bash
# Make sure images are built
docker-compose build

# Tag images
docker tag primehub-systems-app bisoyjan/primehub-systems:latest
docker tag primehub-systems-queue bisoyjan/primehub-systems-queue:latest

# Login to Docker Hub
docker login

# Push images
docker push bisoyjan/primehub-systems:latest
docker push bisoyjan/primehub-systems-queue:latest
```

#### Step 3: Create docker-compose.pull.yml

Create a new file for pulling pre-built images:

```yaml
version: '3.8'

services:
  # Laravel Application
  app:
    image: bisoyjan/primehub-systems:latest
    container_name: primehub-app
    restart: unless-stopped
    working_dir: /var/www
    volumes:
      - ./:/var/www
      - ./storage:/var/www/storage
      - ./bootstrap/cache:/var/www/bootstrap/cache
    networks:
      - primehub-network
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_started
    environment:
      - APP_ENV=local
      - APP_DEBUG=true
      - DB_CONNECTION=mysql
      - DB_HOST=db
      - DB_PORT=3306
      - DB_DATABASE=primehub
      - DB_USERNAME=primehub_user
      - DB_PASSWORD=secret
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - QUEUE_CONNECTION=redis

  # ... (copy rest of services from docker-compose.yml)
  
  queue:
    image: bisoyjan/primehub-systems-queue:latest
    # ... rest of queue config
```

#### Step 4: Use on Other Computers

```bash
# Clone repository
git clone https://github.com/BisoyJan/primehub-systems.git
cd primehub-systems

# Pull and start using pre-built images
docker-compose -f docker-compose.pull.yml pull
docker-compose -f docker-compose.pull.yml up -d

# Run remaining setup
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate
```

### Time Comparison

| Method | First Setup | Updates |
|--------|-------------|---------|
| **Git + Build (Recommended)** | 10-15 min | 2-5 min |
| **Docker Hub** | 8-12 min | 5-10 min |

**Verdict**: Building from source is **simpler and faster for updates**.

---

## Troubleshooting

### Docker Not Running

**Error**: `Error response from daemon: Bad response from Docker engine`

**Solution**:
```bash
# Start Docker Desktop
# Wait for it to fully start (whale icon steady)

# Verify
docker info
```

### Port Already in Use

**Error**: `Bind for 0.0.0.0:80 failed: port is already allocated`

**Solution**:
```bash
# Windows - Check what's using port 80
netstat -ano | findstr :80

# Stop the service or change port in docker-compose.yml
# Edit nginx section:
services:
  nginx:
    ports:
      - "8080:80"  # Use port 8080 instead
```

### Permission Errors

**Error**: `Permission denied` for storage or cache

**Solution**:
```bash
# Fix permissions
docker-compose exec app chmod -R 775 storage bootstrap/cache
docker-compose exec app chown -R www-data:www-data storage bootstrap/cache

# Or use Make command
make permissions
```

### Database Connection Failed

**Error**: `SQLSTATE[HY000] [2002] Connection refused`

**Solution**:
```bash
# Check if database is healthy
docker-compose ps

# Wait for database to start (20-30 seconds first time)
docker-compose logs db

# Restart database
docker-compose restart db

# Verify .env has correct settings
DB_HOST=db
DB_PORT=3306
```

### Containers Keep Restarting

**Solution**:
```bash
# Check logs for errors
docker-compose logs -f app

# Common issues:
# 1. Missing .env file
cp .env.docker .env

# 2. No APP_KEY
docker-compose exec app php artisan key:generate

# 3. Database not ready
# Wait 30 seconds and check again
```

### Out of Disk Space

**Error**: `no space left on device`

**Solution**:
```bash
# Clean up Docker
docker system prune -a --volumes

# Remove unused images
docker image prune -a

# Remove stopped containers
docker container prune
```

### Vite/HMR Not Working

**Issue**: Changes to React files not reflecting

**Solution**:
```bash
# Check node container
docker-compose logs node

# Restart node container
docker-compose restart node

# Verify vite is running on port 5173
# Access via http://localhost (not :5173)
```

### Vite Dev Server Won't Start

**Error**: "Error generating types: php: not found"

**Solution**:
```bash
# Generate Wayfinder types first
docker-compose exec app php artisan wayfinder:generate --with-form

# Restart node container
docker-compose restart node

# Check logs
docker-compose logs node
```

The vite.config.ts is configured to skip Wayfinder plugin if PHP is not available in the Node container.

---

## Quick Reference

### Essential Commands

```bash
# Start containers
docker-compose up -d
make up

# Stop containers
docker-compose down
make down

# View logs
docker-compose logs -f
make logs

# Rebuild after code changes
docker-compose up -d --build

# Run migrations
docker-compose exec app php artisan migrate
make migrate

# Clear caches
docker-compose exec app php artisan optimize:clear
make clear

# Access app container
docker-compose exec app bash
make shell

# Run tests
docker-compose exec app php artisan test
make test
```

### Daily Workflow

```bash
# Morning - Start work
cd ~/Desktop/Projects/primehub-systems
docker-compose up -d

# Pull latest changes
git pull origin main
docker-compose restart

# Evening - Stop work
docker-compose stop
```

---

## Best Practices

### ✅ DO:

- **Commit often** - Push code changes to GitHub regularly
- **Use .gitignore** - Don't commit `.env`, `node_modules/`, `vendor/`
- **Run migrations** - After pulling changes with new migrations
- **Clear caches** - When encountering weird errors
- **Check logs** - Use `docker-compose logs` to debug issues
- **Backup database** - Before major changes

### ❌ DON'T:

- **Don't commit secrets** - Keep passwords in `.env` only
- **Don't edit inside containers** - Edit on host, changes sync automatically
- **Don't delete volumes** unless you want to lose data
- **Don't run as root** - Use provided scripts and commands
- **Don't skip migrations** - Run them after every pull

---

## Summary

### The Recommended Workflow

1. **Computer 1**: Make changes → Commit → Push to GitHub
2. **Computer 2**: Pull from GitHub → Run `docker-compose up -d --build`
3. **Repeat** as needed

### Why This Works

- ✅ Simple one-command setup
- ✅ Always builds from latest code
- ✅ No external services needed
- ✅ Easy to maintain and update
- ✅ Works on unlimited computers

### Next Steps

1. Make sure Docker Desktop is installed on all computers
2. Clone the repository
3. Run `./docker-setup.bat`
4. Start developing!

---

**Need help?** Check the other guides:
- [Docker Setup Guide](./DOCKER_SETUP.md) - Detailed setup instructions
- [Docker Summary](./DOCKER_SUMMARY.md) - Quick reference
- [Main README](../../README.md) - Project overview

**Happy coding! 🚀**
