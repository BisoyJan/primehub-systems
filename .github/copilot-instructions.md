# Copilot Instructions for AI Coding Agents

## Project Overview
This is a full-stack Laravel + React (TypeScript) application. Backend logic resides in `app/` (Laravel PHP), while frontend code is in `resources/js/` (React, Vite, TypeScript). Data models, migrations, and factories are in `app/Models/` and `database/`.

Key features include: PC/Station inventory management, hardware spec tracking (RAM, Disk, Processor, Monitor), QR code generation, attendance tracking, stock management, PC maintenance, and PC transfers.

## Architecture & Data Flow
- **Backend:**
  - Controllers: `app/Http/Controllers/` handle web requests.
  - Models: `app/Models/` define Eloquent ORM models for entities (e.g., `User`, `PcSpec`, `RamSpec`, `DiskSpec`, `MonitorSpec`, `ProcessorSpec`, `Station`, `Stock`, `Campaign`, `Site`, `PcMaintenance`, `PcTransfer`).
  - Jobs: `app/Jobs/` for background tasks (e.g., QR code zip generation for PC specs and stations).
  - Services: `app/Services/` for business logic (e.g., `DashboardService`).
  - Traits: `app/Traits/` for reusable logic (e.g., `HasSpecSearch`).
  - Utils: `app/Utils/` for helper utilities (e.g., `StationNumberUtil`).
  - Requests: `app/Http/Requests/` for form/request validation.
  - Middleware: `app/Http/Middleware/` for request lifecycle hooks.
  - Service Providers: `app/Providers/` for app/service bootstrapping.
  - Migrations/Factories/Seeders: `database/` for schema and test data.
- **Frontend:**
  - React pages/components: `resources/js/pages/`, `resources/js/components/`.
  - UI Components: Uses Shadcn/UI (Radix UI primitives) with Tailwind CSS.
  - Vite is used for bundling (`vite.config.ts`).

## Developer Workflows
- **Build:**
  - Frontend: `npm run build` (Vite) or `npm run build:ssr` for SSR builds
  - Backend: No explicit build, but run `php artisan` commands for migrations, cache clearing, etc.
- **Test:**
  - Backend: `php artisan test` or `vendor/bin/phpunit` (configuration in `phpunit.xml`)
  - Frontend: `npm run lint` for ESLint, `npm run format:check` for Prettier
- **Debug:**
  - Laravel: Use `php artisan serve` for local dev, logs in `storage/logs/`
  - React: Use Vite dev server (`npm run dev`)
  - Docker: See `docs/guides/` for Docker setup and deployment guides
- **Code Quality:**
  - TypeScript: `npm run types` for type checking
  - Formatting: `npm run format` (Prettier with Tailwind plugin)

## Project-Specific Conventions
- **Naming:**
  - Models use `*Spec.php` for hardware specs (e.g., `RamSpec`, `DiskSpec`).
  - Migrations use date-prefixed filenames for ordering.
- **Frontend Routing:**
  - React pages are organized by feature in `resources/js/pages/` (e.g., `Computer/`, `Station/`, `Attendance/`, `Account/`).
  - **Laravel Wayfinder** is used for type-safe routing between Laravel and React. Route definitions in PHP are automatically available in TypeScript.
- **Backend Routing:**
  - Routes are split by concern: `routes/web.php` (main routes), `routes/auth.php` (authentication), `routes/settings.php` (settings), `routes/console.php` (console commands).
  - No separate `api.php` - all routes use web middleware with Inertia responses.
- **Integration:**
  - **Inertia.js** bridges Laravel backend and React frontend. Backend controllers return Inertia responses (see `app/Http/Controllers/`), which map to React pages in `resources/js/pages/`. Props are passed from PHP to React via Inertia, enabling seamless SPA navigation and server-side data hydration.
  - **Frontend/Backend Communication:** Controllers return Inertia responses for page navigation or JSON for API-like endpoints. React components use Inertia's form helpers or the Wayfinder-generated routes for data operations. For example, see how `resources/js/pages/Computer/RamSpecs/Index.tsx` interacts with backend endpoints for RAM specs.
  - **Fortify** is used for authentication (`config/fortify.php`). Custom authentication logic and user management are handled via Fortify's configuration and service provider.

## External Dependencies
- **Laravel packages:** See `composer.json` for installed packages (e.g., Fortify, Inertia, Wayfinder, endroid/qr-code for QR generation).
- **Node packages:** See `package.json` for React, Vite, Radix UI (Shadcn/UI), etc.

## Examples of Patterns
- **Eloquent Relationships:**
  - Many-to-many: See `app/Models/PcSpec.php` and related migration files for pivot tables. Eloquent relationships are used for hardware specs and stations.
- **Validation:**
  - Form requests in `app/Http/Requests/` encapsulate validation logic. Example: `RamSpecRequest.php` for validating RAM spec forms.
- **Frontend State:**
  - React components use hooks and props for state management. Example: `resources/js/pages/Computer/RamSpecs/Index.tsx` uses React hooks to manage RAM spec data and UI state.
- **Inertia Page Props:**
  - Backend controllers return Inertia responses with props, which are consumed by React pages. See `app/Http/Controllers/RamSpecController.php` and corresponding React page for data flow.
- **Custom Middleware:**
  - Middleware in `app/Http/Middleware/` can be used for request filtering, access control, or custom logic. Register in `app/Http/Kernel.php`.

## Key Files & Directories
- `app/Models/`, `app/Http/Controllers/`, `resources/js/pages/`, `database/migrations/`, `config/`
- `vite.config.ts`, `composer.json`, `package.json`, `phpunit.xml`

---

**For updates, merge new conventions and patterns here. Ask for feedback if any section is unclear or missing.**
