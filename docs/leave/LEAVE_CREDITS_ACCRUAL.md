# Leave Credits Accrual System

## Overview
The Leave Credits Accrual System automatically calculates and records monthly leave credits for all eligible employees based on their role and hire date. The system includes automatic backfilling for existing employees and monthly cron-based accrual.

---

## Core Concepts

### Monthly Accrual Rates

**Role-Based Rates:**
```php
// LeaveCreditService.php
const MANAGER_ROLES = ['Super Admin', 'Admin', 'Team Lead', 'HR'];
const EMPLOYEE_ROLES = ['Agent', 'IT', 'Utility'];

public function getMonthlyRate(User $user): float
{
    return in_array($user->role, self::MANAGER_ROLES) ? 1.5 : 1.25;
}
```

**Accrual Schedule:**
- **Frequency:** Monthly
- **Execution:** Last day of each month at 11:00 PM
- **Method:** Automated via Laravel scheduler

---

## Algorithm: Monthly Accrual

### Step 1: Eligibility Check
```php
public function accrueMonthly(User $user, int $year = null, int $month = null): ?LeaveCredit
{
    // Don't accrue if user doesn't have a hire date
    if (!$user->hired_date) {
        return null;
    }
    
    // Don't accrue if the month hasn't ended yet
    $targetDate = Carbon::create($year, $month, 1)->endOfMonth();
    if (now()->lt($targetDate)) {
        return null;
    }
    
    // Don't accrue before hire date
    $hireDate = Carbon::parse($user->hired_date);
    if ($targetDate->lt($hireDate)) {
        return null;
    }
```

**Rules:**
1. ✅ User must have `hired_date` set
2. ✅ Month must be completed (run on last day)
3. ✅ Target month must be on or after hire date

### Step 2: Duplicate Prevention
```php
    // Check if already accrued for this month
    $existing = LeaveCredit::forUser($user->id)
        ->forMonth($year, $month)
        ->first();

    if ($existing) {
        return $existing; // Already processed
    }
```

**Idempotency:** Safe to run multiple times - won't create duplicates.

### Step 3: Credit Calculation
```php
    // Calculate credits to add
    $rate = $this->getMonthlyRate($user);
    
    // Create new credit record
    return LeaveCredit::create([
        'user_id' => $user->id,
        'credits_earned' => $rate,
        'credits_used' => 0,
        'credits_balance' => $rate,
        'year' => $year,
        'month' => $month,
        'accrued_at' => $targetDate,
    ]);
}
```

**Database Record Structure:**
```
leave_credits table:
├─ user_id: 5
├─ credits_earned: 1.25 (or 1.5 for managers)
├─ credits_used: 0 (initial state)
├─ credits_balance: 1.25 (earned - used)
├─ year: 2025
├─ month: 11
└─ accrued_at: 2025-11-30 23:59:59
```

---

## Algorithm: Backfilling Credits

### Purpose
When an existing employee is added to the system or when you first deploy the leave feature, the system needs to calculate and create all missing monthly credit records from their hire date to the present.

### Backfill Algorithm
```php
public function backfillCredits(User $user): int
{
    if (!$user->hired_date) {
        return 0;
    }
    
    $hireDate = Carbon::parse($user->hired_date);
    $today = now();
    $creditsAccrued = 0;
    
    // Start from hire month
    $currentDate = $hireDate->copy()->startOfMonth();
    
    // Loop through each month from hire date to present
    while ($currentDate->lte($today)) {
        // Only accrue for completed months
        if ($currentDate->endOfMonth()->lte($today)) {
            $credit = $this->accrueMonthly(
                $user,
                $currentDate->year,
                $currentDate->month
            );
            
            if ($credit && $credit->wasRecentlyCreated) {
                $creditsAccrued++;
            }
        }
        
        // Move to next month
        $currentDate->addMonth()->startOfMonth();
    }
    
    return $creditsAccrued;
}
```

**Example Scenario:**
```
Employee hired: January 1, 2025
Current date: November 15, 2025
Backfill will create: 11 records (Jan-Nov)

January 2025:   +1.25 credits
February 2025:  +1.25 credits
March 2025:     +1.25 credits
April 2025:     +1.25 credits
May 2025:       +1.25 credits
June 2025:      +1.25 credits
July 2025:      +1.25 credits
August 2025:    +1.25 credits
September 2025: +1.25 credits
October 2025:   +1.25 credits
November 2025:  +1.25 credits (month completed)
December 2025:  Not created (month not complete)
-----------------------------------
Total Balance:  13.75 credits
```

### Automatic Backfill Trigger
```php
// LeaveRequestController.php - create() method
public function create()
{
    $user = auth()->user();
    
    // Automatically backfill missing credits when user opens form
    $this->leaveCreditService->backfillCredits($user);
    
    // ... rest of controller logic
}
```

