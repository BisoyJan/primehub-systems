# Leave Request Validation Logic

## Overview
The Leave Request system implements comprehensive business rules and validation logic to ensure leave requests comply with company policies before submission and approval. Validation happens at **three layers**: frontend (real-time), backend (request validation), and service layer (business logic).

---

## Validation Layers

### Layer 1: Frontend Real-Time Validation (React)
**File:** `resources/js/pages/Leave/Create.tsx`

Provides immediate feedback to users before form submission.

```typescript
useEffect(() => {
    const warnings: string[] = [];
    
    // Check eligibility (6 months)
    if (!creditsSummary.is_eligible && ['VL', 'SL', 'BL'].includes(data.leave_type)) {
        warnings.push(
            `You are not eligible to use leave credits yet. ` +
            `Eligible on ${format(parseISO(creditsSummary.eligibility_date!), 'MMMM d, yyyy')}.`
        );
    }
    
    // Check 2-week advance notice (only for VL and BL, not SL as it's unpredictable)
    if (data.start_date && ['VL', 'BL'].includes(data.leave_type)) {
        const start = parseISO(data.start_date);
        const twoWeeks = parseISO(twoWeeksFromNow);
        if (start < twoWeeks) {
            warnings.push(
                `Leave must be requested at least 2 weeks in advance. ` +
                `Earliest date: ${format(twoWeeks, 'MMMM d, yyyy')}`
            );
        }
    }
    
    // Check attendance points (VL/BL only)
    if (['VL', 'BL'].includes(data.leave_type) && attendancePoints > 6) {
        warnings.push(
            `You have ${attendancePoints} attendance points (must be ≤6 for Vacation/Bereavement Leave).`
        );
    }
    
    // Check recent absence (VL/BL only)
    if (['VL', 'BL'].includes(data.leave_type) && hasRecentAbsence) {
        warnings.push(
            `You had an absence in the last 30 days. ` +
            `Next eligible date: ${format(parseISO(nextEligibleLeaveDate!), 'MMMM d, yyyy')}`
        );
    }
    
    // Check credits balance
    if (['VL', 'SL', 'BL'].includes(data.leave_type) && calculatedDays > 0) {
        if (creditsSummary.balance < calculatedDays) {
            warnings.push(
                `Insufficient leave credits. ` +
                `Available: ${creditsSummary.balance} days, Requested: ${calculatedDays} days`
            );
        }
    }
    
    setValidationWarnings(warnings);
}, [data.leave_type, data.start_date, creditsSummary, attendancePoints, 
    hasRecentAbsence, nextEligibleLeaveDate, calculatedDays]);
```

**Submit Button Logic:**
```typescript
<Button 
    type="submit" 
    disabled={processing || validationWarnings.length > 0}
>
    {processing ? 'Submitting...' : 'Submit Leave Request'}
</Button>
```

---

### Layer 2: Form Request Validation (Laravel)
**File:** `app/Http/Requests/LeaveRequestRequest.php`

Validates form data structure and basic rules.

```php
public function rules(): array
{
    return [
        'leave_type' => ['required', 'string', 'in:VL,SL,BL,SPL,LOA,LDV,UPTO'],
        'start_date' => ['required', 'date', 'after_or_equal:today'],
        'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        'reason' => ['required', 'string', 'min:10', 'max:1000'],
        'team_lead_email' => ['required', 'email'],
        'campaign_department' => ['required', 'string'],
        'medical_cert_submitted' => ['sometimes', 'boolean'],
    ];
}

public function messages(): array
{
    return [
        'leave_type.required' => 'Please select a leave type.',
        'leave_type.in' => 'Invalid leave type selected.',
        'start_date.after_or_equal' => 'Start date must be today or later.',
        'end_date.after_or_equal' => 'End date must be on or after start date.',
        'reason.min' => 'Reason must be at least 10 characters.',
        'reason.max' => 'Reason cannot exceed 1000 characters.',
        'team_lead_email.required' => 'Please select your team lead.',
        'campaign_department.required' => 'Please select your campaign/department.',
    ];
}
```

**Basic Validations:**
- ✅ Leave type is valid enum value
- ✅ Start date is not in the past
- ✅ End date is not before start date
- ✅ Reason is meaningful (10-1000 chars)
- ✅ Team lead and campaign are provided

