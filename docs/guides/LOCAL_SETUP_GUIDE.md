# Local Setup Guide (Without Docker)

This guide will help you set up and run PrimeHub Systems locally on your Windows machine without using Docker.

## Prerequisites

Before you begin, make sure you have the following installed on your system:

### 1. PHP 8.2 or higher
- Download from: https://windows.php.net/download/
- Choose **PHP 8.2** (Thread Safe version recommended)
- Extract to `C:\php` (or your preferred location)
- Add `C:\php` to your system PATH environment variable

**Required PHP Extensions** (enable in `php.ini`):
```ini
extension=pdo_mysql
extension=mbstring
extension=exif
extension=pcntl
extension=bcmath
extension=gd
extension=zip
extension=intl
extension=redis
extension=curl
extension=fileinfo
extension=openssl
```

### 2. Composer
- Download from: https://getcomposer.org/download/
- Run the installer and follow the prompts
- Verify installation: `composer --version`

### 3. Node.js 20.x or higher
- Download from: https://nodejs.org/
- Choose the LTS version (20.x)
- Verify installation: `node --version` and `npm --version`

### 4. MySQL 8.0
- Download from: https://dev.mysql.com/downloads/mysql/
- Install MySQL Server 8.0
- Note your root password during installation
- Optionally install **HeidiSQL** or **MySQL Workbench** for database management

### 5. Redis (Optional but Recommended)
For Windows, use one of these options:

**Option A: Redis for Windows (Memurai)**
- Download from: https://www.memurai.com/get-memurai
- Free for development use

**Option B: WSL2 with Redis**
- Install WSL2: `wsl --install`
- Install Redis in WSL: `sudo apt-get install redis-server`
- Start Redis: `sudo service redis-server start`

**Option C: Skip Redis (Use file-based cache/queue)**
- Less performant but simpler for development

---

## Setup Steps

### Step 1: Clone or Navigate to Project
```bash
cd C:\Users\bisoy\Desktop\Projects\primehub-systems
```

### Step 2: Install PHP Dependencies
```bash
composer install
```

### Step 3: Install Node.js Dependencies
```bash
npm install
```

### Step 4: Configure Environment Variables

Copy the example environment file:
```bash
copy .env.example .env
```

Or if `.env` already exists, update it with these settings:

```env
APP_NAME=PrimeHub
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database Configuration
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=primehub
DB_USERNAME=root
DB_PASSWORD=your_mysql_password

# Session
SESSION_DRIVER=database
SESSION_LIFETIME=120

# Cache & Queue - Option A: With Redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Cache & Queue - Option B: Without Redis (comment above, uncomment below)
# CACHE_STORE=file
# QUEUE_CONNECTION=database

# Mail (Optional - for testing use Mailtrap or log)
MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@primehub.test"
MAIL_FROM_NAME="${APP_NAME}"
```

### Step 5: Generate Application Key
```bash
php artisan key:generate
```

### Step 6: Create Database

**Using MySQL Command Line:**
```bash
mysql -u root -p
```

