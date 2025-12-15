# PrimeHub Systems

A comprehensive full-stack Laravel + React (TypeScript) application for IT asset management, employee attendance tracking, and form request workflows. Built for managing PC specifications, workstations, hardware inventory, biometric attendance, leave requests, and IT support concerns.

## Features

### 🖥️ IT Asset Management
- **PC Specifications** - Full hardware inventory tracking (RAM, Disk, Processor, Monitor)
- **Station Management** - Track workstations with assigned PCs across sites and campaigns
- **PC Transfers** - Transfer PCs between stations with complete history tracking
- **PC Maintenance** - Log and track maintenance records for all equipment
- **Stock Inventory** - Manage spare parts and equipment stock levels
- **QR Code Generation** - Generate QR codes for assets (individual or bulk ZIP)

### ⏰ Attendance Management
- **Biometric Records** - Import and process biometric attendance data
- **Employee Schedules** - Define and manage employee work schedules
- **Attendance Points** - Track and manage attendance infractions with excuse system
- **Attendance Statistics** - View comprehensive attendance analytics
- **Biometric Export** - Export attendance data to Excel with job queue processing
- **Anomaly Detection** - Detect and flag biometric data anomalies
- **Retention Policies** - Configure data retention for biometric records

### 📝 Form Requests
- **Leave Requests** - Submit, approve/deny leave applications with email notifications
- **IT Concerns** - Submit and track IT support tickets with assignment workflow
- **Medication Requests** - Request and manage medication needs
- **Leave Credits** - Track employee leave credit balances

### 👥 User Management
- **Role-Based Access Control** - 7 roles (Super Admin, Admin, Team Lead, Agent, HR, IT, Utility)
- **Granular Permissions** - 60+ configurable permissions across all modules
- **User Approval System** - New users require approval before accessing the system
- **Two-Factor Authentication** - Optional 2FA for enhanced security
- **Activity Logging** - Track all user actions with Spatie Activity Log

### 🔔 Notifications
- **Real-time Notifications** - In-app notification system
- **Email Notifications** - Automated emails for leave and medication requests

### 🏢 Organization Management
- **Sites** - Manage physical locations/offices
- **Campaigns** - Track projects or campaigns with assigned stations

## Tech Stack

### Backend
- **Laravel 12** - PHP Framework
- **PHP 8.2+** - Server-side language
- **MySQL 8.0** - Database
- **Redis** - Cache & Queue backend
- **Laravel Fortify** - Authentication with 2FA support
- **Inertia.js** - Backend/Frontend bridge
- **Laravel Wayfinder** - Type-safe routing
- **Spatie Activity Log** - Audit trail logging
- **PHPSpreadsheet** - Excel export/import
- **Endroid QR Code** - QR code generation

### Frontend
- **React 19** - UI Framework
- **TypeScript** - Type-safe JavaScript
- **Vite 7** - Build tool & dev server
- **Tailwind CSS 4** - Utility-first styling
- **Radix UI** - Accessible component primitives (Shadcn/UI)
- **Framer Motion** - Animations
- **GSAP** - Advanced animations
- **Recharts** - Data visualization
- **Lucide React** - Icons
- **Sonner** - Toast notifications
- **date-fns** - Date manipulation
- **cmdk** - Command palette

## Prerequisites

- PHP 8.2+
- Composer
- Node.js 20+
- npm
- MySQL 8.0+
- Redis

## Installation

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

# Configure Redis in .env
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Run migrations
php artisan migrate

# (Optional) Seed database with sample data
php artisan db:seed

# Generate Wayfinder types for type-safe routing
php artisan wayfinder:generate --with-form

# Build frontend assets
npm run build

# Start development servers
composer run dev
```

**Access the application**: http://localhost:8000

## Development

### Running Development Servers

The project includes a convenient dev script that runs all services concurrently:

```bash
# Recommended: Run all services with one command
composer run dev

# This starts:
# - Laravel development server (http://localhost:8000)
# - Queue worker for background jobs
# - Vite dev server for hot reload
```

Alternatively, run each service separately:

```bash
# Terminal 1: Laravel server
php artisan serve

# Terminal 2: Queue worker
php artisan queue:listen --tries=1

# Terminal 3: Vite dev server
npm run dev
```

### With Ngrok (for external access)

```bash
# Run all services including Ngrok tunnel
composer run dev:ngrok
```

### Common Commands

```bash
# Run migrations
php artisan migrate

# Seed database
php artisan db:seed

# Fresh install with seeding
php artisan migrate:fresh --seed

# Generate Wayfinder types (after route changes)
php artisan wayfinder:generate --with-form

# Clear all caches
php artisan optimize:clear

