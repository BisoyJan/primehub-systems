# Notification System

A comprehensive notification system for the PrimeHub application that allows users to receive real-time updates about important events in the system.

## Features

- **Real-time Notifications**: Users receive instant notifications for important events
- **Notification Bell**: Visual indicator in the header showing unread notification count
- **Dropdown Preview**: Quick preview of recent notifications without leaving the current page
- **Full Notifications Page**: Dedicated page to view all notifications with filtering and pagination
- **Mark as Read/Unread**: Users can mark individual or all notifications as read
- **Delete Notifications**: Users can delete individual notifications they no longer need
- **Notification Types**: Support for multiple notification types (maintenance, leave requests, IT concerns, etc.)
- **Background Polling**: Automatic polling for new notifications every 30 seconds

## Database Schema

### Notifications Table
- `id` - Primary key
- `user_id` - Foreign key to users table
- `type` - Type of notification (e.g., 'maintenance_due', 'leave_request', etc.)
- `title` - Short title of the notification
- `message` - Detailed message
- `data` - JSON field for additional data (optional)
- `read_at` - Timestamp when notification was read (nullable)
- `created_at` - Timestamp when notification was created
- `updated_at` - Timestamp when notification was last updated

## Backend Components

### Model
**Location**: `app/Models/Notification.php`

Key methods:
- `markAsRead()` - Mark notification as read
- `markAsUnread()` - Mark notification as unread
- `read()` - Check if notification is read
- `unread()` - Check if notification is unread
- `scopeUnread()` - Query scope for unread notifications
- `scopeRead()` - Query scope for read notifications

### Controller
**Location**: `app/Http/Controllers/NotificationController.php`

Available endpoints:
- `GET /notifications` - List all notifications (paginated)
- `GET /notifications/unread-count` - Get count of unread notifications
- `GET /notifications/recent` - Get recent notifications for dropdown
- `POST /notifications/{notification}/read` - Mark notification as read
- `POST /notifications/mark-all-read` - Mark all notifications as read
- `DELETE /notifications/{notification}` - Delete a notification
- `DELETE /notifications/read/all` - Delete all read notifications

### Service
**Location**: `app/Services/NotificationService.php`

Helper service for creating notifications easily throughout the application.

Key methods:
- `create($user, $type, $title, $message, $data)` - Create a notification
- `createForMultipleUsers($userIds, ...)` - Create notifications for multiple users
- `notifyMaintenanceDue($user, $stationName, $dueDate)` - Notify about maintenance due
- `notifyLeaveRequest($user, $requesterName, $leaveType, $requestId)` - Notify about new leave request
- `notifyLeaveRequestStatusChange($user, $status, $leaveType, $requestId)` - Notify about leave request status change
- `notifyItConcern($user, $stationName, $category, $concernId)` - Notify about IT concern
- `notifyItConcernStatusChange($user, $status, $stationName, $concernId)` - Notify about IT concern status change
- `notifyMedicationRequest($user, $requesterName, $requestId)` - Notify about medication request
- `notifyPcAssignment($user, $pcNumber, $stationName)` - Notify about PC assignment
- `notifySystemMessage($user, $title, $message, $data)` - Send system message
- `notifyAllUsers($title, $message, $data)` - Send notification to all users
- `notifyUsersByRole($role, $type, $title, $message, $data)` - Send notification to users with specific role

## Frontend Components

### NotificationBell
**Location**: `resources/js/components/notification-bell.tsx`

Displays a bell icon in the header with unread count badge. Clicking opens the notification dropdown.

Features:
- Real-time unread count
- Automatic polling every 30 seconds
- Badge indicator for unread notifications
- Dropdown integration

### NotificationDropdown
**Location**: `resources/js/components/notification-dropdown.tsx`

Dropdown component showing recent notifications with quick actions.

Features:
- List of recent notifications (up to 10)
- Mark as read/unread
- Delete individual notifications
- Mark all as read
- View all notifications link

### Notifications Index Page
**Location**: `resources/js/pages/Notifications/Index.tsx`

Full-featured page for viewing and managing all notifications.

Features:
- Paginated list of all notifications
- Visual distinction between read and unread
- Bulk actions (mark all as read)
- Delete individual notifications
- Formatted timestamps

## Usage Examples

### Creating Notifications in Controllers

