# Leave Request Approval Workflow

## Overview
The Leave Request Approval Workflow manages the complete lifecycle of leave requests from submission through approval/denial to cancellation and credit restoration. The system maintains audit trails and ensures proper credit management at each stage.

---

## Workflow States

### Status Enum
```php
// Migration: leave_requests table
$table->enum('status', ['pending', 'approved', 'denied', 'cancelled'])->default('pending');
```

### State Transitions
```
[Employee Submits] → pending
                     ├→ [HR Approves] → approved
                     ├→ [HR Denies] → denied
                     └→ [Employee Cancels] → cancelled
                     
approved → [Employee Cancels] → cancelled (if future date)
```

---

## Workflow Step-by-Step

### Step 1: Submission (Employee)
**File:** `LeaveRequestController@store`

```php
public function store(LeaveRequestRequest $request)
{
    $user = auth()->user();
    
    // Calculate days
    $startDate = Carbon::parse($request->start_date);
    $endDate = Carbon::parse($request->end_date);
    $daysRequested = $this->leaveCreditService->calculateDays($startDate, $endDate);
    
    // Get current attendance points (for audit)
    $attendancePoints = $this->leaveCreditService->getAttendancePoints($user);
    
    // Validate business rules
    $validationData = array_merge($request->validated(), [
        'days_requested' => $daysRequested,
        'credits_year' => now()->year,
    ]);
    
    $errors = $this->leaveCreditService->validateLeaveRequest($user, $validationData);
    
    if (!empty($errors)) {
        return back()->withErrors(['validation' => $errors])->withInput();
    }
    
    // Create leave request (status = pending)
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
        'status' => 'pending', // Initial state
        // Credits not yet deducted
        'credits_deducted' => null,
        'credits_year' => null,
    ]);
    
    return redirect('/leave-requests')
        ->with('success', 'Leave request submitted successfully.');
}
```

**Database State After Submission:**
```
leave_requests:
├─ id: 123
├─ user_id: 5
├─ leave_type: "VL"
├─ start_date: "2025-12-01"
├─ end_date: "2025-12-05"
├─ days_requested: 5.00
├─ reason: "Family vacation"
├─ status: "pending"
├─ reviewed_by: NULL
├─ reviewed_at: NULL
├─ review_notes: NULL
├─ credits_deducted: NULL
└─ attendance_points_at_request: 3.5
```

---

### Step 2a: Approval (HR/Admin)
**File:** `LeaveRequestController@approve`

```php
public function approve(Request $request, LeaveRequest $leaveRequest)
{
    // Authorization check
    if (!in_array(auth()->user()->role, ['HR', 'Admin', 'Super Admin'])) {
        abort(403, 'Unauthorized');
    }
    
    // Validate optional review notes
    $request->validate([
        'review_notes' => ['nullable', 'string', 'max:1000'],
    ]);
    
    $user = $leaveRequest->user;
    $creditedLeaveTypes = ['VL', 'SL', 'BL'];
    $requiresCredits = in_array($leaveRequest->leave_type, $creditedLeaveTypes);
    
    // Start database transaction
    DB::transaction(function () use ($leaveRequest, $request, $user, $requiresCredits) {
        // Update leave request status
        $leaveRequest->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $request->review_notes,
        ]);
        
        // Deduct credits if applicable
        if ($requiresCredits) {
            $year = Carbon::parse($leaveRequest->start_date)->year;
            $deducted = $this->leaveCreditService->deductCredits(
                $user,
                $leaveRequest->days_requested,
                $year
            );
            
            if ($deducted) {
                $leaveRequest->update([
                    'credits_deducted' => $leaveRequest->days_requested,
                    'credits_year' => $year,
                ]);
            }
        }
        
        // TODO: Create attendance records for leave dates
        // $this->createAttendanceRecords($leaveRequest);
    });
    
    return back()->with('success', 'Leave request approved successfully.');
}
```

