# Accounts

*resources/js/pages/Account/*, *resources/js/pages/Admin/ActivityLogs/*, *resources/js/pages/settings/*, *resources/js/pages/auth/*

---

## Account List

*resources/js/pages/Account/Index.tsx*

[Insert Screenshot: 'Account List' Screen Layout]

The main page that shows all user accounts.

### Filters & Search Bar

A row of controls at the top:

- **All Employees** dropdown — Click to open a searchable list. Type a name or scroll to pick one person, then click their name. *The list resets to all employees if you do not click a name before moving away.*
- **Filter by Role** dropdown — Click then select **All Roles**, **Super Admin**, **Admin**, **Team Lead**, **Agent**, **HR**, **IT**, or **Utility**. *The table shows only users with the selected role.*
- **Filter by Account Status** dropdown — Click then select **All Account Statuses**, **Pending**, **Approved**, **Pending Deletion**, or **Deleted**. *The table shows only accounts matching that status.*
- **Filter by Employee Status** dropdown — Click then select **All Employee Statuses**, **Active Employees**, or **Inactive Employees**. *The table is narrowed to active or inactive staff.*
- **Search** box — Type a name or email address, then press **Enter** on your keyboard. *Leave it blank to see everyone.*
- **Apply Filters** button — Click to reload the list with your chosen filters.
- **Clear Filters** button — Appears when any filter is active. Click to reset everything.
- **Refresh** button — Click to reload the list manually. The spinning icon means it is loading.
- **Auto-refresh (30s)** button — Click the **Play** icon to turn on automatic refreshing every 30 seconds. Click the **Pause** icon to turn it off.
- **Stale Accounts** button — Only **Super Admin**, **Admin**, and **IT** see this button. Click to open a window listing inactive employees whose **Resigned** date is **2 or more years** in the past. The button shows a red badge with the count (up to **99+**). *You cannot undo permanent deletion from this window.*

### Stale Accounts Window

Inside this window:
- Each entry shows the person's **Name**, **Email**, **Role**, **Hired Date**, and **Resigned** date.
- Click an entry or its checkbox to mark it, or click **Select All** / **Deselect All**.
- Click **Delete Selected** to permanently remove the checked accounts, or click **Delete All** to remove every stale account. *Permanent deletion cannot be undone and also removes all of their schedules.*
- Click **Close** to exit without deleting.

### Action Buttons

- **Create Account** — Click to open the create form.
- **Bulk Approve (number)** — Appears after checking the green checkbox(es) for Pending accounts. Click to approve all selected at once. *Only accounts not yet approved and not deleted can be selected.*
- **Bulk Revoke (number)** — Appears after checking the yellow checkbox(es) for Approved accounts. Click to open a confirmation window. You may choose **Revoke Only** or **Revoke & Send Emails**. *Revoking removes access and deactivates schedules.*
- **Clear Selection (number)** — Click to uncheck all selected accounts.

### Desktop Table

Nine columns:

- **Checkbox column** — Two stacked checkboxes appear in the header: a **green** one for selecting all Pending accounts on the page, and a **yellow** one for selecting all Approved accounts on the page. Each row also shows either a green or yellow checkbox depending on whether the account is Pending or Approved. Click a checkbox to select or deselect that account. *You cannot select your own account.*
- **Name** — Shows the profile picture (or initials as a colored circle), first name, middle initial if any, and last name.
- **Email** — The person's email address.
- **Role** — Colored label: **Super Admin** (purple), **Admin** (blue), **HR** (green). All other roles — **Team Lead**, **Agent**, **IT**, and **Utility** — display in gray.
- **Employee Status** — A toggle switch next to an icon badge that shows **Active** (green check icon) or **Inactive** (gray person-X icon). Click the switch to change the status.
  - *Switching from **Active to Inactive** opens a **Deactivate Employee?** warning — the person loses system access and all their schedules are deactivated.*
  - *Switching from **Inactive to Active** opens a **Re-hire Employee** dialog (see below).*
- **Account Status** — One of five badges:
  - **Approved** (green) – account is active.
  - **Pending** (yellow) – registered but not yet approved.
  - **Resigned** (purple) – has a hired date but access was revoked.
  - **Pending Deletion** (orange) – marked for deletion, waiting for admin confirmation.
  - **Deleted** (red) – deletion confirmed.
- **Hired Date** — The date the person was hired, or a dash if not set.
- **Created At** — The date the account was created.
- **Actions** — Icons and buttons that change depending on status:

For **normal (not deleted) accounts**:
  - **Edit** (pencil icon) — Click to open the edit form.
  - **Approve** (green person icon) — Click to approve a Pending account.
  - **Revoke** (yellow person-minus icon) — Click to open a confirmation window. Choose **Revoke Only** to remove access silently, or **Revoke & Send Email** to also notify management. *Revoking deactivates the employee and all schedules.*
  - **Delete** (red trash icon) — Click to mark the account for deletion. *The account moves to Pending Deletion status; an admin must confirm before it is fully removed.*

For **Pending Deletion** accounts:
  - **Confirm Delete** (green check icon) — Only **Super Admin**, **Admin**, and **IT** can see this. Click to permanently confirm deletion.
  - **Restore** (green rewind icon) — Click to bring the account back to normal.

For **Deleted** accounts:
  - **Restore** (green rewind icon) — **Super Admin**, **Admin**, **HR**, and **IT** can click this to restore the account.
  - **Permanent Delete** (red X icon) — Only **Super Admin**, **Admin**, and **IT** can click this. A confirmation box appears warning that all data will be lost forever. *This cannot be undone.*

### Re-hire Employee Dialog

Opens when you switch an inactive employee's toggle back to **Active**.

- Displays the employee's previous hire date for reference.
- **New Hired Date** — Pick the date you are re-hiring them on. *Required; the dialog cannot be confirmed until a date is set.*
- Click **Confirm Re-hire** to restore access and record the new hire date. Click **Cancel** to abort.
- After confirming, the **Schedule Assignment** dialog opens automatically.

### Schedule Assignment Dialog

Opens automatically right after a successful re-hire so you can assign a working schedule without leaving the page.

- Lists any existing schedules for the employee. Each schedule shows the **Campaign**, **Site**, **Shift Type**, scheduled times (in 12-hour format), and work days.
- Each schedule has an **Edit** link and either an **Active** badge or an **Activate** button. Click **Activate** to make that schedule the active one.
- If no schedules exist, the dialog shows a placeholder message.
- **Create new schedule for this employee** link — Opens the employee schedule create form with the user and effective date pre-filled.
- Click **Done** to close the dialog.

### Mobile Cards

On narrow screens, each account appears as a stacked card instead of a table row. Every action listed above is also available inside each card.

### Pagination

At the bottom, page numbers appear when there are many accounts. Click a number to jump to that page. The text above shows how many accounts are on the current page versus the total.

---

## Create Account

*resources/js/pages/Account/Create.tsx*

[Insert Screenshot: 'Create Account' Screen Layout]

Opens when you click **Create Account** on the list page.

### Personal Information section

- **First Name** — Type the person's first name. *Required; leaving it blank will cause a system rejection.*
- **Middle Initial (Optional)** — Type one letter for the middle initial. The system automatically capitalizes it.
- **Last Name** — Type the person's last name. *Required; leaving it blank will cause a system rejection.*

### Account Information section

- **Email Address** — Type the company email. Only addresses ending in **@primehubmail.com** or **@prmhubsolutions.com** are accepted. *Any other email domain will cause a system rejection.*
- **Role** — Click the dropdown and select from the available roles (**Super Admin**, **Admin**, **Team Lead**, **Agent**, **HR**, **IT**, **Utility**). The list of selectable roles is supplied by the backend. The default is **Agent**.
- **Hired Date** — Click the calendar icon and pick the first day of employment. *Optional; leave blank for non-employee accounts.*

### Security section

- **Password** — Type a password. *Required; leaving it blank will cause a system rejection.*
- **Confirm Password** — Type the same password again. *The two entries must match or the system will reject it.*

### Bottom buttons

- **Create Account** — Click to save. The button shows **Creating...** while saving. *If any required field is empty or invalid, an error message appears in red below the field and a toast also shows the first error.*
- **Cancel** — Click to go back to the account list without saving.

---

## Edit Account

*resources/js/pages/Account/Edit.tsx*

[Insert Screenshot: 'Edit Account' Screen Layout]

Opens when you click the **Edit** (pencil) icon on any account. All fields are pre-filled with the current information.

### Personal Information section

Same fields as Create: **First Name**, **Middle Initial (Optional)**, **Last Name**. *Required fields cannot be emptied.*

### Account Information section

Same fields as Create: **Email Address**, **Role**, **Hired Date**.

### Employee Status section (only shown when editing someone else's account)

- **Active Status** — Shows **Active** (green) or **Inactive** (gray). A switch lets you toggle. Switching from Active to Inactive opens a warning: "Deactivate Employee?" with a note that all their active schedules will be deactivated too. *Deactivating blocks the person from logging in.* Switching from Inactive to Active activates the employee immediately without opening the re-hire/schedule dialog you see on the list page.
- **Solo Parent** — Shows **Solo Parent** (blue) or **Not Solo Parent** (gray). Click the switch to turn it on or off. Enabling this makes the person eligible for Solo Parent Leave (SPL) credits. *Turning it on does not affect any other leave balances.*

### Change Password section

- **New Password** — Type a new password if you want to change it. *Leave blank to keep the current password unchanged.*
- **Confirm New Password** — Type the same password. *Only needed if you entered a new password above.*

### Bottom buttons

- **Update Account** — Click to save changes. The button shows **Updating...** while saving. *Any invalid field causes a red error message below the field and a toast also shows the first error.*
- **Cancel** — Click to go back to the account list without saving.

---

## Activity Logs

*resources/js/pages/Admin/ActivityLogs/Index.tsx*

[Insert Screenshot: 'Activity Logs' Screen Layout]

A record of every action people take in the system.

### Search & Filter

- **Search logs** box — Type any keyword to filter log entries. Results update automatically a short moment after you stop typing (debounced).
- **Filter by Event** dropdown — Select **All Events**, **Created**, **Updated**, **Deleted**, **Login**, or **Logout**.
- **Filter by User** dropdown — Select **All Users** or any user who has previously generated activity. The list is built from people who actually appear in the log.
- **Clear filters** (X icon) — Appears when any filter is active. Click to reset.

### Top-right buttons

- **Refresh** (circular arrow icon) — Click to reload the list manually.
- **Auto-refresh (30s)** — Click **Play** to turn on automatic refresh every 30 seconds; click **Pause** to stop. The button changes color to indicate the current state.
- **Export CSV** (download icon) — Click to download the current filtered list as a CSV file.

### Event Table

Seven columns:

- **User** — Name of the person who performed the action.
- **Event** — Colored badge: **created** (green), **updated** (blue), **deleted** (red), **login** (purple), **logout** (gray).
- **Subject** — The type of item affected and its ID number (e.g., `User #12`).
- **Description** — A short sentence describing what happened.
- **Changes** — Shows the number of fields that changed, or a dash if no changes were recorded.
- **Date** — The date and time, plus a "time ago" label (e.g., "2 hours ago").
- **Eye icon** — Click any row (or the eye icon) to open a detail panel sliding in from the right.

### Detail Slide-in Panel

Clicking a row opens a panel on the right side showing:

- **Event** badge and description at the top.
- **User**, **Subject**, **Date**, and **Time Ago** in a summary grid.

For **Updated** events: A list of changed fields. Each field shows the **Old** value (red background) next to the **New** value (green background) with an arrow between them.

For **Created** events: A table showing every field and its value when the item was first made.

For **Deleted** events: A table showing every field and its value at the time of deletion.

For **Login/Logout** or other events: The raw recorded data, if any.

Click outside the panel or press the **X** button to close it.

### Mobile View

On narrow screens, each entry appears as a stacked card. Tap a card to open the detail panel.

### Pagination

Shows how many of the total results are displayed. Page number links appear below.

---

## Settings

### Account Settings

*resources/js/pages/settings/account.tsx*

[Insert Screenshot: 'Account Settings' Screen Layout]

**Profile Picture:**
- Shows your current profile photo or initials in a circle.
- Click the **camera icon** (bottom-right of the photo) or **Change Photo** to pick a new one. The system opens a **crop window** where you can adjust and crop the image before saving. Accepted formats: **JPG, JPEG, PNG, WebP**. Maximum size: **2MB**.
- Click **Remove** (only appears if you already have a photo) to delete it.
- *Uploading a file larger than 2MB or in the wrong format will cause a system rejection.*

**Account Information:**
- Fields: **First Name**, **Middle Initial (Optional)**, **Last Name**, **Email Address** — all pre-filled with your current information.
- Type your changes, then click **Save Changes**. A green "Changes saved successfully" message appears if it works.
- *Required fields cannot be emptied. Only @primehubmail.com and @prmhubsolutions.com emails are accepted.*

**Email Verification:**
- Shows a panel labeled **Email Verified** (green check icon, **Verified** badge) or **Email Not Verified** (amber X icon, **Unverified** badge).
- If unverified, an amber warning box appears with a link to resend the verification email. A green confirmation message appears after the link is sent. *You may not be able to use certain features until your email is verified.*

**Delete Account:**
- Click **Delete Account** to open a confirmation dialog.
- An amber box warns: "Account deletion requires admin confirmation." After requesting deletion, the account is marked **Pending Deletion** and an **Admin** or **IT** user must confirm before it is fully removed. Until then, you can reactivate by logging in and setting a new password.
- In the dialog, type your password and click **Delete Account**. The button shows **Deleting...** while processing. *Wrong password causes a system rejection.*
- Click **Cancel** to close the dialog without deleting.

### Password

*resources/js/pages/settings/password.tsx*

[Insert Screenshot: 'Password Settings' Screen Layout]

- **Current password** — Type your existing password.
- **New password** — Type the new password you want.
- **Confirm password** — Type the new password again.
- Click **Save password**. A "Saved" message appears when successful. *All three fields are required. The new password and confirmation must match. The current password must be correct or the system will reject it.*

### Appearance

*resources/js/pages/settings/appearance.tsx*

[Insert Screenshot: 'Appearance Settings' Screen Layout]

Three choices: **Light**, **Dark**, or **System** (automatically follows your computer's setting). Click your preferred option. The change takes effect immediately.

### Preferences

*resources/js/pages/settings/preferences.tsx*

[Insert Screenshot: 'Preferences' Screen Layout]

**Automatic Logout:**
- Toggle **Enable Auto Logout** on or off. When on, you are automatically logged out after a period of inactivity.
- If enabled, pick a timeout duration from the dropdown: **5 minutes**, **10 minutes**, **15 minutes**, **30 minutes**, **1 hour**, **2 hours**, **4 hours**, or **8 hours**.
- A status box shows your current setting. A warning appears 1 minute before logout.

**Notification Preferences:**

Several categories with individual toggle switches:

- **General** — System messages, Account Deletion, Account Reactivation, Account Restored.
- **Leave & Attendance** — Leave Requests, Attendance Status, Undertime Approval, Break Overage.
- **IT & Equipment** — IT Concerns (support tickets), Maintenance Due, PC Assignment.
- **Requests** — Medication Requests.
- **Coaching** — Coaching Sessions, Coaching Acknowledged, Coaching Reviewed, Coaching Ready for Review, Coaching Reminders, Coaching Alerts.

Each notification type has a label and description. Toggle the switch on or off as desired.

Click **Save Preferences** at the bottom. A green "Preferences saved successfully!" message confirms the change.

### Two-Factor Authentication

*resources/js/pages/settings/two-factor.tsx*

[Insert Screenshot: 'Two-Factor Authentication' Screen Layout]

When **Disabled** (red badge):
- Click **Enable 2FA**. A setup window opens with a **QR code** and a **manual setup key**. Scan the QR code with an authenticator app (like Google Authenticator or Microsoft Authenticator) or type the manual key.
- After scanning, enter a code from the app to confirm setup.
- If you close the window mid-setup, a **Continue Setup** button appears so you can resume.

When **Enabled** (green badge):
- **Recovery Codes** — Click to view or regenerate your backup codes. Store these in a safe place; each code can be used once to log in if you lose access to your authenticator app.
- **Disable 2FA** (red button with shield icon) — Click to turn off two-factor authentication. *You will no longer be prompted for a code during login.*

---

## Authentication Pages

### Login

*resources/js/pages/auth/login.tsx*

- **Email address** — Type your company email. Only **@primehubmail.com** and **@prmhubsolutions.com** emails are accepted.
- **Password** — Type your password.
- **Remember me** — Check this box to stay logged in on this device.
- **Log in** — Click to sign in. A spinning icon shows while processing.
- **Forgot password?** — Click to go to the password reset page.
- **Sign up** — Link at the bottom for new users to register.

*If your account is not yet approved, you will be redirected to the Pending Approval page. If your credentials are wrong, a red error message appears.*

### Register

*resources/js/pages/auth/register.tsx*

- **First Name** — Type your first name. *Required.*
- **Middle Initial (Optional)** — Type one letter.
- **Last Name** — Type your last name. *Required.*
- **Email address** — Type your company email. Only **@primehubmail.com** and **@prmhubsolutions.com** are accepted. *Other domains will be rejected.*
- **Password** — Type a password.
- **Confirm password** — Type the same password again.
- **Create account** — Click to submit. After successful registration, you are taken to the Pending Approval page. *Passwords must match.*

### Forgot Password

*resources/js/pages/auth/forgot-password.tsx*

- **Email address** — Type the email you used to register.
- **Email password reset link** — Click to send a reset link to your inbox. A green status message confirms it was sent. *If the email is not found in the system, no error is shown (for security).*

### Reset Password

*resources/js/pages/auth/reset-password.tsx*

You reach this page by clicking the link from the reset email.

- **Email** — Pre-filled and read-only. This is the email you requested the reset for.
- **Password** — Type your new password.
- **Confirm password** — Type it again.
- **Reset password** — Click to save. *Passwords must match.*

### Pending Approval

*resources/js/pages/auth/pending-approval.tsx*

Shown after registering or when your account access has been disabled.

- A **yellow Clock** icon and the heading **Account Created Successfully!** mean your account is waiting for administrator approval. A message explains that an admin will review and approve it, and you will receive an email when approved.
- A **red Clock** icon and the heading **Account Access Disabled** mean your account has been disabled because you are no longer employed and an administrator revoked your access. Contact HR or your system administrator if you believe this is a mistake.
- The page automatically rechecks your status every **15 seconds** and redirects you in once you are approved.
- **Log Out** — Click to sign out.
- **try logging in again** — Click to return to the login page.

### Verify Email

*resources/js/pages/auth/verify-email.tsx*

- A message tells you to check your email and click the verification link.
- **Resend verification email** — Click to send a new link.
- **Log out** — Click to sign out.

### Two-Factor Challenge

*resources/js/pages/auth/two-factor-challenge.tsx*

Appears during login when two-factor authentication is enabled.

**Authentication Code** (default):
- A row of numbered boxes (one digit each). Type the 6-digit code from your authenticator app.
- Click **Continue**.

**Recovery Code** (alternative):
- Click the link to switch to recovery code mode.
- Type one of your recovery codes.
- Click **Continue**.

*You can switch back and forth between code and recovery modes. An incorrect code shows an error message.*

### Account Deleted

*resources/js/pages/auth/account-deleted.tsx*

Shown when you try to log in but your account is marked for deletion.

- An amber **warning** icon and a message: "Your Account is Pending Deletion."
- **To Reactivate:**
  - **Email** — Pre-filled and cannot be changed.
  - **New Password** — Type a new password. An eye icon toggles visibility.
  - **Confirm New Password** — Type it again.
  - **Reactivate Account** — Click to restore access immediately.
- **Back to Login** — Click to go to the login page.

### Confirm Password

*resources/js/pages/auth/confirm-password.tsx*

Shown before accessing certain sensitive areas of the system.

- **Password** — Type your current password.
- **Confirm password** — Click to proceed. *If the password is wrong, an error message appears.*
