# Notification System - Quick Start Guide

## Installation Steps

Follow these steps to set up and test the notification system:

### 1. Run Database Migration

```bash
php artisan migrate
```

This will create the `notifications` table in your database.

### 2. Build Frontend Assets

Since we've added new React components, rebuild the frontend:

```bash
npm run build
# or for development with hot reload
npm run dev
```

### 3. Test the Notification System

#### Option A: Using the Test Command

Send a test notification to a specific user:
```bash
php artisan notification:test
```

This will show you a list of users. Enter a user ID to send them a test notification.

Or send to a specific user directly:
```bash
php artisan notification:test 1
```

Or send to all approved users:
```bash
php artisan notification:test --all
```

#### Option B: Trigger from Application

The notification system is already integrated with Leave Requests. To test:

1. Log in as a regular user
2. Create a new leave request at `/form-requests/leave-requests/create`
3. Log in as an Admin/HR user
4. You should see a notification in the bell icon
5. Approve or deny the leave request
6. Log back in as the original user
7. You should see a notification about the status change

### 4. View Notifications

Users can view their notifications in two ways:

1. **Bell Icon Dropdown**: Click the bell icon in the header to see recent notifications
2. **Full Page**: Visit `/notifications` to see all notifications with pagination

### 5. Verify It's Working

Check the following:

- [ ] Bell icon appears in the header
- [ ] Unread count shows on the bell icon badge
- [ ] Clicking the bell opens a dropdown with recent notifications
- [ ] Can mark individual notifications as read
- [ ] Can mark all notifications as read
- [ ] Can delete notifications
- [ ] Can view all notifications at `/notifications`
- [ ] Unread notifications are visually distinct (blue highlight)
- [ ] Notification count updates automatically (polls every 30 seconds)

## Features Overview

### For End Users

1. **Notification Bell**: Always visible in the header
2. **Unread Count**: Shows number of unread notifications
3. **Quick Preview**: Dropdown shows last 10 notifications
4. **Full View**: Dedicated page for all notifications
5. **Mark as Read**: Click the checkmark icon
6. **Delete**: Click the X icon

### For Developers

1. **NotificationService**: Easy-to-use service for creating notifications
2. **Pre-built Methods**: Common notification types already implemented
3. **Flexible**: Can create custom notification types
4. **Bulk Operations**: Send to multiple users or all users
5. **Role-based**: Send to users with specific roles

## Common Use Cases

### Example 1: Notify Admin About New Request

```php
use App\Services\NotificationService;

public function __construct(NotificationService $notificationService)
{
    $this->notificationService = $notificationService;
}

public function store(Request $request)
{
    // ... create the request ...
    
    // Notify all admins
    $admins = User::where('role', 'Admin')->get();
    foreach ($admins as $admin) {
        $this->notificationService->create(
            $admin->id,
            'new_request',
            'New Request Submitted',
            "User {$user->name} submitted a new request.",
            ['request_id' => $request->id]
        );
    }
}
```

### Example 2: Notify User About Status Change

```php
public function approve(Request $request)
{
    // ... approve the request ...
    
    // Notify the user
    $this->notificationService->create(
        $request->user_id,
        'request_approved',
        'Request Approved',
        'Your request has been approved.',
        ['request_id' => $request->id]
    );
}
```

### Example 3: System-wide Announcement

```php
$this->notificationService->notifyAllUsers(
    'System Maintenance',
    'The system will be down for maintenance on Sunday at 2 AM.',
    ['scheduled_time' => '2024-12-01 02:00:00']
);
```

## Troubleshooting

### Bell Icon Not Showing

1. Clear your browser cache
2. Rebuild frontend assets: `npm run build`
3. Check browser console for errors

### Notifications Not Appearing

1. Check if the migration ran: `php artisan migrate:status`
2. Verify user is authenticated
3. Check the database `notifications` table for records
4. Look for errors in `storage/logs/laravel.log`

### Unread Count Not Updating

1. Check browser console's Network tab for API calls
2. Ensure `/notifications/unread-count` endpoint is accessible
3. Wait 30 seconds for the next poll cycle

### CSRF Token Errors

Make sure your layout includes the CSRF meta tag:
```html
<meta name="csrf-token" content="{{ csrf_token() }}">
```

## Next Steps

1. **Add More Integrations**: Integrate notifications into other controllers (IT Concerns, Medication Requests, etc.)
2. **Customize Notification Types**: Add custom notification types and styling
3. **User Preferences**: Allow users to configure which notifications they want to receive
4. **Email Integration**: Send email notifications for critical events
5. **Websockets**: Implement real-time notifications using Laravel Echo and Pusher

## Documentation

For complete documentation, see:
- [Full Documentation](./NOTIFICATION_SYSTEM.md)
- [NotificationService API](../app/Services/NotificationService.php)
- [Frontend Components](../resources/js/components/)

## Support

If you encounter issues:
1. Check the troubleshooting section above
2. Review the Laravel logs in `storage/logs/`
3. Check browser console for JavaScript errors
4. Verify all migrations have run
5. Ensure frontend assets are built

Happy notifying! 🔔