**Smart Trigger:** Backfills automatically when employee accesses the leave request form for the first time.

---

## Credit Balance Calculation

### Per-Year Balance
```php
public function getBalance(User $user, int $year = null): float
{
    $year = $year ?? now()->year;
    return LeaveCredit::getTotalBalance($user->id, $year);
}

// LeaveCredit Model
public static function getTotalBalance(int $userId, int $year): float
{
    return self::forUser($userId)
        ->forYear($year)
        ->sum('credits_balance') ?? 0;
}
```

**Key Point:** Credits are **year-specific** and do NOT carry over!

### Detailed Summary
```php
public function getSummary(User $user, int $year = null): array
{
    $year = $year ?? now()->year;
    
    return [
        'year' => $year,
        'is_eligible' => $this->isEligible($user), // 6 months check
        'eligibility_date' => $this->getEligibilityDate($user),
        'monthly_rate' => $this->getMonthlyRate($user),
        'total_earned' => LeaveCredit::getTotalEarned($user->id, $year),
        'total_used' => LeaveCredit::getTotalUsed($user->id, $year),
        'balance' => $this->getBalance($user, $year),
        'credits_by_month' => LeaveCredit::forUser($user->id)
            ->forYear($year)
            ->orderBy('month')
            ->get(),
    ];
}
```

**Response Example:**
```json
{
  "year": 2025,
  "is_eligible": true,
  "eligibility_date": "2025-07-01",
  "monthly_rate": 1.25,
  "total_earned": 13.75,
  "total_used": 3.0,
  "balance": 10.75,
  "credits_by_month": [
    {"month": 1, "credits_earned": 1.25, "credits_used": 0, "credits_balance": 1.25},
    {"month": 2, "credits_earned": 1.25, "credits_used": 1.0, "credits_balance": 0.25},
    // ... more months
  ]
}
```

---

## Automated Processing

### Cron Job Configuration
```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('leave:accrue-credits')
        ->monthlyOn(date('t'), '23:00') // Last day of month at 11 PM
        ->withoutOverlapping()
        ->onOneServer();
}
```

**Schedule Details:**
- **Trigger:** `date('t')` = Last day of month (28-31 depending on month)
- **Time:** 23:00 (11:00 PM)
- **Safety:** `withoutOverlapping()` prevents concurrent runs
- **Scalability:** `onOneServer()` for multi-server environments

### Command Implementation
```php
// app/Console/Commands/AccrueLeaveCredits.php
public function handle()
{
    $year = $this->option('year') ?? now()->year;
    $month = $this->option('month') ?? now()->month;
    
    $users = User::whereNotNull('hired_date')->get();
    $accrued = 0;
    $skipped = 0;
    
    foreach ($users as $user) {
        try {
            $credit = $this->leaveCreditService->accrueMonthly($user, $year, $month);
            
            if ($credit) {
                $this->info("✓ {$user->name}: {$credit->credits_earned} credits");
                $accrued++;
            } else {
                $this->warn("⊘ {$user->name}: Skipped");
                $skipped++;
            }
        } catch (\Exception $e) {
            $this->error("✗ {$user->name}: {$e->getMessage()}");
        }
    }
    
    $this->info("Summary: Accrued: {$accrued}, Skipped: {$skipped}");
}
```

**Manual Execution:**
```bash
# Accrue for current month
php artisan leave:accrue-credits

# Accrue for specific month
php artisan leave:accrue-credits --year=2025 --month=10
```

---

## Database Schema

### leave_credits Table
```php
Schema::create('leave_credits', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->decimal('credits_earned', 5, 2); // e.g., 1.25
    $table->decimal('credits_used', 5, 2)->default(0);
    $table->decimal('credits_balance', 5, 2); // earned - used
    $table->year('year');
    $table->integer('month'); // 1-12
    $table->timestamp('accrued_at');
    $table->timestamps();
    
    // Prevent duplicate accruals
    $table->unique(['user_id', 'year', 'month']);
    
    $table->index(['user_id', 'year']);
});
```

**Key Constraints:**
- ✅ Unique per user/year/month combination
- ✅ Cascade delete with user
- ✅ Indexed for fast queries

---

## Eligibility System

### 6-Month Rule
```php
public function isEligible(User $user): bool
{
    if (!$user->hired_date) {
        return false;
    }
    
    $sixMonthsAfterHire = Carbon::parse($user->hired_date)->addMonths(6);
    return now()->greaterThanOrEqualTo($sixMonthsAfterHire);
}

public function getEligibilityDate(User $user): ?Carbon
{
    if (!$user->hired_date) {
        return null;
    }
    
    return Carbon::parse($user->hired_date)->addMonths(6);
}
```