```php
use App\Services\NotificationService;

class YourController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function someAction()
    {
        // Example 1: Notify single user
        $this->notificationService->notifyLeaveRequest(
            $admin->id,
            $employee->name,
            'Sick Leave',
            $leaveRequest->id
        );

        // Example 2: Notify multiple users
        $hrAdminUsers = User::whereIn('role', ['Super Admin', 'Admin', 'HR'])->get();
        foreach ($hrAdminUsers as $admin) {
            $this->notificationService->notifyLeaveRequest(
                $admin->id,
                $employee->name,
                $leaveType,
                $leaveRequest->id
            );
        }

        // Example 3: Custom notification
        $this->notificationService->create(
            $user->id,
            'custom_type',
            'Custom Title',
            'Custom message here',
            ['extra' => 'data', 'link' => '/some/path']
        );

        // Example 4: Notify all users
        $this->notificationService->notifyAllUsers(
            'System Maintenance',
            'The system will be down for maintenance on Sunday at 2 AM.',
            ['scheduled_time' => '2024-12-01 02:00:00']
        );

        // Example 5: Notify by role
        $this->notificationService->notifyUsersByRole(
            'Admin',
            'system',
            'New Feature Released',
            'Check out the new dashboard analytics feature!'
        );
    }
}
```

### Notification Types

The following notification types are predefined with helper methods:

1. **maintenance_due** - PC/Station maintenance notifications
2. **leave_request** - Leave request submissions and status changes
3. **it_concern** - IT issues and their resolution
4. **medication_request** - Medication request submissions
5. **pc_assignment** - PC assignment to stations
6. **system** - General system announcements

You can also create custom notification types by using the `create()` method directly.

## Setup Instructions

1. **Run the migration**:
   ```bash
   php artisan migrate
   ```

2. **Import the NotificationBell component in your layout** (already done):
   ```tsx
   import { NotificationBell } from '@/components/notification-bell';
   ```

3. **Add the bell to your header** (already done in app-header.tsx):
   ```tsx
   <NotificationBell />
   ```

4. **Use NotificationService in your controllers**:
   ```php
   use App\Services\NotificationService;
   
   // In constructor
   protected $notificationService;
   public function __construct(NotificationService $notificationService) {
       $this->notificationService = $notificationService;
   }
   ```

## Customization

### Adding New Notification Types

1. Add a helper method to `NotificationService.php`:
   ```php
   public function notifyCustomEvent(User|int $user, string $param): Notification
   {
       return $this->create(
           $user,
           'custom_event',
           'Event Title',
           "Event description with {$param}.",
           ['param' => $param]
       );
   }
   ```

2. Optionally add custom styling in `NotificationDropdown.tsx`:
   ```tsx
   const getNotificationColor = (type: string) => {
       const colors: Record<string, string> = {
           // ... existing types
           custom_event: 'text-pink-500',
       };
       return colors[type] || 'text-gray-500';
   };
   ```

### Changing Polling Interval

Edit `notification-bell.tsx` and modify the interval:
```tsx
const interval = setInterval(fetchUnreadCount, 60000); // Poll every 60 seconds
```

## Best Practices

1. **Use NotificationService**: Always use the NotificationService helper methods instead of creating notifications directly
2. **Provide Context**: Include relevant IDs and data in the `data` field for future linking capabilities
3. **Be Specific**: Use clear, actionable titles and messages
4. **Target Appropriately**: Only notify users who need to know about the event
5. **Clean Up**: Consider implementing automatic cleanup of old read notifications
6. **Performance**: Notifications are indexed on `user_id` and `read_at` for optimal query performance

## Future Enhancements

- Push notifications via websockets/broadcasting
- Email notifications for important events
- Notification preferences/settings per user
- Custom notification sounds
- Notification categories/filtering
- Batch notification management
- Notification templates
- Read receipts and tracking

## Integration Examples

The notification system is already integrated with:

1. **Leave Requests** (`LeaveRequestController.php`)
   - Notifies HR/Admin when new leave request is submitted
   - Notifies employee when their leave request is approved/denied

You can add similar integrations to other controllers like:
- IT Concerns
- Medication Requests
- PC Maintenance
- Station Assignments
- Attendance Issues

## Troubleshooting

### Notifications not appearing
- Check browser console for errors
- Verify the CSRF token is properly configured
- Ensure the user is authenticated
- Check database for notification records

### Unread count not updating
- Verify polling is working (check Network tab in browser)
- Check for JavaScript errors
- Ensure the `/notifications/unread-count` endpoint is accessible

### Permission issues
- Verify routes are properly protected with auth middleware
- Check that users have proper permissions
- Ensure notification ownership is validated in controller
