<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Config;

class PermissionService
{
    /**
     * Get all permissions for a role
     *
     * @param string $role
     * @return array
     */
    public function getPermissionsForRole(string $role): array
    {
        $roleKey = $this->normalizeRoleKey($role);
        $permissions = Config::get("permissions.role_permissions.{$roleKey}", []);

        // If role has wildcard (*), return all permissions
        if (in_array('*', $permissions)) {
            return array_keys(Config::get('permissions.permissions', []));
        }

        return $permissions;
    }

    /**
     * Check if a user has a specific permission
     *
     * @param User|null $user
     * @param string $permission
     * @return bool
     */
    public function userHasPermission(?User $user, string $permission): bool
    {
        if (!$user) {
            return false;
        }

        $permissions = $this->getPermissionsForRole($user->role);

        return in_array($permission, $permissions);
    }

    /**
     * Check if a user has any of the specified permissions
     *
     * @param User|null $user
     * @param array $permissions
     * @return bool
     */
    public function userHasAnyPermission(?User $user, array $permissions): bool
    {
        if (!$user) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($this->userHasPermission($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a user has all of the specified permissions
     *
     * @param User|null $user
     * @param array $permissions
     * @return bool
     */
    public function userHasAllPermissions(?User $user, array $permissions): bool
    {
        if (!$user) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$this->userHasPermission($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a user has a specific role
     *
     * @param User|null $user
     * @param string|array $roles
     * @return bool
     */
    public function userHasRole(?User $user, string|array $roles): bool
    {
        if (!$user) {
            return false;
        }

        if (is_string($roles)) {
            $roles = [$roles];
        }

        return in_array($user->role, $roles);
    }

    /**
     * Normalize role name to config key format
     * Converts "Super Admin" to "super_admin"
     *
     * @param string $role
     * @return string
     */
    protected function normalizeRoleKey(string $role): string
    {
        return str_replace(' ', '_', strtolower($role));
    }

    /**
     * Get all available roles
     *
     * @return array
     */
    public function getAllRoles(): array
    {
        return Config::get('permissions.roles', []);
    }

    /**
     * Get all available permissions
     *
     * @return array
     */
    public function getAllPermissions(): array
    {
        return Config::get('permissions.permissions', []);
    }
}
