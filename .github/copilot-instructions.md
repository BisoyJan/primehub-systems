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
- **Local Development:**
  - Run `php artisan serve` for backend (default: http://127.0.0.1:8000)
  - Run `npm run dev` for frontend with hot reload (Vite dev server on port 5173)
  - Requires local MySQL and Redis servers
- **Code Quality:**
  - TypeScript: `npm run types` for type checking
  - Formatting: `npm run format` (Prettier with Tailwind plugin)

## Console Tinker Testing

**IMPORTANT:** After implementing any backend PHP code (Models, Controllers, Services, Jobs, etc.), always verify functionality using `php artisan tinker`. This ensures code works correctly before committing.

### When to Use Tinker
- After creating/modifying Models or relationships
- After adding new Service methods
- After implementing Job logic
- After modifying Eloquent queries or scopes
- After adding new helper functions or utilities

### Common Tinker Test Patterns

#### Testing Models & Relationships
```php
# Test model creation
$model = App\Models\RamSpec::factory()->create();

# Test relationships
$pcSpec = App\Models\PcSpec::with(['ramSpecs', 'diskSpecs'])->first();
$pcSpec->ramSpecs;  // Check many-to-many
$pcSpec->station;   // Check belongsTo

# Test scopes
App\Models\User::where('role', 'IT')->get();
App\Models\PcSpec::search('keyword')->get();
```

#### Testing Services
```php
# Instantiate service
$service = app(App\Services\DashboardService::class);

# Call methods
$stats = $service->getStats();
dd($stats);
```

#### Testing Eloquent Queries
```php
# Test complex queries
App\Models\Attendance::whereDate('created_at', today())
    ->with('user')
    ->get();

# Test aggregations
App\Models\Stock::sum('quantity');
App\Models\PcSpec::count();
```

#### Testing Factories
```php
# Test factory states
App\Models\User::factory()->create(['role' => 'HR', 'is_approved' => true]);
App\Models\LeaveRequest::factory()->pending()->create();
```

#### Testing Jobs (Sync)
```php
# Dispatch job synchronously for testing
$jobId = \Illuminate\Support\Str::uuid()->toString();
dispatch_sync(new App\Jobs\GenerateAllPcSpecQRCodesZip($jobId));

# Check cache for progress
Cache::get("pc_spec_qr_zip:{$jobId}");
```

#### Testing Notifications
```php
# Test notification service
$notificationService = app(App\Services\NotificationService::class);
$notificationService->notifyUsersByRole('IT', 'test', 'Test Title', 'Test message');
```

### Tinker Best Practices
1. **Always test after model changes** - Verify relationships load correctly
2. **Test edge cases** - Empty collections, null values, boundary conditions
3. **Verify database state** - Check records were created/updated correctly
4. **Test authorization** - Verify policies work with different user roles
5. **Clean up test data** - Use `DB::rollBack()` or delete test records after testing

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

## Error Handling Patterns

### Backend (Controllers)
- Wrap database operations in `DB::transaction()` for atomicity
- Use `Log::error('ControllerName Action Error: ' . $e->getMessage())` for exceptions
- Return via `$this->redirectWithFlash()` trait method for consistent flash messages

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

### Backend (Jobs)
- Use `Cache::put()` to track progress and error states with standard structure:
```php
Cache::put($cacheKey, [
    'percent' => 50,
    'status' => 'Processing...',
    'finished' => false,
    'error' => false,        // Optional: true on failure
    'downloadUrl' => null,   // Optional: set on completion
], 3600);
```

### Frontend
- Flash messages from backend are automatically handled by `useFlashMessage()` hook
- Toast notifications display via Sonner library

## Logging & Activity Log Conventions

### Activity Logging (Spatie)
Models use `Spatie\Activitylog\Traits\LogsActivity`:
```php
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Model extends BaseModel
{
    use LogsActivity;
    
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
```

### Error Logging Format
- Format: `Log::error('ControllerName Action Error: ' . $e->getMessage())`
- Authentication events logged via `LogAuthentication` listener with IP and user agent

### Configuration
- Activity log retention: 365 days (see `config/activitylog.php`)

## Testing Patterns

### Test Organization
- `tests/Feature/` - Integration tests with database
- `tests/Unit/` - Unit tests for services, policies, models
- All tests use `RefreshDatabase` trait

### Test Conventions
```php
use PHPUnit\Framework\Attributes\Test;

class FeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'IT', 'is_approved' => true]);
    }

    #[Test]
    public function it_does_something(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('route.name'));
        
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Component/Path')
                ->has('data', 5)
            );
    }
}
```

### Key Patterns
- Use `#[Test]` attribute instead of `test_` prefix
- Use `assertInertia()` with callback for page component and props validation
- Create policy tests in `tests/Unit/Policies/` for each role
- Create users with `['role' => 'RoleName', 'is_approved' => true]` for auth testing

## Authorization Patterns

### Permissions Configuration
- Defined in `config/permissions.php` using `module.action` format
- Roles: `super_admin`, `admin`, `team_lead`, `agent`, `hr`, `it`, `utility`
- `super_admin` has `['*']` wildcard for all permissions

### Policy Pattern
```php
class ModelPolicy
{
    public function __construct(protected PermissionService $permissionService) {}

    public function viewAny(User $user): bool
    {
        return $this->permissionService->userHasPermission($user, 'model.view');
    }
}
```

### Controller Authorization
```php
$this->authorize('viewAny', Model::class);
$this->authorize('update', $model);
```

### Route Middleware
```php
Route::resource('models', ModelController::class)
    ->middleware('permission:model.view,model.create,model.edit');
```

### Frontend Components
```tsx
import { Can, CanAny, HasRole } from '@/components/authorization';
import { usePermission } from '@/hooks/use-permission';

// Component-based
<Can permission="model.create">
    <Button>Create</Button>
</Can>

// Hook-based
const { can } = usePermission();
if (can('model.edit')) { /* ... */ }
```

### Documentation
- Full reference: `docs/authorization/QUICK_REFERENCE.md`

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

### Mailable Structure
```php
class StatusUpdated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Model $model, public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Subject: ' . $this->model->status);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.template-name');
    }
}
```

### In-App Notifications
Use `NotificationService` for in-app notifications:
```php
$this->notificationService->notifyLeaveRequest($userId, $name, $type, $id);
$this->notificationService->notifyUsersByRole('HR', 'type', 'Title', 'Message', $data);
```

### Notification Types
- `maintenance_due`, `leave_request`, `it_concern`, `medication_request`, `system`

### Documentation
- Full reference: `docs/NOTIFICATION_SYSTEM.md`

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

**Audit Complete (January 2025):** All pages in `resources/js/pages/` have been checked and updated for mobile view compatibility.

### Mobile View Pattern
All list/index pages follow a consistent responsive pattern:
- **Desktop Table:** Wrapped in `<div className="hidden md:block">` - visible only on medium screens and above
- **Mobile Cards:** Wrapped in `<div className="md:hidden space-y-4">` - visible only on small screens

### Example Structure
```tsx
{/* Desktop Table View */}
<div className="hidden md:block shadow rounded-md overflow-hidden">
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

### Pages with Mobile Views
All index/list pages in the following directories have mobile card views:
- `Account/` - Index, Create, Edit (responsive forms)
- `Admin/ActivityLogs/` - Index
- `Attendance/BiometricRecords/` - Index, Anomalies, Export, Reprocessing, RetentionPolicies, Show
- `Attendance/EmployeeSchedules/` - Index
- `Attendance/Main/` - Index, Calendar, Create, Import, Review
- `Attendance/Points/` - Index
- `Attendance/Uploads/` - Index
- `Computer/RamSpecs/` - Index
- `Computer/DiskSpecs/` - Index
- `Computer/ProcessorSpecs/` - Index
- `Computer/MonitorSpecs/` - Index
- `Computer/PcSpecs/` - Index
- `Computer/Stocks/` - Index
- `FormRequest/ItConcerns/` - Index
- `FormRequest/Leave/` - Index
- `FormRequest/MedicationRequests/` - Index
- `Notifications/` - Index
- `Station/` - Index, Create, Edit
- `dashboard.tsx` - Responsive grid layout

### Best Practices
1. Use Tailwind responsive breakpoints: `sm:`, `md:`, `lg:`, `xl:`
2. For forms: Use `grid-cols-1 md:grid-cols-2` or similar patterns
3. For dialogs: Use `max-w-[90vw] sm:max-w-md` to ensure proper sizing
4. For buttons: Use `flex-1 sm:flex-none` for full-width on mobile, auto on desktop
5. For filters: Stack vertically on mobile with `flex-col sm:flex-row`

---

**For updates, merge new conventions and patterns here. Ask for feedback if any section is unclear or missing.**