**Credit Deduction Algorithm:**
```php
// LeaveCreditService@deductCredits
public function deductCredits(User $user, float $daysToDeduct, int $year): bool
{
    $credits = LeaveCredit::forUser($user->id)
        ->forYear($year)
        ->orderBy('month')
        ->get();
    
    $remainingToDeduct = $daysToDeduct;
    
    // Deduct from oldest months first (FIFO)
    foreach ($credits as $credit) {
        if ($remainingToDeduct <= 0) {
            break;
        }
        
        if ($credit->credits_balance > 0) {
            $deduction = min($remainingToDeduct, $credit->credits_balance);
            
            $credit->credits_used += $deduction;
            $credit->credits_balance -= $deduction;
            $credit->save();
            
            $remainingToDeduct -= $deduction;
        }
    }
    
    return $remainingToDeduct == 0;
}
```

**Database State After Approval:**
```
leave_requests:
├─ status: "approved" ← Updated
├─ reviewed_by: 1 ← Admin user ID
├─ reviewed_at: "2025-11-15 14:30:00" ← Timestamp
├─ review_notes: "Approved for year-end vacation"
├─ credits_deducted: 5.00 ← Days deducted
└─ credits_year: 2025 ← Year credits taken from

leave_credits (user_id = 5, year = 2025):
├─ Month 1: balance 1.25 → 0.00 (1.25 deducted)
├─ Month 2: balance 1.25 → 0.00 (1.25 deducted)
├─ Month 3: balance 1.25 → 0.00 (1.25 deducted)
├─ Month 4: balance 1.25 → 0.00 (1.25 deducted)
├─ Month 5: balance 1.25 → 0.50 (0.75 deducted)
└─ Remaining months: unchanged
```

---

### Step 2b: Denial (HR/Admin)
**File:** `LeaveRequestController@deny`

```php
public function deny(Request $request, LeaveRequest $leaveRequest)
{
    // Authorization check
    if (!in_array(auth()->user()->role, ['HR', 'Admin', 'Super Admin'])) {
        abort(403, 'Unauthorized');
    }
    
    // Validate denial reason (REQUIRED)
    $request->validate([
        'review_notes' => ['required', 'string', 'min:10', 'max:1000'],
    ], [
        'review_notes.required' => 'Please provide a reason for denial.',
        'review_notes.min' => 'Denial reason must be at least 10 characters.',
    ]);
    
    // Update leave request
    $leaveRequest->update([
        'status' => 'denied',
        'reviewed_by' => auth()->id(),
        'reviewed_at' => now(),
        'review_notes' => $request->review_notes,
    ]);
    
    // No credit deduction occurs
    // Credits remain unchanged
    
    return back()->with('success', 'Leave request denied.');
}
```

**Database State After Denial:**
```
leave_requests:
├─ status: "denied" ← Updated
├─ reviewed_by: 1
├─ reviewed_at: "2025-11-15 14:35:00"
├─ review_notes: "Insufficient staffing during requested period"
├─ credits_deducted: NULL ← No deduction
└─ credits_year: NULL

leave_credits: Unchanged (no deduction occurred)
```

---

### Step 3: Cancellation (Employee)
**File:** `LeaveRequestController@cancel`

```php
public function cancel(LeaveRequest $leaveRequest)
{
    $user = auth()->user();
    
    // Authorization: Only own requests
    if ($leaveRequest->user_id !== $user->id) {
        abort(403, 'You can only cancel your own leave requests.');
    }
    
    // Business Rules for Cancellation
    $canCancel = $leaveRequest->status === 'pending' || 
                 ($leaveRequest->status === 'approved' && 
                  Carbon::parse($leaveRequest->start_date)->isFuture());
    
    if (!$canCancel) {
        return back()->with('error', 'Cannot cancel this leave request.');
    }
    
    DB::transaction(function () use ($leaveRequest, $user) {
        // Restore credits if they were deducted
        if ($leaveRequest->credits_deducted && $leaveRequest->status === 'approved') {
            $this->leaveCreditService->restoreCredits(
                $user,
                $leaveRequest->credits_deducted,
                $leaveRequest->credits_year
            );
        }
        
        // Update status
        $leaveRequest->update([
            'status' => 'cancelled',
        ]);
    });
    
    return back()->with('success', 'Leave request cancelled successfully.');
}
```

