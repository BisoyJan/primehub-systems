<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendNotificationRequest;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use App\Http\Traits\RedirectsWithFlashMessages;

class NotificationController extends Controller
{
    use RedirectsWithFlashMessages;

    public function __construct(protected NotificationService $notificationService)
    {
    }
    /**
     * Display a listing of the user's notifications.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Get unread notifications count (API endpoint for polling).
     */
    public function unreadCount(Request $request)
    {
        $count = $request->user()->unreadNotifications()->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Get recent notifications for dropdown.
     */
    public function recent(Request $request)
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(Request $request, Notification $notification)
    {
        // Ensure the notification belongs to the authenticated user
        if ($notification->user_id !== $request->user()->id) {
            abort(403);
        }

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, Notification $notification)
    {
        // Ensure the notification belongs to the authenticated user
        if ($notification->user_id !== $request->user()->id) {
            abort(403);
        }

        $notification->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Delete all read notifications.
     */
    public function deleteAllRead(Request $request)
    {
        $request->user()->notifications()->whereNotNull('read_at')->delete();

        return back()->with('success', 'All read notifications have been deleted.');
    }

    /**
     * Delete all notifications.
     */
    public function deleteAll(Request $request)
    {
        $request->user()->notifications()->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Show the form for sending a notification.
     */
    public function create()
    {
        $this->authorize('send', Notification::class);

        // Get all approved users for single/multiple user selection
        $users = User::where('is_approved', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'role'])
            ->map(fn($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]);

        // Get user counts by role
        $userCountsByRole = User::where('is_approved', true)
            ->selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->pluck('count', 'role');

        // Get available roles with user counts
        $roles = collect(config('permissions.roles'))->map(fn($label, $value) => [
            'value' => $value,
            'label' => $label,
            'count' => $userCountsByRole->get($label, 0),
        ])->values();

        return Inertia::render('Notifications/Send', [
            'users' => $users,
            'roles' => $roles,
        ]);
    }

    /**
     * Store a newly created notification.
     */
    public function store(SendNotificationRequest $request)
    {
        $this->authorize('send', Notification::class);

        try {
            DB::beginTransaction();

            $validated = $request->validated();
            $recipientType = $validated['recipient_type'];
            $title = $validated['title'];
            $message = $validated['message'];
            $type = $validated['type'] ?? 'system';

            $recipientCount = 0;

            switch ($recipientType) {
                case 'all':
                    $this->notificationService->notifyAllUsers($title, $message, ['sent_by' => auth()->id()], $type);
                    $recipientCount = User::where('is_approved', true)->count();
                    break;

                case 'role':
                    $role = $validated['role'];
                    $this->notificationService->notifyUsersByRole($role, $type, $title, $message, ['sent_by' => auth()->id()]);
                    $recipientCount = User::where('role', $role)->where('is_approved', true)->count();
                    break;

                case 'specific_users':
                    $userIds = $validated['user_ids'];
                    foreach ($userIds as $userId) {
                        $this->notificationService->create($userId, $type, $title, $message, ['sent_by' => auth()->id()]);
                    }
                    $recipientCount = count($userIds);
                    break;

                case 'single_user':
                    $userId = $validated['user_id'];
                    $this->notificationService->create($userId, $type, $title, $message, ['sent_by' => auth()->id()]);
                    $recipientCount = 1;
                    break;
            }

            DB::commit();

            return $this->redirectWithFlash(
                'notifications.index',
                "Notification sent successfully to {$recipientCount} user(s).",
                'success'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('NotificationController store Error: ' . $e->getMessage());
            return $this->backWithFlash('Failed to send notification. Please try again.', 'error');
        }
    }
}
