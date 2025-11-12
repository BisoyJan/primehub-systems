# Automatic Logout on Inactivity

This feature automatically logs out users after a specified period of inactivity to enhance security.

## Overview

The system tracks user activity on both the backend and frontend:

- **Backend**: The `UpdateLastActivity` middleware tracks session activity and enforces logout on the server side
- **Frontend**: The `useInactivityLogout` React hook monitors user interactions and initiates logout on the client side

## Configuration

### Environment Variables

Add the following to your `.env` file:

```env
# Inactivity timeout in minutes (default: 15)
INACTIVITY_TIMEOUT=15
```

### Timeout Settings

The default timeout is **15 minutes** of inactivity. You can adjust this in:

1. **Environment file** (`.env`): Set `INACTIVITY_TIMEOUT` to your desired value in minutes
2. **Session config** (`config/session.php`): The `inactivity_timeout` setting
3. **Frontend hook** (`resources/js/layouts/app/app-sidebar-layout.tsx`): Modify the `timeout` parameter

## How It Works

### Backend Implementation

1. **Middleware** (`app/Http/Middleware/UpdateLastActivity.php`):
   - Tracks the last activity timestamp in the session
   - Checks if the user has been inactive beyond the configured timeout
   - Automatically logs out inactive users and redirects to login page
   - Registered in `bootstrap/app.php` as part of the web middleware stack

### Frontend Implementation

1. **Hook** (`resources/js/hooks/use-inactivity-logout.ts`):
   - Monitors user interactions: mouse movements, clicks, keypresses, scrolls, and touches
   - Shows a warning toast **1 minute** before automatic logout
   - Automatically triggers logout after the configured timeout period
   - Resets the timer on any user activity

2. **Integration** (`resources/js/layouts/app/app-sidebar-layout.tsx`):
   - The hook is integrated into the main app layout
   - Runs automatically for all authenticated pages

## User Experience

1. User is active → Timer resets on any interaction
2. User becomes inactive for (timeout - 1) minutes → Warning toast appears
3. User remains inactive for another minute → Automatic logout
4. After logout → User is redirected to the login page with a notification

### Warning Toast

A warning toast appears 1 minute before logout with the message:
> **Inactivity Warning**  
> You will be logged out in 1 minute due to inactivity.

### Logout Notification

After automatic logout, users see:
> **Session Expired**  
> You have been logged out due to inactivity.

## Customization

### Change Timeout Duration

In `resources/js/layouts/app/app-sidebar-layout.tsx`:

```tsx
// Default: 15 minutes
useInactivityLogout({ timeout: 15, enabled: true });

// Custom: 30 minutes
useInactivityLogout({ timeout: 30, enabled: true });
```

### Disable Warning Toast

```tsx
useInactivityLogout({ 
    timeout: 15, 
    enabled: true,
    showWarning: false  // Disable warning toast
});
```

### Disable Feature Completely

```tsx
useInactivityLogout({ enabled: false });
```

## Tracked User Activities

The following user interactions reset the inactivity timer:

- Mouse movements (`mousemove`)
- Mouse clicks (`mousedown`, `click`)
- Keyboard input (`keypress`)
- Page scrolling (`scroll`)
- Touch events (`touchstart`)

## Security Considerations

1. **Dual Protection**: Both backend and frontend enforce the timeout for added security
2. **Session Invalidation**: The session is properly invalidated on logout
3. **CSRF Protection**: Uses Laravel's built-in CSRF protection for logout requests
4. **Configurable**: Can be adjusted based on security requirements

## Testing

To test the feature:

1. Log in to the application
2. Remain inactive (no mouse/keyboard/touch activity)
3. After (timeout - 1) minutes, a warning toast should appear
4. After another minute, you should be automatically logged out
5. Verify you're redirected to the login page

For faster testing, temporarily reduce the timeout:

```tsx
// Test with 2-minute timeout
useInactivityLogout({ timeout: 2, enabled: true });
```

## Technical Details

### Files Modified/Created

- `app/Http/Middleware/UpdateLastActivity.php` - Backend middleware
- `config/session.php` - Configuration
- `bootstrap/app.php` - Middleware registration
- `resources/js/hooks/use-inactivity-logout.ts` - Frontend hook
- `resources/js/layouts/app/app-sidebar-layout.tsx` - Hook integration

### Dependencies

- **Laravel Inertia Router**: For programmatic logout on frontend
- **Sonner**: For toast notifications
- **React Hooks**: `useEffect`, `useCallback`, `useRef`

## Troubleshooting

### User is logged out too quickly
- Check `INACTIVITY_TIMEOUT` in `.env`
- Verify the `timeout` parameter in the hook usage

### User is not being logged out
- Ensure middleware is registered in `bootstrap/app.php`
- Check browser console for any JavaScript errors
- Verify the hook is being called in the layout

### Warning doesn't appear
- Check if `showWarning` is set to `true`
- Verify the Toaster component is rendered in the layout
- Check browser console for toast-related errors

## Future Enhancements

Possible improvements:

- Add a countdown timer in the warning notification
- Allow users to extend their session from the warning dialog
- Track inactivity separately for different user roles
- Log inactivity logouts for security auditing
