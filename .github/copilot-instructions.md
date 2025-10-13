# Copilot Instructions for AI Coding Agents

## Project Overview
This is a full-stack Laravel + React (TypeScript) application. Backend logic resides in `app/` (Laravel PHP), while frontend code is in `resources/js/` (React, Vite, TypeScript). Data models, migrations, and factories are in `app/Models/` and `database/`.

## Architecture & Data Flow
- **Backend:**
  - Controllers: `app/Http/Controllers/` handle API and web requests.
  - Models: `app/Models/` define Eloquent ORM models for entities (e.g., `User`, `PcSpec`, `RamSpec`).
  - Requests: `app/Http/Requests/` for form/request validation.
  - Middleware: `app/Http/Middleware/` for request lifecycle hooks.
  - Service Providers: `app/Providers/` for app/service bootstrapping.
  - Migrations/Factories/Seeders: `database/` for schema and test data.
- **Frontend:**
  - React pages/components: `resources/js/pages/`, `resources/js/components/`.
  - Vite is used for bundling (`vite.config.ts`).

## Developer Workflows
- **Build:**
  - Frontend: `npm run build` (Vite)
  - Backend: No explicit build, but run `php artisan` commands for migrations, etc.
- **Test:**
  - Backend: `php artisan test` or `vendor/bin/phpunit`
  - Frontend: If tests exist, use `npm test` (not standard in this repo)
- **Debug:**
  - Laravel: Use `php artisan serve` for local dev, logs in `storage/logs/`
  - React: Use Vite dev server (`npm run dev`)

## Project-Specific Conventions
- **Naming:**
  - Models use `*Spec.php` for hardware specs (e.g., `RamSpec`, `DiskSpec`).
  - Migrations use date-prefixed filenames for ordering.
- **Frontend Routing:**
  - React pages are organized by feature in `resources/js/pages/Station/`, etc.
- **Backend Routing:**
  - Routes are split by concern: `routes/web.php`, `routes/api.php`, etc.
- **Integration:**
  - Inertia.js bridges Laravel backend and React frontend.
  - Fortify is used for authentication (`config/fortify.php`).

## External Dependencies
- **Laravel packages:** See `composer.json` for installed packages (e.g., Fortify, Inertia).
- **Node packages:** See `package.json` for React, Vite, etc.

## Examples of Patterns
- **Eloquent Relationships:**
  - Many-to-many: See `app/Models/PcSpec.php` and related migration files for pivot tables.
- **Validation:**
  - Form requests in `app/Http/Requests/` encapsulate validation logic.
- **Frontend State:**
  - React components use hooks and props for state management.

## Key Files & Directories
- `app/Models/`, `app/Http/Controllers/`, `resources/js/pages/`, `database/migrations/`, `config/`
- `vite.config.ts`, `composer.json`, `package.json`, `phpunit.xml`

---

**For updates, merge new conventions and patterns here. Ask for feedback if any section is unclear or missing.**
