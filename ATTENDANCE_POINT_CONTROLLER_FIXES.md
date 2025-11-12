# AttendancePointController Test Failures - Fix Required

## Summary
**Current Status:** 11 failed, 5 passed out of 16 controller tests

## Issues Found

### 1. **Missing Routes** (2 failures)
- ❌ `attendance-points.export` - Route not defined
- ❌ `attendance-points.statistics` - Route not defined (test expects it)

**Fix Required:**
```php
// routes/web.php - Add these routes:
Route::get('/{user}/statistics', [AttendancePointController::class, 'statistics'])->name('statistics');
Route::get('/{user}/export', [AttendancePointController::class, 'export'])->name('export');
```

### 2. **Wrong HTTP Method** (1 failure)
- ❌ `unexcuse` route uses POST but test expects DELETE
- Current: `Route::post('/{point}/unexcuse', ...)`
- Expected: `Route::delete('/{point}/unexcuse', ...)`

**Fix Required:**
```php
// routes/web.php - Change:
Route::delete('/{point}/unexcuse', [AttendancePointController::class, 'unexcuse'])->name('unexcuse');
```

### 3. **Missing Authorization Middleware** (4 failures)
Tests expect:
- Admin users can excuse/unexcuse points
- Regular users CANNOT excuse/unexcuse points (should get 403)
- Regular users can view their OWN points
- Regular users CANNOT view OTHER users' points (should get 403)

**Current Issue:** No authorization checks in controller

**Fix Required:**
Add middleware or policy checks:
```php
// Option 1: Add to routes
Route::middleware('can:manage-points')->group(function () {
    Route::post('/{point}/excuse', ...);
    Route::delete('/{point}/unexcuse', ...);
});

// Option 2: Add authorization in controller methods
public function excuse(Request $request, AttendancePoint $point)
{
    if (!in_array($request->user()->role, ['Admin', 'Super Admin', 'HR'])) {
        abort(403, 'Unauthorized to excuse points');
    }
    // ... rest of method
}

public function show(User $user, Request $request)
{
    // Users can only view their own points unless they're admin
    if ($request->user()->id !== $user->id && 
        !in_array($request->user()->role, ['Admin', 'Super Admin', 'HR'])) {
        abort(403, 'Unauthorized to view other user points');
    }
    // ... rest of method
}
```

### 4. **Wrong Route Signature** (1 failure)
- Test: `route('attendance-points.index', $targetUser)` - expects user parameter
- Current: `Route::get('/', ...)` - no parameters, uses query string `?user_id=...`

**Options:**
- **Option A:** Change route to accept user parameter:
  ```php
  Route::get('/{user}', [AttendancePointController::class, 'index'])->name('index');
  // Then move show route to be more specific: /user/{user}/details
  ```
  
- **Option B:** Update test to use query string:
  ```php
  $response = $this->actingAs($user)->get(route('attendance-points.index', ['user_id' => $targetUser->id]));
  ```

### 5. **Missing Query Filters** (2 failures)
Controller doesn't handle these filters:
- ❌ `expiring_soon` - Should filter points expiring within 30 days
- ❌ `gbro_eligible` - Should filter points eligible for GBRO

**Fix Required in Controller:**
```php
public function index(Request $request)
{
    $query = AttendancePoint::with(['user', 'attendance', 'excusedBy'])
        ->orderBy('shift_date', 'desc');

    // ... existing filters ...

    // Add expiring_soon filter
    if ($request->boolean('expiring_soon')) {
        $query->where('is_expired', false)
              ->where('expires_at', '<=', Carbon::now()->addDays(30))
              ->where('expires_at', '>=', Carbon::now());
    }

    // Add gbro_eligible filter
    if ($request->boolean('gbro_eligible')) {
        $query->eligibleForGbro(); // Uses the scope from model
    }

    $points = $query->paginate(50);
    // ...
}
```

### 6. **Missing Controller Methods** (2 methods needed)

#### `statistics()` method:
```php
public function statistics(User $user)
{
    $points = AttendancePoint::where('user_id', $user->id)->get();
    
    return response()->json([
        'total_points' => $points->where('is_excused', false)->where('is_expired', false)->sum('points'),
        'active_points' => $points->where('is_excused', false)->where('is_expired', false)->count(),
        'expired_points' => $points->where('is_expired', true)->count(),
        'excused_points' => $points->where('is_excused', true)->count(),
    ]);
}
```

#### `export()` method:
```php
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\AttendancePointsExport; // You'll need to create this

public function export(User $user)
{
    return Excel::download(
        new AttendancePointsExport($user), 
        "attendance-points-{$user->id}-" . now()->format('Y-m-d') . ".xlsx"
    );
}
```

## Test Expectations Summary

### Routes Expected:
```php
Route::get('/', ...)->name('index');                          // ✅ EXISTS
Route::get('/{user}', ...)->name('show');                     // ✅ EXISTS  
Route::get('/{user}/statistics', ...)->name('statistics');    // ❌ MISSING
Route::get('/{user}/export', ...)->name('export');            // ❌ MISSING
Route::post('/{point}/excuse', ...)->name('excuse');          // ✅ EXISTS
Route::delete('/{point}/unexcuse', ...)->name('unexcuse');    // ⚠️ WRONG METHOD (POST)
```

### Query Parameters Expected:
- `status` - Filter by active/expired/excused ✅
- `point_type` - Filter by type (tardy, etc.) ✅
- `from_date` / `to_date` - Date range ✅
- `expiring_soon` - Boolean filter ❌
- `gbro_eligible` - Boolean filter ❌

### Authorization Expected:
- Admin roles can access all endpoints ✅ (no checks = everyone can access)
- Regular users:
  - CAN view their own points ⚠️ (no check)
  - CANNOT view other users' points ❌ (no check)
  - CANNOT excuse points ❌ (no check)
  - CANNOT unexcuse points ❌ (no check)

## Priority Fixes

### High Priority (Breaks functionality):
1. Add authorization checks for excuse/unexcuse/show
2. Fix unexcuse route method (POST → DELETE)
3. Add expiring_soon and gbro_eligible filters

### Medium Priority (Missing features):
4. Add statistics() method and route
5. Add export() method and route
6. Fix index route to accept user parameter OR update tests

## Next Steps

1. Update routes in `routes/web.php`
2. Add authorization logic to controller methods
3. Add missing filter logic to index() method
4. Add statistics() and export() methods
5. Run tests again: `php artisan test --filter=AttendancePointControllerTest`