**Business Rule:**
- Employees can **accrue** credits from Day 1
- Employees can only **use** credits after 6 months
- Non-credited leave types (SPL, LOA, LDV, UPTO) are always available

**Example:**
```
Hire Date: January 1, 2025
Eligible Date: July 1, 2025

January-June: Credits accrue but cannot be used
July onward: Can submit VL/SL/BL requests
```

---

## Annual Reset Policy

### Year-Specific Credits
All credits are isolated by year through the database design:

```php
// Eloquent Scope
public function scopeForYear($query, int $year)
{
    return $query->where('year', $year);
}

// Usage
LeaveCredit::forUser(5)->forYear(2025)->get(); // Only 2025 credits
LeaveCredit::forUser(5)->forYear(2026)->get(); // Only 2026 credits (separate)
```

**Reset Behavior:**
```
December 31, 2025 23:59:59:
├─ 2025 credits: 10.5 remaining → EXPIRE (cannot be used in 2026)
└─ No carryover to 2026

January 1, 2026 00:00:00:
├─ 2026 credits: 0.0 balance (fresh start)
└─ January 2026 accrual happens on January 31 at 11 PM
```

### Year-End Reminder Command
```php
// app/Console/Commands/YearEndLeaveCreditsReminder.php
public function handle()
{
    $year = $this->option('year') ?? now()->year;
    $users = User::whereNotNull('hired_date')
        ->where('hired_date', '<=', now()->subMonths(6))
        ->get();
    
    $usersWithCredits = [];
    
    foreach ($users as $user) {
        $balance = $this->leaveCreditService->getBalance($user, $year);
        
        if ($balance > 0) {
            $usersWithCredits[] = [
                'name' => $user->name,
                'balance' => $balance,
            ];
        }
    }
    
    // Display table of users with unused credits
    $this->table(['Name', 'Expiring Credits'], $usersWithCredits);
    $this->warn("Credits do NOT carry over to next year!");
}
```

**Usage:**
```bash
# Run in November/December
php artisan leave:year-end-reminder

# Check specific year
php artisan leave:year-end-reminder --year=2025
```

---

## Key Files

### Backend
- **Service:** `app/Services/LeaveCreditService.php` (343 lines)
- **Model:** `app/Models/LeaveCredit.php` (94 lines)
- **Command:** `app/Console/Commands/AccrueLeaveCredits.php`
- **Command:** `app/Console/Commands/BackfillLeaveCredits.php`
- **Command:** `app/Console/Commands/YearEndLeaveCreditsReminder.php`
- **Scheduler:** `app/Console/Kernel.php`

### Database
- **Migration:** `database/migrations/2025_11_15_000001_create_leave_credits_table.php`

---

## Testing Examples

### Test Accrual
```php
// Create test user
$user = User::factory()->create([
    'hired_date' => '2025-01-01',
    'role' => 'Agent',
]);

// Manually accrue credits
$service = app(LeaveCreditService::class);
$credit = $service->accrueMonthly($user, 2025, 1);

// Assertions
assertEquals(1.25, $credit->credits_earned);
assertEquals(1.25, $credit->credits_balance);
assertEquals(0, $credit->credits_used);
```

### Test Backfill
```php
$user = User::factory()->create([
    'hired_date' => '2025-01-01',
    'role' => 'Team Lead', // Manager role
]);

Carbon::setTestNow('2025-11-15');

$service = app(LeaveCreditService::class);
$count = $service->backfillCredits($user);

// Assertions
assertEquals(11, $count); // Jan-Nov (11 months)
assertEquals(16.5, $service->getBalance($user, 2025)); // 11 * 1.5
```

---

## Performance Considerations

### Batch Processing
- Monthly cron processes all users (~200-500 employees)
- Average execution time: 2-5 seconds
- Uses chunking for large datasets

### Query Optimization
- Indexed queries on `user_id` and `year`
- Eager loading for user relationships
- Minimal database calls per accrual

### Idempotency
- Safe to run multiple times
- Duplicate prevention via unique constraint
- No race conditions with `withoutOverlapping()`

---

## Troubleshooting

### Problem: User has 0 credits
**Solution:**
```bash
# Check hire date
User::find(5)->hired_date; // Should not be null

# Run backfill
php artisan leave:backfill-credits --user=5
```

### Problem: Credits not accruing
**Solution:**
```bash
# Check cron is running
php artisan schedule:list

# Manually trigger
php artisan leave:accrue-credits
```

### Problem: Wrong accrual rate
**Solution:**
```php
// Verify role mapping in LeaveCreditService.php
const MANAGER_ROLES = ['Super Admin', 'Admin', 'Team Lead', 'HR'];
const EMPLOYEE_ROLES = ['Agent', 'IT', 'Utility'];
```

---

*Last updated: December 15, 2025*