Then run:
```sql
CREATE DATABASE primehub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

**Or using HeidiSQL:**
1. Connect to MySQL (localhost:3306)
2. Right-click → Create New → Database
3. Name: `primehub`
4. Collation: `utf8mb4_unicode_ci`

### Step 7: Run Database Migrations & Seeders
```bash
php artisan migrate:fresh --seed
```

This will create all tables and populate them with sample data.

### Step 8: Generate Wayfinder Types

**Important:** Generate TypeScript route types for the frontend before starting Vite dev server.

```bash
php artisan wayfinder:generate --with-form
```

This creates route type files in `resources/js/routes/` and `resources/js/actions/` that Vite needs.

**When to regenerate:**
- After adding/modifying routes
- After changing form request validation
- Before running the dev server for the first time

### Step 9: Create Storage Symlink
```bash
php artisan storage:link
```

### Step 10: Set Up Storage Permissions

Create required directories if they don't exist:
```bash
mkdir storage\app\temp
mkdir storage\framework\cache
mkdir storage\framework\sessions
mkdir storage\framework\views
mkdir storage\logs
```

---

## Running the Application

You need to run **three separate terminal windows** simultaneously:

### Terminal 1: Laravel Development Server
```bash
php artisan serve
```

This will start the Laravel app at: **http://localhost:8000**

### Terminal 2: Vite Dev Server (Frontend)
```bash
npm run dev
```

This will start Vite at: **http://localhost:5173** (for hot module replacement)

### Terminal 3: Queue Worker (If using queues)

**If using Redis for queues:**
```bash
php artisan queue:work redis --tries=3 --timeout=300
```

**If using database for queues:**
```bash
php artisan queue:work database --tries=3 --timeout=300
```

**Note:** The queue worker is **required** for QR code generation to work properly. Without it, QR code downloads will hang.

---

## Accessing the Application

1. Open your browser and go to: **http://localhost:8000**
2. You should see the login page
3. Use the default credentials (check `database/seeders/DatabaseSeeder.php` for test users)

---

## Common Issues & Solutions

### Issue 1: "Class Redis not found"
**Solution:** 
- Make sure PHP Redis extension is enabled in `php.ini`
- Or switch to file/database cache in `.env`:
  ```env
  CACHE_STORE=file
  QUEUE_CONNECTION=database
  ```

### Issue 2: QR Code Generation Hangs
**Solution:**
- Make sure the queue worker is running (Terminal 3)
- Check queue worker logs for errors

### Issue 3: Vite Connection Refused
**Solution:**
- Make sure Vite dev server is running (`npm run dev`)
- Check if port 5173 is available

### Issue 3.1: Vite Dev Server Error - "php: not found"
**Error:** Vite fails to start with "Error generating types: php: not found"

**Solution:**
1. Generate Wayfinder types manually first:
   ```bash
   php artisan wayfinder:generate --with-form
   ```

2. The vite.config.ts automatically detects if PHP is unavailable and skips the Wayfinder plugin

3. Restart Vite dev server:
   ```bash
   npm run dev
   ```

### Issue 4: MySQL Connection Failed
**Solution:**
- Verify MySQL is running: `mysql -u root -p`
- Check credentials in `.env` file
- Ensure database `primehub` exists

### Issue 5: Permission Denied on Storage
**Solution:**
- On Windows, usually not an issue
- Make sure storage directories exist (see Step 10)

### Issue 6: Port 8000 Already in Use
**Solution:**
- Use a different port: `php artisan serve --port=8080`
- Update `APP_URL` in `.env` to match

---

## Development Workflow

### Starting Development
Run these commands in separate terminals:
```bash
# Terminal 1
php artisan serve

# Terminal 2
npm run dev

# Terminal 3
php artisan queue:work
```

### Stopping Development
- Press `Ctrl+C` in each terminal to stop the servers

### Clearing Caches
```bash
php artisan optimize:clear
```

This clears:
- Config cache
- Route cache
- View cache
- Application cache

### Running Migrations
```bash
# Run new migrations
php artisan migrate

# Rollback last migration
php artisan migrate:rollback

# Reset and re-run all migrations with seeders
php artisan migrate:fresh --seed
```

### Building for Production
```bash
# Build frontend assets
npm run build

# Optimize Laravel
php artisan optimize
```

---

## Database Management

### Using HeidiSQL (Recommended for Windows)
1. Download from: https://www.heidisql.com/download.php
2. Connect with:
   - Network type: MySQL (TCP/IP)
   - Hostname: 127.0.0.1
   - User: root
   - Password: your_mysql_password
   - Port: 3306
   - Database: primehub

### Using MySQL Command Line
```bash
# Connect to database
mysql -u root -p primehub

# Show tables
SHOW TABLES;

# View data
SELECT * FROM users;
SELECT * FROM pc_specs;
```

---

## Testing

### Run PHP Unit Tests
```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Feature/PcSpecTest.php

# Run with coverage
php artisan test --coverage
```

### Run Frontend Tests (if configured)
```bash
npm test
```

---

## Performance Tips for Local Development

1. **Use Redis** instead of file cache for better performance
2. **Keep queue worker running** to process background jobs
3. **Use `php artisan serve`** instead of XAMPP/WAMP for simplicity
4. **Enable OPcache** in production (not needed for development)

---

## Troubleshooting Checklist

Before asking for help, verify:

- [ ] PHP version is 8.2 or higher: `php --version`
- [ ] Composer is installed: `composer --version`
- [ ] Node.js is installed: `node --version`
- [ ] MySQL is running and accessible
- [ ] `.env` file exists and has correct database credentials
- [ ] Application key is generated: check `APP_KEY` in `.env`
- [ ] Database migrations have run: check tables exist
- [ ] Storage directories exist and are writable
- [ ] All three servers are running (Laravel, Vite, Queue)
- [ ] No port conflicts (8000, 5173, 3306, 6379)

---

## Additional Resources

- **Laravel Documentation**: https://laravel.com/docs
- **Inertia.js Documentation**: https://inertiajs.com/
- **React Documentation**: https://react.dev/
- **Vite Documentation**: https://vitejs.dev/

---

## Need Help?

If you encounter issues:
1. Check the `storage/logs/laravel.log` file for errors
2. Enable debug mode: `APP_DEBUG=true` in `.env`
3. Clear all caches: `php artisan optimize:clear`
4. Check PHP error log for fatal errors

---

**Happy coding! 🚀**
