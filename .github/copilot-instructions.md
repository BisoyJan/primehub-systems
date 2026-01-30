# Copilot Instructions for AI Coding Agents

## Project Overview
Full-stack **Laravel 12 + React 19 (TypeScript)** application for IT asset management, attendance tracking, and form requests. Uses **Inertia.js** to bridge backend/frontend (no separate API routes).

**Key Modules:** PC/Station inventory, hardware specs (RAM, Disk, Processor, Monitor), QR codes, biometric attendance, leave/IT concerns, stock management.

## Architecture
```
app/
├── Http/Controllers/    # Inertia responses, form handling
├── Http/Requests/       # Form validation classes
├── Http/Traits/         # RedirectsWithFlashMessages, HandlesStockOperations
├── Models/              # Eloquent models with LogsActivity trait
├── Jobs/                # Queue jobs (QR zips, Excel exports)
├── Services/            # Business logic (DashboardService, NotificationService)
├── Policies/            # Authorization policies
resources/js/
├── pages/               # React pages matching Inertia routes
├── components/          # Shadcn/UI components + custom (PageHeader, DeleteConfirmDialog)
├── hooks/               # usePageMeta, useFlashMessage, usePermission, usePageLoading
├── routes/              # Wayfinder-generated type-safe routes
```

## Developer Workflows
```bash
# Backend
php artisan serve                    # Dev server :8000
php artisan test                     # Run tests
php artisan tinker                   # Interactive shell - ALWAYS verify new code here
php artisan queue:work               # Process background jobs

# Frontend
npm run dev                          # Vite dev server :5173
npm run build                        # Production build
npm run types                        # TypeScript check
npm run lint && npm run format       # Code quality
```

## Console Tinker Testing
**IMPORTANT:** Always verify backend PHP code in `php artisan tinker` before committing.

```php
# Models & Relationships
$pcSpec = App\Models\PcSpec::with(['ramSpecs', 'diskSpecs'])->first();

# Services
$service = app(App\Services\DashboardService::class);
$stats = $service->getStats();

# Jobs (sync for testing)
dispatch_sync(new App\Jobs\GenerateAllPcSpecQRCodesZip($jobId, 'png', 300, false));
Cache::get("qrcode_zip_job:{$jobId}");
```

## Project-Specific Conventions
- **Models:** `*Spec.php` for hardware specs (e.g., `RamSpec`, `DiskSpec`)
- **Routes:** `routes/web.php` (main), `routes/auth.php`, `routes/settings.php` - no separate `api.php`
- **Pages:** `resources/js/pages/{Feature}/` mirrors controller structure
- **Wayfinder:** Type-safe routes auto-generated in `resources/js/routes/`
- **Inertia:** Controllers return `inertia('Page/Path', $props)` → React pages receive via `usePage<Props>().props`
- **Auth:** Laravel Fortify with 2FA support (see `config/fortify.php`)

## Key Reference Files
- Controller example: [RamSpecsController.php](app/Http/Controllers/RamSpecsController.php)
- Model with relationships: [PcSpec.php](app/Models/PcSpec.php)
- React page pattern: [RamSpecs/Index.tsx](resources/js/pages/Computer/RamSpecs/Index.tsx)
- Permissions config: [config/permissions.php](config/permissions.php)

## Error Handling & Logging

### Controller Pattern
```php
try {
    DB::transaction(function () use ($request) {
        // Database operations
    });
    return $this->redirectWithFlash('route.name', 'Success message.');
} catch (\Exception $e) {
    Log::error('ControllerName Error: ' . $e->getMessage());
    return $this->redirectWithFlash('route.name', 'Failed message.', 'error');
}
```

### Job Progress Tracking
```php
Cache::put($cacheKey, ['percent' => 50, 'status' => 'Processing...', 'finished' => false], 3600);
```

### Activity Logging (Spatie)
All models use `LogsActivity` trait with `logAll()->logOnlyDirty()->dontSubmitEmptyLogs()`

## Testing Patterns
- **Feature tests:** `tests/Feature/` with `RefreshDatabase` trait
- **Unit tests:** `tests/Unit/` for services, policies, models
- **Attribute syntax:** Use `#[Test]` instead of `test_` prefix
- **Inertia assertions:** `$response->assertInertia(fn ($page) => $page->component('Path')->has('data'))`
- **Auth testing:** `User::factory()->create(['role' => 'IT', 'is_approved' => true])`