# Run tests
php artisan test
# or
composer test
```

## Project Structure

```
├── app/
│   ├── Http/
│   │   ├── Controllers/     # Web & Inertia controllers
│   │   ├── Requests/        # Form validation classes
│   │   ├── Middleware/      # Custom middleware (e.g., approved user check)
│   │   └── Traits/          # Controller traits
│   ├── Models/              # Eloquent models
│   │   ├── User.php         # User with roles & permissions
│   │   ├── PcSpec.php       # PC specifications
│   │   ├── Station.php      # Workstations
│   │   ├── Attendance.php   # Attendance records
│   │   ├── BiometricRecord.php
│   │   ├── LeaveRequest.php
│   │   ├── ItConcern.php
│   │   └── ...
│   ├── Services/            # Business logic
│   │   ├── AttendanceProcessor.php
│   │   ├── BiometricAnomalyDetector.php
│   │   ├── DashboardService.php
│   │   ├── LeaveCreditService.php
│   │   ├── NotificationService.php
│   │   └── PermissionService.php
│   ├── Jobs/                # Background jobs
│   │   ├── GenerateAllPcSpecQRCodesZip.php
│   │   ├── GenerateAttendanceExportExcel.php
│   │   └── ...
│   ├── Mail/                # Email templates
│   │   ├── LeaveRequestStatusUpdated.php
│   │   ├── MedicationRequestSubmitted.php
│   │   └── ...
│   └── Utils/               # Helper utilities
├── resources/
│   ├── js/
│   │   ├── pages/           # Inertia React pages
│   │   │   ├── Account/     # User accounts management
│   │   │   ├── Admin/       # Activity logs
│   │   │   ├── Attendance/  # Attendance module
│   │   │   │   ├── BiometricRecords/
│   │   │   │   ├── EmployeeSchedules/
│   │   │   │   ├── Main/
│   │   │   │   ├── Points/
│   │   │   │   └── Uploads/
│   │   │   ├── Computer/    # Hardware management
│   │   │   │   ├── DiskSpecs/
│   │   │   │   ├── MonitorSpecs/
│   │   │   │   ├── PcSpecs/
│   │   │   │   ├── ProcessorSpecs/
│   │   │   │   ├── RamSpecs/
│   │   │   │   └── Stocks/
│   │   │   ├── FormRequest/ # Form requests module
│   │   │   │   ├── ItConcerns/
│   │   │   │   ├── Leave/
│   │   │   │   └── MedicationRequests/
│   │   │   ├── Notifications/
│   │   │   ├── Station/     # Station management
│   │   │   │   ├── Campaigns/
│   │   │   │   ├── PcMaintenance/
│   │   │   │   ├── PcTransfer/
│   │   │   │   └── Site/
│   │   │   ├── settings/    # User settings
│   │   │   └── dashboard.tsx
│   │   ├── components/      # Reusable React components
│   │   │   ├── ui/          # Shadcn/UI components
│   │   │   └── ...
│   │   └── app.tsx          # Frontend entry
│   └── css/                 # Stylesheets
├── database/
│   ├── migrations/          # Database migrations
│   ├── factories/           # Model factories
│   └── seeders/             # Database seeders
├── routes/
│   ├── web.php              # Main application routes
│   ├── auth.php             # Authentication routes
│   └── settings.php         # Settings routes
├── config/
│   ├── permissions.php      # Role & permission definitions
│   └── ...
├── tests/                   # PHPUnit tests
└── public/                  # Public assets
```

## Key Models

### IT Assets
- **PcSpec** - Computer specifications with linked hardware components
- **RamSpec** - RAM specifications (capacity, type, brand)
- **ProcessorSpec** - Processor specifications (model, cores, speed)
- **DiskSpec** - Disk/Storage specifications (capacity, type, interface)
- **MonitorSpec** - Monitor specifications (size, resolution)
- **Station** - Workstations linked to sites and campaigns
- **PcTransfer** - PC transfer history between stations
- **PcMaintenance** - Maintenance records for equipment
- **Stock** - Inventory items with quantity tracking

### Attendance
- **Attendance** - Daily attendance records
- **BiometricRecord** - Raw biometric punch data
- **AttendanceUpload** - Biometric file upload records
- **AttendancePoint** - Attendance infraction points
- **EmployeeSchedule** - Employee work schedules
- **BiometricRetentionPolicy** - Data retention policies

### Form Requests
- **LeaveRequest** - Leave applications with approval workflow
- **LeaveCredit** - Employee leave credit balances
- **ItConcern** - IT support tickets
- **MedicationRequest** - Medication requests
- **FormRequestRetentionPolicy** - Retention policies for form requests

### Organization
- **User** - Users with roles, permissions, and approval status
- **Site** - Physical locations/offices
- **Campaign** - Projects or campaigns
- **Notification** - In-app notifications

## Role-Based Access Control

The application implements a comprehensive RBAC system with 7 predefined roles:

| Role | Description |
|------|-------------|
| **Super Admin** | Full access to all features |
| **Admin** | User management, attendance, HR features |
| **Team Lead** | Team attendance, schedules, basic requests |
| **Agent** | Personal attendance, leave/IT requests |
| **HR** | Full attendance, leave management, user accounts |
| **IT** | Full IT asset management, PC specs, stations |
| **Utility** | Basic dashboard and attendance access |

Permissions are organized by module (60+ permissions) and configured in `config/permissions.php`.

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

### Laravel Wayfinder
Type-safe routing between Laravel and React:
- Route definitions in PHP automatically available in TypeScript
- Generated types ensure route parameter correctness
- Run `php artisan wayfinder:generate --with-form` after route changes

### Authentication
Uses **Laravel Fortify** for authentication:
- Email/password login
- Two-factor authentication (TOTP)
- User approval workflow (new users require admin approval)

### Background Jobs
Redis-backed queue system for:
- QR code ZIP generation (bulk exports)
- Attendance Excel exports
- Email notifications

### Activity Logging
Uses **Spatie Activity Log** for comprehensive audit trails:
- Track all CRUD operations
- User action history
- Configurable per-model logging

## Available Scripts

### Development
```bash
npm run dev          # Start Vite dev server with hot reload
npm run build        # Build for production
npm run build:ssr    # Build with SSR support
npm run format       # Format code with Prettier
npm run format:check # Check code formatting
npm run lint         # Lint and fix with ESLint
npm run types        # Check TypeScript types
```

### Backend
```bash
php artisan test     # Run PHP tests
php artisan serve    # Start development server
php artisan queue:work   # Process background jobs
php artisan queue:listen # Process jobs with auto-restart
php artisan wayfinder:generate --with-form  # Generate route types
```

### Composer Scripts
```bash
composer run dev     # Run all dev services concurrently
composer run dev:ngrok  # Dev services + Ngrok tunnel
composer run dev:ssr    # Dev with SSR enabled
composer test        # Run tests with config clear
```

## Documentation

### � Available Guides
All guides are organized in the **[docs/guides/](./docs/guides/)** directory:

| Guide | Description |
|-------|-------------|
| **[Local Setup Guide](./docs/guides/LOCAL_SETUP_GUIDE.md)** | Complete local development setup without Docker |
| **[Ngrok Guide](./docs/guides/NGROK_GUIDE.md)** | Expose local app to the internet for demos/testing |
| **[VPS Hosting Guide](./docs/guides/VPS_HOSTING_COMPLETE_GUIDE.md)** | Complete VPS deployment guide |
| **[DigitalOcean Deployment](./docs/guides/DIGITALOCEAN_DEPLOYMENT.md)** | Deploy to DigitalOcean |

### Additional Documentation
- **[Notification System](./docs/NOTIFICATION_SYSTEM.md)** - Notification implementation details
- **[QR Code Setup](./docs/QR_CODE_ZIP_GENERATION_SETUP_GUIDE.md)** - QR code feature configuration
- **[PHP Extensions](./docs/PHP_EXTENSIONS_SETUP.md)** - Required PHP extensions

### Project Guidelines
- **[Copilot Instructions](./.github/copilot-instructions.md)** - AI coding assistant guidelines
- **[Refactoring Guide](./REFACTORING_GUIDE.md)** - Code refactoring standards

## Database

### Migrations
```bash
php artisan migrate
```

### Seeding
```bash
php artisan db:seed
```

### Fresh Install
```bash
php artisan migrate:fresh --seed
```

## Queue Management

Background jobs use Redis for queue management.

```bash
# Start queue worker (restarts on changes)
php artisan queue:listen --tries=1

