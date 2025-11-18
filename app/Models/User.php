<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'password',
        'role',
        'time_format',
        'hired_date',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['name'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'hired_date' => 'date',
        ];
    }

    /**
     * Get the user's full name.
     *
     * @return string
     */
    public function getNameAttribute(): string
    {
        $name = $this->first_name;
        if (!empty($this->middle_name)) {
            $name .= ' ' . $this->middle_name . '.';
        }
        $name .= ' ' . $this->last_name;
        return $name;
    }

    /**
     * Get the employee schedules for the user.
     */
    public function employeeSchedules()
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    /**
     * Get the active employee schedule for the user.
     */
    public function activeSchedule()
    {
        return $this->hasOne(EmployeeSchedule::class)
            ->where('is_active', true)
            ->where('effective_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
    }

    /**
     * Get the attendances for the user.
     */
    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get the attendance points for the user.
     */
    public function attendancePoints()
    {
        return $this->hasMany(AttendancePoint::class);
    }

    /**
     * Get the leave credits for the user.
     */
    public function leaveCredits()
    {
        return $this->hasMany(LeaveCredit::class);
    }

    /**
     * Get the leave requests for the user.
     */
    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Get leave requests reviewed by this user.
     */
    public function reviewedLeaveRequests()
    {
        return $this->hasMany(LeaveRequest::class, 'reviewed_by');
    }

    /**
     * Check if user has a specific permission
     *
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        return app(\App\Services\PermissionService::class)->userHasPermission($this, $permission);
    }

    /**
     * Check if user has any of the specified permissions
     *
     * @param array $permissions
     * @return bool
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return app(\App\Services\PermissionService::class)->userHasAnyPermission($this, $permissions);
    }

    /**
     * Check if user has all of the specified permissions
     *
     * @param array $permissions
     * @return bool
     */
    public function hasAllPermissions(array $permissions): bool
    {
        return app(\App\Services\PermissionService::class)->userHasAllPermissions($this, $permissions);
    }

    /**
     * Check if user has a specific role
     *
     * @param string|array $roles
     * @return bool
     */
    public function hasRole(string|array $roles): bool
    {
        return app(\App\Services\PermissionService::class)->userHasRole($this, $roles);
    }

    /**
     * Get all permissions for this user
     *
     * @return array
     */
    public function getPermissions(): array
    {
        return app(\App\Services\PermissionService::class)->getPermissionsForRole($this->role);
    }
}