## Authorization
**Roles:** `super_admin`, `admin`, `team_lead`, `agent`, `hr`, `it`, `utility`
**Permissions:** `module.action` format in `config/permissions.php`

```php
// Controller
$this->authorize('viewAny', Model::class);

// Route middleware
Route::resource('models', Controller::class)->middleware('permission:model.view,model.create');
```
```tsx
// Frontend
import { Can } from '@/components/authorization';
import { usePermission } from '@/hooks/use-permission';

<Can permission="model.create"><Button>Create</Button></Can>
const { can } = usePermission(); // can('model.edit')
```

## Queue/Job Patterns

### Job Structure
```php
class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected string $jobId, /* params */) {}

    public function handle(): void
    {
        $cacheKey = "job_type:{$this->jobId}";
        
        Cache::put($cacheKey, ['percent' => 0, 'status' => 'Starting...', 'finished' => false], 3600);
        
        // Work...
        
        Cache::put($cacheKey, [
            'percent' => 100,
            'status' => 'Complete',
            'finished' => true,
            'downloadUrl' => $url
        ], 3600);
    }
}
```

### Conventions
- **Naming:** `Generate{Feature}{Action}` (e.g., `GenerateAllPcSpecQRCodesZip`)
- **Progress tracking:** Cache key format `job_type:{$jobId}`, TTL 1 hour
- **Temp files:** Store in `storage_path('app/temp/')`, clean after download
- **Queue driver:** Database (configurable via `QUEUE_CONNECTION` env)

## Flash Messages

### Backend Trait
Use `RedirectsWithFlashMessages` trait in controllers:
```php
return $this->redirectWithFlash('route.name', 'Message', 'success'); // or 'error', 'warning', 'info'
return $this->backWithFlash('Message', 'error', ['field' => 'error']);
```

### Alternative Inertia Flash
```php
return redirect()->back()->with('flash', [
    'message' => 'Success message',
    'type' => 'success'
]);
```

### Frontend Hook
```tsx
useFlashMessage(); // Call in page component - auto-displays toasts via Sonner
```

## Frontend Component Patterns

### Standard Page Structure
```tsx
export default function PageName() {
    const { data, search } = usePage<Props>().props;
    
    const { title, breadcrumbs } = usePageMeta({ 
        title: "Page Title", 
        breadcrumbs: [{ label: 'Home', href: '/' }, { label: 'Page' }] 
    });
    useFlashMessage();
    const isLoading = usePageLoading();
    const { can } = usePermission();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <PageHeader title={title} createLink={can('model.create') ? createRoute().url : undefined} />
            {/* Content */}
        </AppLayout>
    );
}
```

### Reusable Components
- `<PageHeader>` - Title, description, action buttons
- `<DeleteConfirmDialog>` - Confirmation modal for destructive actions
- `<LoadingOverlay>` - Loading state overlay
- `<PaginationNav>` - Pagination controls
- `<SearchBar>` - Search input with debounce

### Standard Hooks
- `usePageMeta()` - Title and breadcrumbs
- `useFlashMessage()` - Toast notifications from backend
- `usePageLoading()` - Loading state during navigation
- `usePermission()` / `useCan()` - Authorization checks

### Wayfinder Routes
```tsx
import { index, store, update, destroy } from '@/routes/models';

// Usage
<Link href={index().url}>List</Link>
form.post(store().url);
```

## Reusable Traits

### Controller Traits (`app/Http/Traits/`)
- `RedirectsWithFlashMessages` - Flash message helpers
- `HandlesStockOperations` - Stock CRUD with deletion checks

### Model Traits (`app/Traits/`)
- `HasSpecSearch` - Reusable search scope for spec models

### Pattern
Extract repeated logic into traits. Traits in `app/Http/Traits/` for controllers, `app/Traits/` for models.

## Database Conventions

### No SoftDeletes
Models use hard delete. Always check relationships before deletion to prevent orphaned records.

### Seeders
- Use `firstOrCreate()` for idempotent seeding of core data
- Use factories for test/demo data

### Factories
- Define state methods like `withoutField()` for nullable variations
- Use `$this->faker` for random data generation

### Pivot Tables
- Use `withTimestamps()` for many-to-many relationships
- Include `quantity` on pivot for spec relationships (e.g., RAM/Disk on PC)