# Start queue worker (production - faster)
php artisan queue:work

# Monitor queue in real-time
php artisan queue:monitor
```

## Troubleshooting

### Common Issues

**Permission errors (Linux/Mac):**
```bash
chmod -R 775 storage bootstrap/cache
```

**Cache issues:**
```bash
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan view:clear
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

**Wayfinder type errors:**
```bash
php artisan wayfinder:generate --with-form
```

**Queue jobs not processing:**
```bash
# Restart queue worker
php artisan queue:restart

# Check failed jobs
php artisan queue:failed
```

### Logs

- Laravel logs: `storage/logs/laravel.log`
- Use `php artisan pail` for real-time log monitoring

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Run tests: `php artisan test`
5. Format code: `npm run format && npm run lint`
6. Check types: `npm run types`
7. Commit your changes
8. Submit a pull request

## Environment Variables

Key environment variables to configure:

```env
# Application
APP_NAME="PrimeHub Systems"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=primehub
DB_USERNAME=root
DB_PASSWORD=

# Redis (for cache & queues)
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Queue
QUEUE_CONNECTION=redis

# Mail (for notifications)
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@example.com
```

## License

This project is proprietary software. All rights reserved.

## Support

For issues or questions:
1. Check existing documentation in `docs/`
2. Review Laravel logs: `storage/logs/laravel.log`
3. Use `php artisan pail` for real-time log monitoring
4. Contact the development team

---

**Built with ❤️ using Laravel & React**
