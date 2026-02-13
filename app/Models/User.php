<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, LogsActivity, Notifiable, TwoFactorAuthenticatable;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept([
                'password',
                'remember_token',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ])
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
        'avatar',
        'password',
        'role',
        'inactivity_timeout',
        'hired_date',
        'is_approved',
        'is_active',
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
    protected $appends = ['name', 'avatar_url'];

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
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
            'deleted_at' => 'datetime',
            'deletion_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Capitalize a name string properly (first letter uppercase, rest lowercase for each word).
     */
    protected function capitalizeName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Convert to lowercase first, then capitalize first letter of each word
        // This handles ALL CAPS, snake_case, and mixed cases
        return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Interact with the user's first name.
     */
    protected function firstName(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $this->capitalizeName($value),
        );
    }

    /**
     * Interact with the user's middle name.
     */
    protected function middleName(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value ? mb_strtoupper($value) : $value,
        );
    }

    /**
     * Interact with the user's last name.
     */
    protected function lastName(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $this->capitalizeName($value),
        );
    }

    /**
     * Get the user's full name.
     */
    public function getNameAttribute(): string
    {
        $name = $this->first_name;
        if (! empty($this->middle_name)) {
            $name .= ' '.$this->middle_name.'.';
        }
        $name .= ' '.$this->last_name;

        return $name;
    }

    /**
     * Get the URL for the user's avatar.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar) {
            return null;
        }

        return Storage::disk('public')->url($this->avatar);
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
     */
    public function hasPermission(string $permission): bool
    {
        return app(\App\Services\PermissionService::class)->userHasPermission($this, $permission);
    }

    /**
     * Check if user has any of the specified permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return app(\App\Services\PermissionService::class)->userHasAnyPermission($this, $permissions);
    }

    /**
     * Check if user has all of the specified permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        return app(\App\Services\PermissionService::class)->userHasAllPermissions($this, $permissions);
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole(string|array $roles): bool
    {
        return app(\App\Services\PermissionService::class)->userHasRole($this, $roles);
    }

    /**
     * Get all permissions for this user
     */
    public function getPermissions(): array
    {
        return app(\App\Services\PermissionService::class)->getPermissionsForRole($this->role);
    }

    /**
     * Check if the user is soft deleted (marked for deletion).
     */
    public function isSoftDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Check if the deletion is pending confirmation.
     */
    public function isDeletionPending(): bool
    {
        return $this->deleted_at !== null && $this->deletion_confirmed_at === null;
    }

    /**
     * Check if the deletion has been confirmed.
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
