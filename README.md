# PrimeHub Systems

A full-stack Laravel + React (TypeScript) application for managing PC specifications, stations, and hardware inventory.

## Features

- 🖥️ PC Specifications Management
- 🎯 Station Tracking
- 💾 Hardware Specs (RAM, Processor, Disk, Monitor)
- 📊 Dashboard Analytics
- 🔄 PC Transfers and Maintenance
- 📦 Stock Management
- 🏢 Site and Campaign Management
- 📱 QR Code Generation for Assets

## Tech Stack

### Backend
- **Laravel 12** - PHP Framework
- **PHP 8.2** - Server-side language
- **MySQL 8.0** - Database
- **Redis** - Cache & Queue backend
- **Laravel Fortify** - Authentication
- **Inertia.js** - Backend/Frontend bridge

### Frontend
- **React 19** - UI Framework
- **TypeScript** - Type-safe JavaScript
- **Vite** - Build tool & dev server
- **Tailwind CSS 4** - Styling
- **Radix UI** - Component primitives
- **Framer Motion** - Animations
- **Lucide React** - Icons

## Prerequisites

Choose one of the following setups:

### Option 1: Docker (Recommended)
- Docker Desktop (Windows/Mac) or Docker Engine (Linux)
- Docker Compose

### Option 2: Local Development
- PHP 8.2+
- Composer
- Node.js 20+
- npm
- MySQL 8.0+
- Redis

## Installation

### 🐳 Using Docker (Recommended)

Docker setup provides a consistent development environment with all dependencies included.

#### Quick Start
```bash
# Windows
./docker-setup.bat

# Linux/Mac
./docker-setup.sh

# Or using Make
make setup
```

#### Manual Docker Setup
```bash
# Copy environment file
cp .env.docker .env

# Build and start containers
docker-compose up -d

# Install dependencies
docker-compose exec app composer install
docker-compose exec node npm install

# Generate key and migrate
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate

# Build assets
docker-compose exec node npm run build
```

**Access the application**: http://localhost

For complete Docker documentation, see **[docs/guides/DOCKER_SETUP.md](./docs/guides/DOCKER_SETUP.md)**

### 💻 Local Development Setup

```bash
# Clone the repository
git clone <repository-url>
cd primehub-systems

# Copy environment file
cp .env.example .env

# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Generate application key
php artisan key:generate

# Configure database in .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=primehub
DB_USERNAME=root
DB_PASSWORD=

# Run migrations
php artisan migrate

# Build frontend assets
npm run build

# Start development servers (in separate terminals)
php artisan serve
php artisan queue:work
npm run dev
```

**Access the application**: http://localhost:8000

## Development

### Docker Development
```bash
# View logs
docker-compose logs -f

# Run migrations
docker-compose exec app php artisan migrate

# Seed database
docker-compose exec app php artisan db:seed

# Run tests
docker-compose exec app php artisan test

# Clear caches
docker-compose exec app php artisan optimize:clear

# Access containers
docker-compose exec app bash
docker-compose exec node sh

# Stop services
docker-compose down
```

### Local Development
```bash
# Run migrations
php artisan migrate

# Seed database
php artisan db:seed

# Run tests
php artisan test

# Clear caches
php artisan optimize:clear

# Run development servers
composer run dev  # Runs all services concurrently
```

## Project Structure

```
├── app/
│   ├── Http/
│   │   ├── Controllers/     # API & Web controllers
│   │   ├── Requests/        # Form validation
│   │   └── Middleware/      # Custom middleware
│   ├── Models/              # Eloquent models
│   ├── Services/            # Business logic
│   └── Utils/               # Helper utilities
├── resources/
│   ├── js/
│   │   ├── pages/           # Inertia React pages
│   │   ├── components/      # React components
│   │   └── app.tsx          # Frontend entry
│   └── css/                 # Stylesheets
├── database/
│   ├── migrations/          # Database migrations
│   ├── factories/           # Model factories
│   └── seeders/             # Database seeders
├── routes/
│   ├── web.php              # Web routes
│   ├── api.php              # API routes
│   └── auth.php             # Auth routes
├── docker/                  # Docker configuration
├── tests/                   # PHPUnit tests
└── public/                  # Public assets
```

## Key Models

- **PcSpec** - Computer specifications
- **Station** - Work stations
- **RamSpec** - RAM specifications
- **ProcessorSpec** - Processor specifications
- **DiskSpec** - Disk/Storage specifications
- **MonitorSpec** - Monitor specifications
- **PcTransfer** - PC transfer records
- **PcMaintenance** - Maintenance records
- **Stock** - Inventory management
- **Site** - Physical locations
- **Campaign** - Marketing/project campaigns

