<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AccountController extends Controller
{
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
     * Remove the specified user account.
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

        $account->delete();

        return back()->with('flash', [
            'message' => 'User account deleted successfully',
            'type' => 'success'
        ]);
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
