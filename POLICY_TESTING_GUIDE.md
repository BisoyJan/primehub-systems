# Policy Testing Guide

## Overview
This guide explains how to test Laravel Policies in your PrimeHub Systems application. Policies provide authorization logic for your application's resources.

## Test Types

### 1. **Unit Tests** (Fastest, Most Isolated)
Located in `tests/Unit/Policies/`

**Purpose:** Test individual policy methods in isolation without HTTP requests or database operations.

**Example:**
```php
/** @test */
public function user_can_cancel_their_own_pending_leave_request()
{
    $user = User::factory()->create(['role' => 'Agent']);
    $leaveRequest = LeaveRequest::factory()->create([
        'user_id' => $user->id,
        'status' => 'pending',
    ]);

    $this->assertTrue($this->policy->cancel($user, $leaveRequest));
}
```

### 2. **Feature Tests** (Full Integration)
Located in `tests/Feature/Authorization/`

**Purpose:** Test authorization through actual HTTP requests, ensuring policies work correctly with routes and controllers.

**Example:**
```php
/** @test */
public function agent_cannot_access_other_users_leave_request()
{
    $agent = User::factory()->create(['role' => 'Agent']);
    $otherUser = User::factory()->create(['role' => 'Agent']);
    $leaveRequest = LeaveRequest::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($agent)->get(route('leave-requests.show', $leaveRequest));

    $response->assertForbidden(); // Expects 403 status
}
```

## Running Tests

### Run All Tests
```bash
php artisan test
```

### Run Specific Test File
```bash
php artisan test tests/Unit/Policies/LeaveRequestPolicyTest.php
```

### Run Specific Test Method
```bash
php artisan test --filter test_user_can_cancel_their_own_pending_leave_request
```

### Run All Policy Tests
```bash
php artisan test tests/Unit/Policies/
```

### Run with Coverage (if enabled)
```bash
php artisan test --coverage
```

## Test Structure

### Unit Test Pattern
```php
<?php

namespace Tests\Unit\Policies;

use App\Models\YourModel;
use App\Models\User;
use App\Policies\YourModelPolicy;
use App\Services\PermissionService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class YourModelPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected YourModelPolicy $policy;
    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->policy = new YourModelPolicy($this->permissionService);
    }

    /** @test */
    public function test_description()
    {
        // Arrange: Create test data
        $user = User::factory()->create(['role' => 'Agent']);
        
        // Act: Call policy method
        $result = $this->policy->someMethod($user);
        
        // Assert: Check result
        $this->assertTrue($result);
    }
}
```

## Testing Checklist

For each Policy, test these scenarios:

### ✅ **Basic Permission Checks**
- [ ] Super Admin can access everything
- [ ] Admin can access admin features
- [ ] Each role has correct base permissions

### ✅ **Ownership Validation**
- [ ] Users can access their own resources
- [ ] Users cannot access other users' resources
- [ ] Admins can access all resources

### ✅ **Status-Based Rules**
- [ ] Can only modify resources in specific states (e.g., pending)
- [ ] Cannot modify completed/cancelled items

### ✅ **Role-Specific Scoping**
- [ ] Agent/IT/Utility see only own data
- [ ] Admin/HR see all data

### ✅ **Action-Specific Rules**
- [ ] Approve/Deny restricted to authorized roles
- [ ] Assign only by specific roles
- [ ] Delete with appropriate restrictions

## Example Test Scenarios

### LeaveRequest Policy Tests
```php
// ✅ Ownership
- user_can_view_their_own_leave_request
- agent_cannot_view_other_users_leave_request
- admin_can_view_all_leave_requests

// ✅ Actions
- user_can_cancel_their_own_pending_leave_request
- user_cannot_cancel_approved_leave_request
- admin_can_approve_leave_requests
- agent_cannot_approve_leave_requests

// ✅ Status
- user_cannot_cancel_denied_leave_request
- user_cannot_cancel_other_users_leave_request
```

### Attendance Policy Tests
```php
// ✅ Role Scoping
- agent_can_view_their_own_attendance
- agent_cannot_view_other_users_attendance
- admin_can_view_any_attendance

// ✅ Actions
- agent_cannot_approve_attendance
- team_lead_can_approve_attendance
- admin_can_import_attendance
```

### ItConcern Policy Tests
```php
// ✅ Ownership
- agent_can_view_their_own_concern
- agent_cannot_view_other_users_concern

// ✅ Assignment
- assigned_user_can_view_concern_assigned_to_them
- assigned_user_can_resolve_their_concern

// ✅ Permissions
- it_user_can_assign_concerns
- agent_cannot_assign_concerns
```

