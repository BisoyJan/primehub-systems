# Automatic Logout on Inactivity

This feature automatically logs out users after a specified period of inactivity to enhance security.

## Overview

The system tracks user activity on both the backend and frontend:

- **Backend**: The `UpdateLastActivity` middleware tracks session activity and enforces logout on the server side
- **Frontend**: The `useInactivityLogout` React hook monitors user interactions and initiates logout on the client side

## Configuration

### User Preferences

Each user can configure their own inactivity timeout preference at `/settings/preferences`:

1. **Enable/Disable Auto Logout**: Toggle to enable or disable automatic logout
2. **Timeout Duration**: Select from 5 minutes to 8 hours

**Default Behavior**: Auto-logout is **disabled by default** for all new accounts. Users can enable it and set their preferred timeout in their preferences.

### Available Timeout Options

- 5 minutes
- 10 minutes
- 15 minutes
- 30 minutes
- 1 hour
- 2 hours
- 4 hours
- 8 hours

### Database Storage

The user's preference is stored in the `inactivity_timeout` column in the `users` table:
- `NULL` = Auto-logout disabled (no automatic logout)
- Integer value = Timeout in minutes

## How It Works

### Backend Implementation

1. **Middleware** (`app/Http/Middleware/UpdateLastActivity.php`):
   - Checks the user's `inactivity_timeout` preference
   - If `null`, skips timeout check (auto-logout disabled)
   - If set, tracks the last activity timestamp in the session
   - Automatically logs out inactive users and redirects to login page
   - Registered in `bootstrap/app.php` as part of the web middleware stack

### Frontend Implementation

1. **Hook** (`resources/js/hooks/use-inactivity-logout.ts`):
   - Monitors user interactions: mouse movements, clicks, keypresses, scrolls, and touches
   - Shows a warning toast **1 minute** before automatic logout
   - Automatically triggers logout after the configured timeout period
   - Resets the timer on any user activity

2. **Integration** (`resources/js/layouts/app/app-sidebar-layout.tsx`):
   - Reads the user's `inactivity_timeout` preference from shared data
   - Passes the preference to the hook
   - Disables the hook if `inactivity_timeout` is `null`

## User Experience

### When Auto-Logout is Enabled

1. User is active → Timer resets on any interaction
2. User becomes inactive for (timeout - 1) minutes → Warning toast appears
3. User remains inactive for another minute → Automatic logout
4. After logout → User is redirected to the login page with a notification

### When Auto-Logout is Disabled

User stays logged in until:
- They manually log out
- Their session expires (based on Laravel session lifetime)

### Warning Toast

A warning toast appears 1 minute before logout with the message:
> **Inactivity Warning**  
> You will be logged out in 1 minute due to inactivity.

### Logout Notification

After automatic logout, users see:
> **Session Expired**  
> You have been logged out due to inactivity.

## Settings Page

The preferences can be configured at `/settings/preferences`:

### Options Available

1. **Enable Auto Logout Toggle**: Switch to enable/disable automatic logout
2. **Inactivity Timeout Dropdown**: Select timeout duration (only shown when enabled)
3. **Status Preview**: Shows current configuration status

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
4. **User Control**: Users can configure their own security preferences
5. **Default Safe**: Auto-logout is disabled by default, respecting user convenience

## Testing

To test the feature:

1. Log in to the application
2. Go to `/settings/preferences` and enable auto-logout with a short timeout (e.g., 5 minutes)
3. Remain inactive (no mouse/keyboard/touch activity)
4. After (timeout - 1) minutes, a warning toast should appear
5. After another minute, you should be automatically logged out
6. Verify you're redirected to the login page

## Technical Details

### Files Modified/Created

- `app/Http/Middleware/UpdateLastActivity.php` - Backend middleware (uses user preference)
- `app/Http/Controllers/Settings/PreferencesController.php` - Preferences controller
- `app/Http/Middleware/HandleInertiaRequests.php` - Shares inactivity_timeout to frontend
- `bootstrap/app.php` - Middleware registration
- `resources/js/hooks/use-inactivity-logout.ts` - Frontend hook
- `resources/js/layouts/app/app-sidebar-layout.tsx` - Hook integration with user preference
- `resources/js/pages/settings/preferences.tsx` - Settings UI for auto-logout
- `resources/js/types/index.d.ts` - TypeScript type definitions
- `database/migrations/2025_12_15_*_add_inactivity_timeout_to_users_table.php` - Migration

### Database Schema

```sql
-- users table
inactivity_timeout SMALLINT UNSIGNED NULL  -- NULL = disabled, value = minutes
```

### Dependencies

- **Laravel Inertia Router**: For programmatic logout on frontend
- **Sonner**: For toast notifications
- **React Hooks**: `useEffect`, `useCallback`, `useRef`
- **Shadcn/UI**: Switch and Select components

## Troubleshooting

### User is logged out too quickly
- Check the user's `inactivity_timeout` preference in the database
- Verify the preference shows correctly on `/settings/preferences`

### User is not being logged out when expected
- Ensure middleware is registered in `bootstrap/app.php`
- Verify `inactivity_timeout` is not `null` for the user
- Check browser console for any JavaScript errors

### Warning doesn't appear
- Check if `showWarning` is set to `true` in the hook
- Verify the Toaster component is rendered in the layout
- Check browser console for toast-related errors

### Settings not saving
- Check for validation errors (timeout must be between 5 and 480 minutes)
- Verify the PATCH request is reaching the controller

---

*Last updated: December 15, 2025*