---

### Layer 3: Business Logic Validation (Service)
**File:** `app/Services/LeaveCreditService.php`

Implements complex business rules and policy checks.

```php
public function validateLeaveRequest(User $user, array $data): array
{
    $errors = [];
    $leaveType = $data['leave_type'];
    $startDate = Carbon::parse($data['start_date']);
    $endDate = Carbon::parse($data['end_date']);
    $daysRequested = $this->calculateDays($startDate, $endDate);
    $year = $data['credits_year'] ?? now()->year;
    
    // Leave types that require credits
    $creditedLeaveTypes = ['VL', 'SL', 'BL'];
    $requiresCredits = in_array($leaveType, $creditedLeaveTypes);
    
    // ============================================
    // RULE 1: Eligibility Check (6 months)
    // ============================================
    if ($requiresCredits && !$this->isEligible($user)) {
        $eligibilityDate = $this->getEligibilityDate($user);
        $errors[] = "You are not eligible to use leave credits yet. " .
                    "You will be eligible on " . 
                    $eligibilityDate->format('F d, Y') . 
                    " (6 months after your hire date).";
    }
    
    // ============================================
    // RULE 2: Advance Notice (2 weeks for VL/BL only)
    // ============================================
    if (in_array($leaveType, ['VL', 'BL'])) {
        $twoWeeksFromNow = now()->addWeeks(2);
        if ($startDate->lt($twoWeeksFromNow)) {
            $errors[] = "Leave must be requested at least 2 weeks in advance. " .
                        "Earliest eligible date: " . 
                        $twoWeeksFromNow->format('F d, Y');
        }
    }
    
    // ============================================
    // RULE 3: Attendance Points Check (VL/BL only)
    // ============================================
    if (in_array($leaveType, ['VL', 'BL'])) {
        $attendancePoints = $this->getAttendancePoints($user);
        if ($attendancePoints > 6) {
            $errors[] = "You have {$attendancePoints} attendance points. " .
                        "Vacation and Bereavement Leave require ≤6 attendance points.";
        }
    }
    
    // ============================================
    // RULE 4: Recent Absence Check (VL/BL only)
    // ============================================
    if (in_array($leaveType, ['VL', 'BL'])) {
        if ($this->hasRecentAbsence($user, $startDate)) {
            $nextEligibleDate = $this->getNextEligibleLeaveDate($user);
            $errors[] = "You had an absence in the last 30 days. " .
                        "You can apply for Vacation/Bereavement Leave on " .
                        $nextEligibleDate->format('F d, Y') . 
                        " (30 days after last absence).";
        }
    }
    
    // ============================================
    // RULE 5: Credits Balance Check
    // ============================================
    if ($requiresCredits) {
        $balance = $this->getBalance($user, $year);
        if ($balance < $daysRequested) {
            $errors[] = "Insufficient leave credits. " .
                        "Available: {$balance} days, Requested: {$daysRequested} days.";
        }
    }
    
    return $errors;
}
```

---

## Leave Type Specific Rules

### VL (Vacation Leave) - Most Restrictive
```
✅ Requires 6 months employment
✅ Requires 2 weeks advance notice
✅ Requires ≤6 attendance points
✅ Requires no absence in last 30 days
✅ Deducts from leave credits
```

### SL (Sick Leave)
```
✅ Requires 6 months employment
❌ No advance notice required (illness is unpredictable)
✅ No attendance points check
✅ No recent absence check
✅ Deducts from leave credits
⚠️ Medical certificate checkbox (optional but recommended)
```

### BL (Bereavement Leave)
```
✅ Similar to Vacation Leave
✅ Requires 6 months employment
✅ Requires 2 weeks advance notice
✅ Requires ≤6 attendance points
✅ Requires no absence in last 30 days
✅ Deducts from leave credits
```

### SPL, LOA, LDV, UPTO (Non-Credited)
```
✅ No eligibility check
✅ No advance notice requirement
✅ No attendance points check
✅ No recent absence check
❌ Does NOT deduct from leave credits
```

---

## Detailed Rule Algorithms