## Assertions Reference

### Policy Assertions
```php
$this->assertTrue($result);          // Policy allows action
$this->assertFalse($result);         // Policy denies action
```

### HTTP Response Assertions
```php
$response->assertOk();               // 200 - Success
$response->assertForbidden();        // 403 - Unauthorized
$response->assertRedirect();         // Redirected
$response->assertStatus(422);        // Validation error
```

### Database Assertions
```php
$this->assertDatabaseHas('leave_requests', [
    'id' => $leaveRequest->id,
    'status' => 'cancelled'
]);
```

## Debugging Tips

### 1. Check Policy Registration
```php
// In tinker or test
Gate::getPolicyFor(LeaveRequest::class);
// Should return: App\Policies\LeaveRequestPolicy
```

### 2. Test Policy Directly
```php
php artisan tinker

$user = User::find(1);
$leaveRequest = LeaveRequest::find(1);
Gate::allows('cancel', $leaveRequest); // true or false
```

### 3. Enable Query Logging
```php
protected function setUp(): void
{
    parent::setUp();
    \DB::enableQueryLog();
}

protected function tearDown(): void
{
    dump(\DB::getQueryLog());
    parent::tearDown();
}
```

### 4. Add Debug Output
```php
/** @test */
public function test_something()
{
    $user = User::factory()->create(['role' => 'Agent']);
    dump($user->role); // Debug output
    
    $result = $this->policy->view($user, $model);
    dump($result); // See actual result
    
    $this->assertTrue($result);
}
```

## Quick Start Commands

### 1. Run All Tests
```bash
php artisan test
```

### 2. Run Only Policy Tests
```bash
php artisan test tests/Unit/Policies/
```

### 3. Run with Detailed Output
```bash
php artisan test --verbose
```

### 4. Run Specific Feature Tests
```bash
php artisan test tests/Feature/Authorization/
```

### 5. Watch Tests (Requires package)
```bash
php artisan test --watch
```

## Common Issues & Solutions

### Issue: "Factory not found"
**Solution:** Create missing factory:
```bash
php artisan make:factory LeaveRequestFactory
```

### Issue: "Policy not registered"
**Solution:** Check `app/Providers/AuthServiceProvider.php`:
```php
protected $policies = [
    LeaveRequest::class => LeaveRequestPolicy::class,
];
```

### Issue: "Permission not found"
**Solution:** Check `config/permissions.php` has the permission defined.

### Issue: "Database not refreshing"
**Solution:** Ensure test uses `RefreshDatabase` trait:
```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class YourTest extends TestCase
{
    use RefreshDatabase;
}
```

## Best Practices

1. ✅ **Use descriptive test names** - `test_agent_cannot_cancel_other_users_leave_request`
2. ✅ **Test both success and failure cases** - Test what should work AND what shouldn't
3. ✅ **Test edge cases** - Null values, empty arrays, boundary conditions
4. ✅ **Keep tests isolated** - Each test should be independent
5. ✅ **Use factories** - Don't manually create data
6. ✅ **Test one thing per test** - Each test should verify one behavior
7. ✅ **Use RefreshDatabase** - Ensures clean state between tests

## Created Test Files

### Unit Tests (Direct Policy Testing)
- ✅ `tests/Unit/Policies/LeaveRequestPolicyTest.php` - 13 test cases
- ✅ `tests/Unit/Policies/AttendancePolicyTest.php` - 13 test cases
- ✅ `tests/Unit/Policies/ItConcernPolicyTest.php` - 11 test cases

### Feature Tests (HTTP Integration Testing)
- ✅ `tests/Feature/Authorization/LeaveRequestAuthorizationTest.php` - 8 test cases

### Factories Created
- ✅ `database/factories/LeaveRequestFactory.php`
- ✅ `database/factories/ItConcernFactory.php`

## Next Steps

1. Run the existing tests to ensure they pass
2. Add more policy tests for other models (PcSpec, Station, etc.)
3. Add feature tests for critical authorization flows
4. Set up CI/CD to run tests automatically
5. Aim for >80% code coverage on policies

## Resources

- [Laravel Testing Documentation](https://laravel.com/docs/testing)
- [Laravel Authorization Documentation](https://laravel.com/docs/authorization)
- [PHPUnit Assertions](https://phpunit.readthedocs.io/en/9.5/assertions.html)