## Architecture

### Inertia.js Integration
The app uses **Inertia.js** to bridge Laravel and React:
- Backend controllers return Inertia responses with props
- React pages consume these props for rendering
- SPA navigation without page reloads
- Server-side data hydration

Example flow:
```
Controller → Inertia Response → React Page → UI Render
```

### Authentication
Uses **Laravel Fortify** for authentication with custom configuration in `config/fortify.php`.

### Frontend State
React hooks and Inertia's state management handle UI state and data flow.

## Available Scripts

### Development
```bash
npm run dev          # Start Vite dev server
npm run build        # Build for production
npm run format       # Format code with Prettier
npm run lint         # Lint code with ESLint
npm run types        # Check TypeScript types
```

### Testing
```bash
php artisan test     # Run PHP tests
composer test        # Alternative test command
```

## Documentation

### 📚 Complete Documentation
All guides are organized in the **[docs/guides/](./docs/guides/)** directory:

#### Setup Guides
- **[Docker Setup Guide](./docs/guides/DOCKER_SETUP.md)** - Complete Docker installation & configuration
- **[Docker Deployment Guide](./docs/guides/DOCKER_DEPLOYMENT_GUIDE.md)** - Using Docker on multiple computers
- **[Local Setup Guide](./docs/guides/LOCAL_SETUP_GUIDE.md)** - Running without Docker
- **[Docker Quick Reference](./docs/guides/DOCKER_README.md)** - Docker commands & overview
- **[Docker Architecture](./docs/guides/DOCKER_ARCHITECTURE.md)** - Understanding the container structure
- **[Docker Summary](./docs/guides/DOCKER_SUMMARY.md)** - Quick reference & cheat sheet

#### Deployment & Remote Access
- **[Ngrok Guide](./docs/guides/NGROK_GUIDE.md)** - Expose local app to the internet

#### Development Guidelines
- **[Refactoring Guide](./REFACTORING_GUIDE.md)** - Code refactoring standards
- **[Copilot Instructions](./.github/copilot-instructions.md)** - AI coding assistant guidelines
- **[PHP Extensions Setup](./docs/PHP_EXTENSIONS_SETUP.md)** - PHP configuration
- **[QR Code Setup](./docs/QR_CODE_ZIP_GENERATION_SETUP_GUIDE.md)** - QR code feature setup

### 🚀 Quick Links
- **New to the project?** → Start with [docs/guides/README.md](./docs/guides/README.md)
- **Using Docker?** → See [Docker Setup Guide](./docs/guides/DOCKER_SETUP.md)
- **Multiple PCs?** → See [Docker Deployment Guide](./docs/guides/DOCKER_DEPLOYMENT_GUIDE.md)
- **Running locally?** → See [Local Setup Guide](./docs/guides/LOCAL_SETUP_GUIDE.md)
- **Need remote access?** → See [Ngrok Guide](./docs/guides/NGROK_GUIDE.md)

## Database

### Migrations
```bash
# Docker
docker-compose exec app php artisan migrate

# Local
php artisan migrate
```

### Seeding
```bash
# Docker
docker-compose exec app php artisan db:seed

# Local
php artisan db:seed
```

### Fresh Install
```bash
# Docker
docker-compose exec app php artisan migrate:fresh --seed

# Local
php artisan migrate:fresh --seed
```

## Queue Management

Background jobs use Redis for queue management.

### Docker
```bash
# View queue worker logs
docker-compose logs -f queue

# Restart queue worker
docker-compose restart queue
```

### Local
```bash
# Start queue worker
php artisan queue:work

# Listen mode (auto-restart)
php artisan queue:listen
```

## Troubleshooting

### Docker Issues
See **[docs/guides/DOCKER_SUMMARY.md](./docs/guides/DOCKER_SUMMARY.md)** troubleshooting section.

### Local Issues

**Permission errors:**
```bash
chmod -R 775 storage bootstrap/cache
```

**Cache issues:**
```bash
php artisan optimize:clear
```

**Composer issues:**
```bash
composer install --no-cache
composer dump-autoload
```

**NPM issues:**
```bash
rm -rf node_modules package-lock.json
npm install
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests: `php artisan test`
5. Format code: `npm run format`
6. Submit a pull request

## License

This project is proprietary software. All rights reserved.

## Support

For issues or questions:
1. Check existing documentation
2. Review Laravel logs: `storage/logs/laravel.log`
3. Check Docker logs: `docker-compose logs -f` (if using Docker)
4. Contact the development team

---

**Built with ❤️ using Laravel & React**