**Credit Restoration Algorithm:**
```php
// LeaveCreditService@restoreCredits
public function restoreCredits(User $user, float $daysToRestore, int $year): bool
{
    $credits = LeaveCredit::forUser($user->id)
        ->forYear($year)
        ->orderBy('month')
        ->get();
    
    $remainingToRestore = $daysToRestore;
    
    // Restore to oldest months first (FIFO, same order as deduction)
    foreach ($credits as $credit) {
        if ($remainingToRestore <= 0) {
            break;
        }
        
        // Calculate how much was originally used from this month
        $originalBalance = $credit->credits_earned - $credit->credits_used;
        $maxRestoration = $credit->credits_earned - $originalBalance;
        
        if ($maxRestoration > 0) {
            $restoration = min($remainingToRestore, $maxRestoration);
            
            $credit->credits_used -= $restoration;
            $credit->credits_balance += $restoration;
            $credit->save();
            
            $remainingToRestore -= $restoration;
        }
    }
    
    return $remainingToRestore == 0;
}
```

**Cancellation Scenarios:**

**Scenario 1: Cancel Pending Request**
```
Before: status = "pending", credits_deducted = NULL
Action: Cancel
After: status = "cancelled"
Result: No credit restoration (none were deducted)
```

**Scenario 2: Cancel Approved Future Request**
```
Before: status = "approved", credits_deducted = 5.00, start_date = 2025-12-01
Action: Cancel on 2025-11-20
After: status = "cancelled"
Result: 5.00 credits restored
```

**Scenario 3: Cannot Cancel (Past/Ongoing)**
```
Before: status = "approved", start_date = 2025-11-10
Action: Attempt cancel on 2025-11-15
Result: Error - "Cannot cancel this leave request"
```

---

## Authorization Matrix

| Action | Employee (Own) | Employee (Others) | HR/Admin |
|--------|---------------|-------------------|----------|
| Submit | ✅ | ❌ | ✅ |
| View Own | ✅ | ❌ | ✅ |
| View All | ❌ | ❌ | ✅ |
| Approve | ❌ | ❌ | ✅ |
| Deny | ❌ | ❌ | ✅ |
| Cancel Pending | ✅ | ❌ | ❌ |
| Cancel Approved (Future) | ✅ | ❌ | ❌ |
| Cancel Approved (Past) | ❌ | ❌ | ❌ |

---

## Audit Trail

### Tracked Information
Every leave request maintains comprehensive audit data:

```php
// Leave Request Model
protected $fillable = [
    // Request details
    'user_id', 'leave_type', 'start_date', 'end_date', 'days_requested',
    'reason', 'team_lead_email', 'campaign_department', 'medical_cert_submitted',
    
    // Audit trail
    'status',
    'reviewed_by',        // User ID of reviewer
    'reviewed_at',        // Timestamp of review
    'review_notes',       // HR's notes/reason
    
    // Credits tracking
    'credits_deducted',   // How many credits were taken
    'credits_year',       // Which year credits were from
    
    // Business context
    'attendance_points_at_request', // Points snapshot at submission
];
```

### Relationships
```php
// LeaveRequest.php
public function user()
{
    return $this->belongsTo(User::class);
}

public function reviewer()
{
    return $this->belongsTo(User::class, 'reviewed_by');
}
```

### Querying Audit History
```php
// Get all approved requests by a specific HR
$approvedByHR = LeaveRequest::where('reviewed_by', $hrUserId)
    ->where('status', 'approved')
    ->get();

// Get requests reviewed today
$todayReviews = LeaveRequest::whereDate('reviewed_at', today())
    ->get();

// Get cancelled approved requests (with restoration)
$cancelledWithRestore = LeaveRequest::where('status', 'cancelled')
    ->whereNotNull('credits_deducted')
    ->get();
```

---

## Frontend Workflow UI

### Index Page (`Leave/Index.tsx`)
**Features:**
- Filter by status (pending/approved/denied/cancelled)
- Filter by leave type
- Color-coded status badges
- Admin view: All requests
- Employee view: Own requests only

```typescript
// Status Badge Colors
const getStatusColor = (status: string) => {
    switch (status) {
        case 'pending': return 'bg-yellow-100 text-yellow-800';
        case 'approved': return 'bg-green-100 text-green-800';
        case 'denied': return 'bg-red-100 text-red-800';
        case 'cancelled': return 'bg-gray-100 text-gray-800';
        default: return 'bg-gray-100 text-gray-800';
    }
};
```

### Show Page (`Leave/Show.tsx`)
**Features:**
- Full request details
- Reviewer information (if reviewed)
- Approve/Deny dialogs (HR/Admin only)
- Cancel button (Employee, conditional)

