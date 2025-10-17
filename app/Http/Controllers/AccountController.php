<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
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
        $query = User::query();

        // Search by name or email
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate(10)
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
            'roles' => ['Super Admin', 'Admin', 'Agent', 'HR'],
        ]);
    }

    /**
     * Store a newly created user account.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => 'required|in:Super Admin,Admin,Agent,HR',
        ]);

        $validated['password'] = Hash::make($validated['password']);

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
            'user' => $account,
            'roles' => ['Super Admin', 'Admin', 'Agent', 'HR'],
        ]);
    }

    /**
     * Update the specified user account.
     */
    public function update(Request $request, User $account)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $account->id,
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => 'required|in:Super Admin,Admin,Agent,HR',
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
}
