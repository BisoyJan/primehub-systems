<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

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
        'is_approved',
        'approved_at',
        'deleted_at',
        'deleted_by',
        'deletion_confirmed_at',
        'deletion_confirmed_by',
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
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
            'deleted_at' => 'datetime',
            'deletion_confirmed_at' => 'datetime',
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
     * Get the notifications for the user.
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the unread notifications for the user.
     */
    public function unreadNotifications()
    {
        return $this->hasMany(Notification::class)->whereNull('read_at')->orderBy('created_at', 'desc');
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

    /**
     * Check if the user is soft deleted (marked for deletion).
     *
     * @return bool
     */
    public function isSoftDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Check if the deletion is pending confirmation.
     *
     * @return bool
     */
    public function isDeletionPending(): bool
    {
        return $this->deleted_at !== null && $this->deletion_confirmed_at === null;
    }

    /**
     * Check if the deletion has been confirmed.
     *
     * @return bool
     */
    public function isDeletionConfirmed(): bool
    {
        return $this->deleted_at !== null && $this->deletion_confirmed_at !== null;
    }

    /**
     * Get the user who deleted this account.
     */
    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Get the user who confirmed the deletion.
     */
    public function deletionConfirmedBy()
    {
        return $this->belongsTo(User::class, 'deletion_confirmed_by');
    }

    /**
     * Scope to include soft deleted users.
     */
    public function scopeWithSoftDeleted($query)
    {
        return $query;
    }

    /**
     * Scope to only get active (non-deleted) users.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Scope to only get soft deleted users.
     */
    public function scopeOnlyDeleted($query)
    {
        return $query->whereNotNull('deleted_at');
    }

    /**
     * Scope to only get users pending deletion confirmation.
     */
    public function scopePendingDeletion($query)
    {
        return $query->whereNotNull('deleted_at')->whereNull('deletion_confirmed_at');
    }
}