```typescript
// Approve Dialog
<Dialog>
    <DialogTrigger asChild>
        <Button variant="default">Approve</Button>
    </DialogTrigger>
    <DialogContent>
        <form onSubmit={handleApprove}>
            <Textarea 
                placeholder="Optional notes..."
                value={approveNotes}
                onChange={e => setApproveNotes(e.target.value)}
            />
            <Button type="submit">Confirm Approval</Button>
        </form>
    </DialogContent>
</Dialog>

// Deny Dialog (Notes Required)
<Dialog>
    <DialogTrigger asChild>
        <Button variant="destructive">Deny</Button>
    </DialogTrigger>
    <DialogContent>
        <form onSubmit={handleDeny}>
            <Textarea 
                placeholder="Required: Reason for denial..."
                value={denyReason}
                onChange={e => setDenyReason(e.target.value)}
                required
                minLength={10}
            />
            <Button type="submit" disabled={denyReason.length < 10}>
                Confirm Denial
            </Button>
        </form>
    </DialogContent>
</Dialog>

// Cancel Logic
const canCancel = (
    request.status === 'pending' ||
    (request.status === 'approved' && isAfter(parseISO(request.start_date), new Date()))
);
```

---

## Database Transactions

All state-changing operations use transactions to ensure data consistency:

```php
// Approval with credit deduction
DB::transaction(function () use ($leaveRequest, $user) {
    $leaveRequest->update(['status' => 'approved', ...]);
    $this->leaveCreditService->deductCredits(...);
    $leaveRequest->update(['credits_deducted' => ..., ...]);
});

// Cancellation with credit restoration
DB::transaction(function () use ($leaveRequest, $user) {
    $this->leaveCreditService->restoreCredits(...);
    $leaveRequest->update(['status' => 'cancelled']);
});
```

**Benefits:**
- ✅ Atomic operations (all-or-nothing)
- ✅ Data integrity maintained
- ✅ Rollback on errors

---

## Edge Cases Handled

### Concurrent Approval Attempts
```php
// Laravel's default locking prevents race conditions
DB::transaction(function () {
    $leaveRequest->lockForUpdate()->first();
    // ... approval logic
});
```

### Insufficient Credits After Approval
```php
// Validation occurs before approval
// Credits are checked in real-time
if (!$this->leaveCreditService->deductCredits(...)) {
    throw new \Exception('Insufficient credits');
}
```

### Restoration Exceeding Original Balance
```php
// Restoration algorithm prevents over-restoration
$maxRestoration = $credit->credits_earned - $originalBalance;
$restoration = min($remainingToRestore, $maxRestoration);
```

---

## Key Files

### Backend
- **Controller:** `app/Http/Controllers/LeaveRequestController.php`
  - `store()` - Submission
  - `approve()` - Approval
  - `deny()` - Denial
  - `cancel()` - Cancellation
- **Service:** `app/Services/LeaveCreditService.php`
  - `deductCredits()` - Credit deduction
  - `restoreCredits()` - Credit restoration
- **Model:** `app/Models/LeaveRequest.php`

### Frontend
- **Index:** `resources/js/pages/Leave/Index.tsx` - List with filters
- **Show:** `resources/js/pages/Leave/Show.tsx` - Detail with actions
- **Create:** `resources/js/pages/Leave/Create.tsx` - Submission form

---

## Testing Workflow

### Test Approval Flow
```php
$user = User::factory()->create();
$request = LeaveRequest::factory()->create([
    'user_id' => $user->id,
    'status' => 'pending',
    'days_requested' => 3,
]);

// Approve
$response = $this->actingAs($hr)
    ->post("/leave-requests/{$request->id}/approve");

$request->refresh();
assertEquals('approved', $request->status);
assertNotNull($request->reviewed_at);
assertEquals(3.0, $request->credits_deducted);
```

### Test Cancellation with Restoration
```php
$request = LeaveRequest::factory()->create([
    'status' => 'approved',
    'credits_deducted' => 5.0,
    'credits_year' => 2025,
]);

$balanceBefore = $service->getBalance($user, 2025);

$response = $this->actingAs($user)
    ->post("/leave-requests/{$request->id}/cancel");

$balanceAfter = $service->getBalance($user, 2025);
assertEquals($balanceBefore + 5.0, $balanceAfter);
```

---

*Last updated: December 15, 2025*