### Rule 1: 6-Month Eligibility
```php
public function isEligible(User $user): bool
{
    if (!$user->hired_date) {
        return false;
    }
    
    $sixMonthsAfterHire = Carbon::parse($user->hired_date)->addMonths(6);
    return now()->greaterThanOrEqualTo($sixMonthsAfterHire);
}
```

**Example:**
```
Hire Date: January 15, 2025
Eligibility Date: July 15, 2025

Request on July 10, 2025: ❌ Rejected (5 days early)
Request on July 15, 2025: ✅ Allowed
Request on July 20, 2025: ✅ Allowed
```

### Rule 2: Two-Week Advance Notice (VL/BL only)
```php
// In controller
$twoWeeksFromNow = now()->addWeeks(2);

// In validation (only for VL and BL, not SL as sickness is unpredictable)
if (in_array($leaveType, ['VL', 'BL']) && $startDate->lt($twoWeeksFromNow)) {
    $errors[] = "Must request 2 weeks in advance";
}
```

**Example:**
```
Today: November 15, 2025
Two weeks from now: November 29, 2025

VL Request for November 20: ❌ Rejected (9 days notice)
VL Request for November 29: ✅ Allowed (exactly 2 weeks)
VL Request for December 5: ✅ Allowed (>2 weeks)

SL Request for November 20: ✅ Allowed (no advance notice required for sick leave)
```

### Rule 3: Attendance Points Check
```php
public function getAttendancePoints(User $user): float
{
    return AttendancePoint::where('user_id', $user->id)
        ->where('is_expired', false)
        ->sum('points');
}
```

**Logic:**
```
Current non-expired points = 3.5
Request Vacation Leave: ✅ Allowed (3.5 ≤ 6)

Current non-expired points = 6.25
Request Vacation Leave: ❌ Rejected (6.25 > 6)
Request Sick Leave: ✅ Allowed (no points check for SL)
```

### Rule 4: Recent Absence Check
```php
public function hasRecentAbsence(User $user, Carbon $fromDate = null): bool
{
    $fromDate = $fromDate ?? now();
    $thirtyDaysAgo = $fromDate->copy()->subDays(30);
    
    return Attendance::where('user_id', $user->id)
        ->where('shift_date', '>=', $thirtyDaysAgo)
        ->where('shift_date', '<=', $fromDate)
        ->where('status', 'absent')
        ->exists();
}

public function getNextEligibleLeaveDate(User $user): ?Carbon
{
    $lastAbsence = Attendance::where('user_id', $user->id)
        ->where('status', 'absent')
        ->orderBy('shift_date', 'desc')
        ->first();
    
    if (!$lastAbsence) {
        return now(); // No absences, eligible immediately
    }
    
    return Carbon::parse($lastAbsence->shift_date)->addDays(30);
}
```

**Example:**
```
Last absence: October 20, 2025
Eligible again: November 19, 2025

Request on November 10: ❌ Rejected (19 days after absence)
Request on November 19: ✅ Allowed (exactly 30 days)
Request on November 25: ✅ Allowed (35 days after)
```

### Rule 5: Credits Balance Check
```php
if ($requiresCredits) {
    $balance = $this->getBalance($user, $year);
    if ($balance < $daysRequested) {
        $errors[] = "Insufficient credits";
    }
}
```

**Example:**
```
Available Balance: 8.5 days
Request Duration: 5 days
Result: ✅ Allowed (8.5 - 5 = 3.5 remaining)

Available Balance: 2.0 days
Request Duration: 3 days
Result: ❌ Rejected (insufficient credits)
```

---

## Controller Validation Flow

