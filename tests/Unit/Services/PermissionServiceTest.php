<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\PermissionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    private PermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PermissionService();
    }

    #[Test]
    public function it_gets_permissions_for_super_admin_role(): void
    {
        $permissions = $this->service->getPermissionsForRole('Super Admin');

        // Super Admin should have all permissions
        $this->assertNotEmpty($permissions);
        $this->assertIsArray($permissions);
    }

    #[Test]
    public function it_gets_permissions_for_admin_role(): void
    {
        $permissions = $this->service->getPermissionsForRole('Admin');

        $this->assertIsArray($permissions);
        $this->assertNotEmpty($permissions);
    }

    #[Test]
    public function it_gets_permissions_for_team_lead_role(): void
    {
        $permissions = $this->service->getPermissionsForRole('Team Lead');

        $this->assertIsArray($permissions);
        $this->assertContains('attendance.view', $permissions);
    }

    #[Test]
    public function it_gets_permissions_for_agent_role(): void
    {
        $permissions = $this->service->getPermissionsForRole('Agent');

        $this->assertIsArray($permissions);
        // Agents have limited permissions
    }

    #[Test]
    public function it_returns_empty_array_for_nonexistent_role(): void
    {
        $permissions = $this->service->getPermissionsForRole('NonExistentRole');

        $this->assertIsArray($permissions);
        $this->assertEmpty($permissions);
    }

    #[Test]
    public function it_normalizes_role_key_for_config_lookup(): void
    {
        // Super Admin -> super_admin
        $permissions1 = $this->service->getPermissionsForRole('Super Admin');
        $permissions2 = $this->service->getPermissionsForRole('super_admin');

        $this->assertEquals($permissions1, $permissions2);
    }

    #[Test]
    public function it_checks_user_has_permission(): void
    {
        $user = User::factory()->create([
            'role' => 'Super Admin',
            'is_approved' => true,
        ]);

        $hasPermission = $this->service->userHasPermission($user, 'accounts.view');

        $this->assertTrue($hasPermission);
    }

    #[Test]
    public function it_returns_false_when_user_lacks_permission(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $hasPermission = $this->service->userHasPermission($user, 'accounts.create');

        $this->assertFalse($hasPermission);
    }

    #[Test]
    public function it_returns_false_when_user_is_null(): void
    {
        $hasPermission = $this->service->userHasPermission(null, 'accounts.view');

        $this->assertFalse($hasPermission);
    }

    #[Test]
    public function it_checks_user_has_any_permission(): void
    {
        $user = User::factory()->create([
            'role' => 'Team Lead',
            'is_approved' => true,
        ]);

        $hasAny = $this->service->userHasAnyPermission($user, ['accounts.create', 'attendance.view']);

        $this->assertTrue($hasAny);
    }

    #[Test]
    public function it_returns_false_when_user_has_none_of_permissions(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $hasAny = $this->service->userHasAnyPermission($user, ['accounts.create', 'sites.create']);

        $this->assertFalse($hasAny);
    }

    #[Test]
    public function it_returns_false_for_any_permission_when_user_is_null(): void
    {
        $hasAny = $this->service->userHasAnyPermission(null, ['accounts.create', 'attendance.view']);

        $this->assertFalse($hasAny);
    }

    #[Test]
    public function it_checks_user_has_all_permissions(): void
    {
        $user = User::factory()->create([
            'role' => 'Super Admin',
            'is_approved' => true,
        ]);

        $hasAll = $this->service->userHasAllPermissions($user, ['accounts.view', 'attendance.view']);

        $this->assertTrue($hasAll);
    }

    #[Test]
    public function it_returns_false_when_user_lacks_one_permission(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $hasAll = $this->service->userHasAllPermissions($user, ['attendance.view', 'accounts.create']);

        $this->assertFalse($hasAll);
    }

    #[Test]
    public function it_returns_false_for_all_permissions_when_user_is_null(): void
    {
        $hasAll = $this->service->userHasAllPermissions(null, ['attendance.view']);

        $this->assertFalse($hasAll);
    }

    #[Test]
    public function it_checks_user_has_specific_role(): void
    {
        $user = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $hasRole = $this->service->userHasRole($user, 'Admin');

        $this->assertTrue($hasRole);
    }

    #[Test]
    public function it_checks_user_has_any_of_multiple_roles(): void
    {
        $user = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
        ]);

        $hasRole = $this->service->userHasRole($user, ['Admin', 'HR', 'IT']);

        $this->assertTrue($hasRole);
    }

    #[Test]
    public function it_returns_false_when_user_has_different_role(): void
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $hasRole = $this->service->userHasRole($user, 'Admin');

        $this->assertFalse($hasRole);
    }

    #[Test]
    public function it_returns_false_for_role_check_when_user_is_null(): void
    {
        $hasRole = $this->service->userHasRole(null, 'Admin');

        $this->assertFalse($hasRole);
    }

    #[Test]
    public function it_gets_all_roles_from_config(): void
    {
        $roles = $this->service->getAllRoles();

        $this->assertIsArray($roles);
        $this->assertNotEmpty($roles);
        $this->assertContains('Super Admin', $roles);
        $this->assertContains('Admin', $roles);
        $this->assertContains('Agent', $roles);
    }

    #[Test]
    public function it_gets_all_permissions_from_config(): void
    {
        $permissions = $this->service->getAllPermissions();

        $this->assertIsArray($permissions);
        $this->assertNotEmpty($permissions);
        $this->assertArrayHasKey('accounts.view', $permissions);
    }

    #[Test]
    public function it_handles_wildcard_permissions_for_super_admin(): void
    {
        $user = User::factory()->create([
            'role' => 'Super Admin',
            'is_approved' => true,
        ]);

        // Super Admin should have all permissions including custom ones
        $hasCustomPermission = $this->service->userHasPermission($user, 'any_custom_permission');

        $this->assertTrue($hasCustomPermission);
    }

    #[Test]
    public function it_is_case_sensitive_for_role_names(): void
    {
        $user = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $hasRole = $this->service->userHasRole($user, 'admin'); // lowercase

        $this->assertFalse($hasRole); // Should not match because roles are case-sensitive
    }
}
