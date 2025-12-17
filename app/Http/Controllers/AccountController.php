<?php

namespace App\Http\Controllers;

use App\Mail\EmployeeAccessRevoked;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AccountController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Display a listing of user accounts.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $query = User::query();

        // Search by name or email
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('middle_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhereRaw("CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) LIKE ?", ["%{$search}%"]);
            });
        }

        // Filter by user_id (for employee dropdown)
        if ($userId = $request->query('user_id')) {
            $query->where('id', $userId);
        }

        // Filter by role
        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        // Filter by status
        $status = $request->query('status');
        if ($status === 'pending') {
            $query->whereNull('deleted_at')->where('is_approved', false);
        } elseif ($status === 'approved') {
            $query->whereNull('deleted_at')->where('is_approved', true);
        } elseif ($status === 'pending_deletion') {
            $query->whereNotNull('deleted_at')->whereNull('deletion_confirmed_at');
        } elseif ($status === 'deleted') {
            $query->whereNotNull('deleted_at')->whereNotNull('deletion_confirmed_at');
        }

        // Filter by employee status (is_active)
        $employeeStatus = $request->query('employee_status');
        if ($employeeStatus === 'active') {
            $query->where('is_active', true);
        } elseif ($employeeStatus === 'inactive') {
            $query->where('is_active', false);
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate(25)
            ->withQueryString();

        // Get all users for employee dropdown (only basic info)
        $allUsers = User::select('id', 'first_name', 'middle_name', 'last_name', 'email')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);

        return Inertia::render('Account/Index', [
            'users' => [
                'data' => $users->items(),
                'links' => $users->toArray()['links'] ?? [],
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
            ],
            'allUsers' => $allUsers,
            'filters' => [
                'search' => $search ?? '',
                'role' => $role ?? '',
                'status' => $status ?? '',
                'employee_status' => $employeeStatus ?? '',
                'user_id' => $userId ?? '',
            ],
        ]);
    }

    /**
     * Show the form for creating a new user account.
     */
    public function create()
    {
        return Inertia::render('Account/Create', [
            'roles' => ['Super Admin', 'Admin', 'Team Lead', 'Agent', 'HR', 'IT', 'Utility'],
        ]);
    }

    /**
     * Allowed email domains.
     */
    protected array $allowedEmailDomains = ['primehubmail.com', 'prmhubsolutions.com'];

    /**
     * Store a newly created user account.
     */
    public function store(Request $request)
    {
        $allowedDomains = $this->allowedEmailDomains;

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|size:1',
            'last_name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users',
                function ($attribute, $value, $fail) use ($allowedDomains) {
                    $domain = substr(strrchr($value, '@'), 1);
                    if (!in_array($domain, $allowedDomains)) {
                        $fail('Only @primehubmail.com and @prmhubsolutions.com email addresses are allowed.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => 'required|in:Super Admin,Admin,Team Lead,Agent,HR,IT,Utility',
            'hired_date' => 'required|date',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        // Accounts created by admins are automatically approved
        $validated['is_approved'] = true;
        $validated['approved_at'] = now();

        User::create($validated);

        return redirect()->route('accounts.index')
            ->with('flash', [
                'message' => 'User account created successfully',
                'type' => 'success'
            ]);
    }

    /**
     * Show the form for editing a user account.
     */
    public function edit(User $account)
    {
        return Inertia::render('Account/Edit', [
            'user' => [
                'id' => $account->id,
                'first_name' => $account->first_name,
                'middle_name' => $account->middle_name,
                'last_name' => $account->last_name,
                'email' => $account->email,
                'role' => $account->role,
                'hired_date' => $account->hired_date ? Carbon::parse($account->hired_date)->format('Y-m-d') : '',
                'is_active' => $account->is_active,
            ],
            'roles' => ['Super Admin', 'Admin', 'Team Lead', 'Agent', 'HR', 'IT', 'Utility'],
        ]);
    }

    /**
     * Update the specified user account.
     */
    public function update(Request $request, User $account)
    {
        $allowedDomains = $this->allowedEmailDomains;

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|size:1',
            'last_name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email,' . $account->id,
                function ($attribute, $value, $fail) use ($allowedDomains) {
                    $domain = substr(strrchr($value, '@'), 1);
                    if (!in_array($domain, $allowedDomains)) {
                        $fail('Only @primehubmail.com and @prmhubsolutions.com email addresses are allowed.');
                    }
                },
            ],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => 'required|in:Super Admin,Admin,Team Lead,Agent,HR,IT,Utility',
            'hired_date' => 'required|date',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $account->update($validated);

        return redirect()->route('accounts.index')
            ->with('flash', [
                'message' => 'User account updated successfully',
                'type' => 'success'
            ]);
    }

    /**
     * Remove the specified user account (soft delete).
     */
    public function destroy(User $account)
    {
        // Prevent deleting own account
        if ($account->id === auth()->id()) {
            return back()->with('flash', [
                'message' => 'You cannot delete your own account',
                'type' => 'error'
            ]);
        }

        // Check if already deleted
        if ($account->isSoftDeleted()) {
            return back()->with('flash', [
                'message' => 'This account has already been marked for deletion',
                'type' => 'info'
            ]);
        }

        try {
            $account->update([
                'deleted_at' => now(),
                'deleted_by' => auth()->id(),
            ]);

            // Notify admins about the deletion request
            $this->notificationService->notifyAccountDeletionRequest(
                $account->name,
                auth()->user()->name,
                $account->id
            );

            return back()->with('flash', [
                'message' => 'User account marked for deletion. Awaiting confirmation from administrators.',
                'type' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('AccountController Destroy Error: ' . $e->getMessage());
            return back()->with('flash', [
                'message' => 'Failed to delete user account',
                'type' => 'error'
            ]);
        }
    }

    /**
     * Confirm deletion of a user account (permanently prevents login).
     */
    public function confirmDelete(User $account)
    {
        $this->authorize('delete', $account);

        // Prevent deleting own account
        if ($account->id === auth()->id()) {
            return back()->with('flash', [
                'message' => 'You cannot confirm deletion of your own account',
                'type' => 'error'
            ]);
        }

        // Check if already confirmed
        if ($account->isDeletionConfirmed()) {
            return back()->with('flash', [
                'message' => 'This account deletion has already been confirmed',
                'type' => 'info'
            ]);
        }

        // Check if not pending deletion
        if (!$account->isDeletionPending()) {
            return back()->with('flash', [
                'message' => 'This account is not pending deletion',
                'type' => 'error'
            ]);
        }

        try {
            DB::transaction(function () use ($account) {
                // Confirm the account deletion and set employee status to inactive
                $account->update([
                    'deletion_confirmed_at' => now(),
                    'deletion_confirmed_by' => auth()->id(),
                    'is_active' => false,
                ]);

                // Deactivate all employee schedules for this user
                $account->employeeSchedules()->update(['is_active' => false]);
            });

            return back()->with('flash', [
                'message' => 'User account deletion has been confirmed, employee and schedules deactivated',
                'type' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('AccountController ConfirmDelete Error: ' . $e->getMessage());
            return back()->with('flash', [
                'message' => 'Failed to confirm account deletion',
                'type' => 'error'
            ]);
        }
    }

    /**
     * Restore a soft-deleted account (admin action).
     * Can restore both pending deletion and confirmed deletion accounts.
     */
    public function restore(User $account)
    {
        $this->authorize('update', $account);

        // Check if not deleted
        if (!$account->isSoftDeleted()) {
            return back()->with('flash', [
                'message' => 'This account is not deleted',
                'type' => 'info'
            ]);
        }

        try {
            $account->update([
                'deleted_at' => null,
                'deleted_by' => null,
                'deletion_confirmed_at' => null,
                'deletion_confirmed_by' => null,
            ]);

            // Notify the user their account has been restored
            $this->notificationService->notifyAccountRestored($account);

            return back()->with('flash', [
                'message' => 'User account has been restored successfully',
                'type' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('AccountController Restore Error: ' . $e->getMessage());
            return back()->with('flash', [
                'message' => 'Failed to restore user account',
                'type' => 'error'
            ]);
        }
    }

    /**
     * Reactivate a deleted account (user action during login).
     */
    public function reactivate(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::where('email', $validated['email'])
            ->whereNotNull('deleted_at')
            ->whereNull('deletion_confirmed_at')
            ->first();

        if (!$user) {
            return back()->with('flash', [
                'message' => 'Account not found or cannot be reactivated',
                'type' => 'error'
            ]);
        }

        try {
            $user->update([
                'deleted_at' => null,
                'deleted_by' => null,
                'deletion_confirmed_at' => null,
                'deletion_confirmed_by' => null,
                'password' => Hash::make($validated['password']),
            ]);

            // Notify admins about account reactivation
            $this->notificationService->notifyAccountReactivated($user->name, $user->id);

            return redirect()->route('login')->with('status', 'Your account has been reactivated. You can now log in with your new password.');
        } catch (\Exception $e) {
            Log::error('AccountController Reactivate Error: ' . $e->getMessage());
            return back()->with('flash', [
                'message' => 'Failed to reactivate account',
                'type' => 'error'
            ]);
        }
    }

    /**
     * Show the reactivation page for deleted accounts.
     */
    public function showReactivate(Request $request)
    {
        $email = $request->query('email', '');

        return Inertia::render('auth/account-deleted', [
            'email' => $email,
        ]);
    }

    /**
     * Permanently delete a user account (hard delete).
     * Only for accounts that have confirmed deletion.
     */
    public function forceDelete(User $account)
    {
        $this->authorize('delete', $account);

        // Prevent deleting own account
        if ($account->id === auth()->id()) {
            return back()->with('flash', [
                'message' => 'You cannot permanently delete your own account',
                'type' => 'error'
            ]);
        }

        // Only allow force delete if deletion is confirmed
        if (!$account->isDeletionConfirmed()) {
            return back()->with('flash', [
                'message' => 'Cannot permanently delete an account that has not been confirmed for deletion',
                'type' => 'error'
            ]);
        }

        try {
            $accountName = $account->name;
            $account->forceDelete();

            return back()->with('flash', [
                'message' => "User account '{$accountName}' has been permanently deleted",
                'type' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('AccountController ForceDelete Error: ' . $e->getMessage());
            return back()->with('flash', [
                'message' => 'Failed to permanently delete user account',
                'type' => 'error'
            ]);
        }
    }

    /**
     * Approve a user account.
     */
    public function approve(User $account)
    {
        $this->authorize('update', $account);

        if ($account->is_approved) {
            return back()->with('flash', [
                'message' => 'User account is already approved',
                'type' => 'info'
            ]);
        }

        $account->update([
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        return back()->with('flash', [
            'message' => 'User account approved successfully',
            'type' => 'success'
        ]);
    }

    /**
     * Unapprove a user account.
     */
    public function unapprove(Request $request, User $account)
    {
        $this->authorize('update', $account);

        // Prevent unapproving own account
        if ($account->id === auth()->id()) {
            return back()->with('flash', [
                'message' => 'You cannot unapprove your own account',
                'type' => 'error'
            ]);
        }

        if (!$account->is_approved) {
            return back()->with('flash', [
                'message' => 'User account is already unapproved',
                'type' => 'info'
            ]);
        }

        $sendEmail = $request->boolean('send_email', false);

        DB::transaction(function () use ($account) {
            // Revoke approval
            $account->update([
                'is_approved' => false,
                'approved_at' => null,
                'is_active' => false,
            ]);

            // Deactivate all employee schedules for this user
            $account->employeeSchedules()->update(['is_active' => false]);
        });

        // Send notification email if requested and employee has a hired date
        if ($sendEmail && $account->hired_date) {
            $this->sendAccessRevokedEmail($account);
        }

        $message = 'User account approval revoked, employee and schedules deactivated';
        if ($sendEmail && $account->hired_date) {
            $message .= '. Notification email has been sent.';
        }

        return back()->with('flash', [
            'message' => $message,
            'type' => 'success'
        ]);
    }

    /**
     * Toggle the active status of a user account.
     * When deactivating, also deactivates all employee schedules.
     */
    public function toggleActive(User $account)
    {
        $this->authorize('update', $account);

        // Prevent toggling own account
        if ($account->id === auth()->id()) {
            return back()->with('flash', [
                'message' => 'You cannot change the status of your own account',
                'type' => 'error'
            ]);
        }

        $newStatus = !$account->is_active;

        // If deactivating, also deactivate all employee schedules
        if (!$newStatus) {
            $account->employeeSchedules()->update(['is_active' => false]);
        }

        $account->update(['is_active' => $newStatus]);

        $statusText = $newStatus ? 'activated' : 'deactivated';
        $message = "Employee {$statusText} successfully";

        if (!$newStatus) {
            $message .= ". All schedules have been deactivated.";
        }

        return back()->with('flash', [
            'message' => $message,
            'type' => 'success'
        ]);
    }

    /**
     * Bulk approve multiple user accounts.
     */
    public function bulkApprove(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:users,id',
        ]);

        $ids = $validated['ids'];

        // Filter out current user's ID and already approved accounts
        $usersToApprove = User::whereIn('id', $ids)
            ->where('id', '!=', auth()->id())
            ->where('is_approved', false)
            ->whereNull('deleted_at')
            ->get();

        if ($usersToApprove->isEmpty()) {
            return back()->with('flash', [
                'message' => 'No accounts to approve. Selected accounts may already be approved or deleted.',
                'type' => 'info'
            ]);
        }

        try {
            foreach ($usersToApprove as $user) {
                $this->authorize('update', $user);
            }

            $count = $usersToApprove->count();

            User::whereIn('id', $usersToApprove->pluck('id'))
                ->update([
                    'is_approved' => true,
                    'approved_at' => now(),
                ]);

            return back()->with('flash', [
                'message' => "{$count} user account(s) approved successfully",
                'type' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('AccountController BulkApprove Error: ' . $e->getMessage());
            return back()->with('flash', [
                'message' => 'Failed to approve selected accounts',
                'type' => 'error'
            ]);
        }
    }

    /**
     * Bulk unapprove (revoke) multiple user accounts.
     */
    public function bulkUnapprove(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:users,id',
            'send_email' => 'boolean',
        ]);

        $ids = $validated['ids'];
        $sendEmail = $request->boolean('send_email', false);

        // Filter out current user's ID and already unapproved accounts
        $usersToUnapprove = User::whereIn('id', $ids)
            ->where('id', '!=', auth()->id())
            ->where('is_approved', true)
            ->whereNull('deleted_at')
            ->get();

        if ($usersToUnapprove->isEmpty()) {
            return back()->with('flash', [
                'message' => 'No accounts to revoke. Selected accounts may already be pending or deleted.',
                'type' => 'info'
            ]);
        }

        try {
            foreach ($usersToUnapprove as $user) {
                $this->authorize('update', $user);
            }

            $count = $usersToUnapprove->count();
            $emailsSent = 0;

            DB::transaction(function () use ($usersToUnapprove) {
                $userIds = $usersToUnapprove->pluck('id');

                // Revoke approval and deactivate employees
                User::whereIn('id', $userIds)
                    ->update([
                        'is_approved' => false,
                        'approved_at' => null,
                        'is_active' => false,
                    ]);

                // Deactivate all employee schedules for these users
                \App\Models\EmployeeSchedule::whereIn('user_id', $userIds)
                    ->update(['is_active' => false]);
            });

            // Send notification emails if requested
            if ($sendEmail) {
                foreach ($usersToUnapprove as $user) {
                    if ($user->hired_date) {
                        $this->sendAccessRevokedEmail($user);
                        $emailsSent++;
                    }
                }
            }

            $message = "{$count} user account(s) revoked, employees and schedules deactivated";
            if ($sendEmail && $emailsSent > 0) {
                $message .= ". {$emailsSent} notification email(s) sent.";
            }

            return back()->with('flash', [
                'message' => $message,
                'type' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('AccountController BulkUnapprove Error: ' . $e->getMessage());
            return back()->with('flash', [
                'message' => 'Failed to revoke approval for selected accounts',
                'type' => 'error'
            ]);
        }
    }

    /**
     * Send access revoked notification email to the employee.
     */
    protected function sendAccessRevokedEmail(User $employee): void
    {
        try {
            $revokedBy = auth()->user()->name ?? 'System';
            $department = $employee->role;

            Mail::to($employee->email)
                ->queue(new EmployeeAccessRevoked($employee, $department, $revokedBy));

            Log::info('Access revoked email queued for employee: ' . $employee->name);
        } catch (\Exception $e) {
            Log::error('Failed to send access revoked email: ' . $e->getMessage());
        }
    }
}