```php
// LeaveRequestController.php - store() method
public function store(LeaveRequestRequest $request)
{
    $user = auth()->user();
    
    // Calculate days
    $startDate = Carbon::parse($request->start_date);
    $endDate = Carbon::parse($request->end_date);
    $daysRequested = $this->leaveCreditService->calculateDays($startDate, $endDate);
    
    // Get current state
    $attendancePoints = $this->leaveCreditService->getAttendancePoints($user);
    
    // Prepare validation data
    $validationData = array_merge($request->validated(), [
        'days_requested' => $daysRequested,
        'credits_year' => now()->year,
    ]);
    
    // Run comprehensive validation
    $errors = $this->leaveCreditService->validateLeaveRequest($user, $validationData);
    
    if (!empty($errors)) {
        return back()->withErrors(['validation' => $errors])->withInput();
    }
    
    // Create leave request
    $leaveRequest = LeaveRequest::create([
        'user_id' => $user->id,
        'leave_type' => $request->leave_type,
        'start_date' => $request->start_date,
        'end_date' => $request->end_date,
        'days_requested' => $daysRequested,
        'reason' => $request->reason,
        'team_lead_email' => $request->team_lead_email,
        'campaign_department' => $request->campaign_department,
        'medical_cert_submitted' => $request->medical_cert_submitted ?? false,
        'attendance_points_at_request' => $attendancePoints,
        'status' => 'pending',
    ]);
    
    return redirect('/leave-requests')->with('success', 'Leave request submitted');
}
```

---

## Error Response Examples

### Frontend Validation Errors
```typescript
// Displayed in real-time alert before submission
[
  "You have 7.5 attendance points (must be ≤6 for Vacation Leave).",
  "Leave must be requested at least 2 weeks in advance. Earliest date: November 29, 2025",
  "Insufficient leave credits. Available: 3.5 days, Requested: 5 days"
]
```

### Backend Validation Errors
```php
// Returned to frontend if validation fails
[
  'validation' => [
    "You are not eligible to use leave credits yet. You will be eligible on July 15, 2025.",
    "You had an absence in the last 30 days. You can apply on November 19, 2025."
  ]
]
```

---

## Edge Cases Handled

### Cross-Year Requests
```php
// Request: Dec 25, 2025 to Jan 5, 2026
// System uses 2025 credits (year of start_date)

$year = Carbon::parse($request->start_date)->year; // 2025
$balance = $this->getBalance($user, 2025); // Check 2025 credits
```

### Same Day Requests
```php
// Start: Nov 15, End: Nov 15 (1 day)
$days = differenceInDays($end, $start) + 1; // 0 + 1 = 1 day
```

### Partial Day Handling
```php
// System works in full days only
// 2.5 days requested = 2.5 credits deducted
$daysRequested = $startDate->diffInDays($endDate) + 1;
```

---

## Key Files

### Backend
- **Service:** `app/Services/LeaveCreditService.php` - `validateLeaveRequest()` method
- **Request:** `app/Http/Requests/LeaveRequestRequest.php` - Form validation
- **Controller:** `app/Http/Controllers/LeaveRequestController.php` - `store()` method
- **Models:** `app/Models/Attendance.php`, `app/Models/AttendancePoint.php`

### Frontend
- **Create Form:** `resources/js/pages/Leave/Create.tsx` - Real-time validation
- **Index:** `resources/js/pages/Leave/Index.tsx` - Status display
- **Show:** `resources/js/pages/Leave/Show.tsx` - Detail view

---

## Testing Validation

### Test Eligibility
```php
$user = User::factory()->create([
    'hired_date' => now()->subMonths(5), // Not eligible yet
]);

$service = app(LeaveCreditService::class);
assertFalse($service->isEligible($user));

// Fast-forward time
Carbon::setTestNow(now()->addMonth());
assertTrue($service->isEligible($user));
```

### Test Attendance Points
```php
AttendancePoint::factory()->create([
    'user_id' => $user->id,
    'points' => 7.0,
    'is_expired' => false,
]);

$errors = $service->validateLeaveRequest($user, [
    'leave_type' => 'VL',
    // ... other data
]);

assertContains('7 attendance points', implode(' ', $errors));
```

### Test Recent Absence
```php
Attendance::factory()->create([
    'user_id' => $user->id,
    'shift_date' => now()->subDays(15),
    'status' => 'absent',
]);

$errors = $service->validateLeaveRequest($user, [
    'leave_type' => 'VL',
    'start_date' => now()->addWeeks(3)->format('Y-m-d'),
    // ... other data
]);

assertContains('absence in the last 30 days', implode(' ', $errors));
```

---

## Performance Considerations

### Query Optimization
- Single queries per validation rule
- Cached attendance point sum
- Indexed database lookups

### Frontend Efficiency
- Debounced validation (useEffect dependencies)
- Warnings computed only when relevant fields change
- No API calls for real-time validation

---

*Last updated: November 15, 2025*