## File Upload Handling

### Storage Pattern
```php
$file = $request->file('file');
$filename = time() . '_' . $file->getClientOriginalName();
$path = $file->storeAs('folder_name', $filename);

// Access later
$filePath = Storage::path($path);
```

### Validation
```php
'file' => 'required|file|mimes:txt,csv,xlsx|max:10240', // Max 10MB
```

### Conventions
- Default disk: `local` (private, in `storage/app/`)
- Timestamped filenames to prevent collisions
- Clean temp files after processing

## Caching Patterns

### Dashboard Stats
```php
$data = Cache::remember('dashboard_stats', 150, fn() => $this->service->getStats());
```

### Job Progress
```php
Cache::put("job:{$jobId}", $progressData, 3600);
$progress = Cache::get("job:{$jobId}", $default);
```

### Configuration
- Cache driver: Configurable via `CACHE_STORE` env (default: file/database)

## Mail/Notification Patterns
Use `NotificationService` for in-app notifications:
```php
$this->notificationService->notifyLeaveRequest($userId, $name, $type, $id);
$this->notificationService->notifyUsersByRole('HR', 'type', 'Title', 'Message', $data);
```

**Notification Types:** `maintenance_due`, `leave_request`, `it_concern`, `medication_request`, `system`

**Documentation:** `docs/NOTIFICATION_SYSTEM.md`

## Git Workflow

### Branch Naming
- `feature/` - New features (e.g., `feature/user-profile`)
- `bugfix/` - Bug fixes (e.g., `bugfix/login-redirect`)
- `hotfix/` - Urgent production fixes (e.g., `hotfix/security-patch`)

### Commit Messages
Follow conventional commits format:
- `feat:` - New feature
- `fix:` - Bug fix
- `docs:` - Documentation changes
- `refactor:` - Code refactoring
- `test:` - Adding/updating tests
- `chore:` - Maintenance tasks

Example: `feat: add QR code bulk download for stations`

## Mobile View Compatibility ✅

All pages in `resources/js/pages/` have mobile-responsive views.

### Mobile View Pattern
```tsx
{/* Desktop Table View */}
<div className="hidden md:block">
    <Table>...</Table>
</div>

{/* Mobile Card View */}
<div className="md:hidden space-y-4">
    {data.map((item) => (
        <div key={item.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
            {/* Card content */}
        </div>
    ))}
</div>
```

### Best Practices
- Use Tailwind breakpoints: `sm:`, `md:`, `lg:`, `xl:`
- Forms: `grid-cols-1 md:grid-cols-2`
- Dialogs: `max-w-[90vw] sm:max-w-md`
- Buttons: `flex-1 sm:flex-none`
- Filters: `flex-col sm:flex-row`

---

**For updates, merge new conventions and patterns here. Ask for feedback if any section is unclear or missing.**

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.11
- inertiajs/inertia-laravel (INERTIA) - v2
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/wayfinder (WAYFINDER) - v0
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v11
- @inertiajs/react (INERTIA) - v2
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `wayfinder-development` — Activates whenever referencing backend routes in frontend components. Use when importing from @/actions or @/routes, calling Laravel routes from TypeScript, or working with Wayfinder route functions.
- `inertia-react-development` — Develops Inertia.js v2 React client-side applications. Activates when creating React pages, forms, or navigation; using &lt;Link&gt;, &lt;Form&gt;, useForm, or router; working with deferred props, prefetching, or polling; or when user mentions React with Inertia, React pages, React forms, or React navigation.
- `tailwindcss-development` — Styles applications using Tailwind CSS v4 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

=== inertia-laravel/v2 rules ===

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scrolling (merging props + `WhenVisible`), lazy loading on scroll, polling, prefetching.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== wayfinder/core rules ===

# Laravel Wayfinder

Wayfinder generates TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

- IMPORTANT: Activate `wayfinder-development` skill whenever referencing backend routes in frontend components.
- Invokable Controllers: `import StorePost from '@/actions/.../StorePostController'; StorePost()`.
- Parameter Binding: Detects route keys (`{post:slug}`) — `show({ slug: "my-post" })`.
- Query Merging: `show(1, { mergeQuery: { page: 2, sort: null } })` merges with current URL, `null` removes params.
- Inertia: Use `.form()` with `<Form>` component or `form.submit(store())` with useForm.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.
</laravel-boost-guidelines>
