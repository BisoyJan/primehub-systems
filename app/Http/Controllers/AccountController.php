<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        // Filter by status
        $status = $request->query('status');
        if ($status === 'active') {
            $query->whereNull('deleted_at');
        } elseif ($status === 'pending_deletion') {
            $query->whereNotNull('deleted_at')->whereNull('deletion_confirmed_at');
        } elseif ($status === 'deleted') {
            $query->whereNotNull('deleted_at')->whereNotNull('deletion_confirmed_at');
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate(25)
            ->withQueryString();

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
            'filters' => [
                'search' => $search ?? '',
                'role' => $role ?? '',
                'status' => $status ?? '',
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
     * Store a newly created user account.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|size:1',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
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
            ],
            'roles' => ['Super Admin', 'Admin', 'Team Lead', 'Agent', 'HR', 'IT', 'Utility'],
        ]);
    }

    /**
     * Update the specified user account.
     */
    public function update(Request $request, User $account)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|size:1',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $account->id,
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
            $account->update([
                'deletion_confirmed_at' => now(),
                'deletion_confirmed_by' => auth()->id(),
            ]);

            return back()->with('flash', [
                'message' => 'User account deletion has been confirmed',
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
     * Restore a soft-deleted account (admin action - before confirmation).
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

        // Only allow restoring if deletion is not confirmed
        if ($account->isDeletionConfirmed()) {
            return back()->with('flash', [
                'message' => 'Cannot restore an account that has confirmed deletion. User must reactivate their account.',
                'type' => 'error'
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
    public function unapprove(User $account)
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

        $account->update([
            'is_approved' => false,
            'approved_at' => null,
        ]);

        return back()->with('flash', [
            'message' => 'User account approval revoked successfully',
            'type' => 'success'
        ]);
    }
}
