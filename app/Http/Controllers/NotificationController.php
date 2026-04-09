<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendNotificationRequest;
use App\Http\Traits\RedirectsWithFlashMessages;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class NotificationController extends Controller
{
    use RedirectsWithFlashMessages;

    public function __construct(protected NotificationService $notificationService) {}

    /**
     * Display a listing of the user's notifications.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = $user->notifications()->delivered()->orderBy('created_at', 'desc');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status')) {
            if ($request->input('status') === 'read') {
                $query->whereNotNull('read_at');
            } elseif ($request->input('status') === 'unread') {
                $query->whereNull('read_at');
            }
        }

        $notifications = $query->paginate(20)->withQueryString();

        // Get available types for the filter dropdown
        $availableTypes = $user->notifications()
            ->reorder()
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => $user->unreadNotifications()->count(),
            'availableTypes' => $availableTypes,
            'filters' => [
                'type' => $request->input('type', ''),
                'status' => $request->input('status', ''),
            ],
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
            ->delivered()
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
        $this->authorize('view', $notification);

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
        $this->authorize('delete', $notification);

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
            ->map(fn ($user) => [
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
        $roles = collect(config('permissions.roles'))->map(fn ($label, $value) => [
            'value' => $label, // Use label as value since that's what's stored in the database
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
            $scheduledAt = $validated['scheduled_at'] ?? null;
            $isScheduled = ! empty($scheduledAt);

            $recipientCount = 0;
            $notificationData = ['sent_by' => auth()->id()];

            // Helper to get user IDs based on recipient type
            $userIds = match ($recipientType) {
                'all' => User::where('is_approved', true)->pluck('id')->toArray(),
                'role' => User::where('role', $validated['role'])->where('is_approved', true)->pluck('id')->toArray(),
                'specific_users' => $validated['user_ids'],
                'single_user' => [$validated['user_id']],
                default => [],
            };

            if ($isScheduled) {
                // Create scheduled notifications directly (bypass preferences — they'll be checked at delivery time)
                foreach ($userIds as $userId) {
                    Notification::create([
                        'user_id' => $userId,
                        'type' => $type,
                        'title' => $title,
                        'message' => $message,
                        'data' => $notificationData,
                        'scheduled_at' => $scheduledAt,
                        'is_scheduled' => true,
                    ]);
                }
                $recipientCount = count($userIds);
            } else {
                // Send immediately using existing service methods
                switch ($recipientType) {
                    case 'all':
                        $this->notificationService->notifyAllUsers($title, $message, $notificationData, $type);
                        $recipientCount = count($userIds);
                        break;

                    case 'role':
                        $this->notificationService->notifyUsersByRole($validated['role'], $type, $title, $message, $notificationData);
                        $recipientCount = count($userIds);
                        break;

                    case 'specific_users':
                        foreach ($userIds as $userId) {
                            $this->notificationService->create($userId, $type, $title, $message, $notificationData);
                        }
                        $recipientCount = count($userIds);
                        break;

                    case 'single_user':
                        $this->notificationService->create($validated['user_id'], $type, $title, $message, $notificationData);
                        $recipientCount = 1;
                        break;
                }
            }

            DB::commit();

            $actionText = $isScheduled ? 'scheduled' : 'sent';

            return $this->redirectWithFlash(
                'notifications.index',
                "Notification {$actionText} successfully to {$recipientCount} user(s).",
                'success'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('NotificationController store Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to send notification. Please try again.', 'error');
        }
    }
}
