# Form Request

*resources/js/pages/FormRequest/*

The Form Request module lets you submit and manage IT concerns, leave requests, and medication requests. Each section has its own workflow, approval process, and role-based access.

---

## What Each Role Can Do (Overview)

| Capability | Agent | Team Lead | Admin/HR | IT | Super Admin |
|---|---|---|---|---|---|
| **IT Concerns** | Own only | Own + campaign agents | All + status changes | All + resolve | All + full mgmt |
| **Leave Requests** | Own only | Own + campaign agents | All + approve/deny | — | All + full mgmt |
| **Leave Credits** | Own only | Campaign agents | All + edit/carryover | — | All + recalc/revert |
| **Medication Requests** | Own only | Own + campaign agents | All + approve/dispense | — | All + full mgmt |
| **Retention Policies** | — | — | Full CRUD | — | Full CRUD |

---

# IT Concerns

*resources/js/pages/FormRequest/ItConcerns/*

Report and track IT issues such as hardware, software, network, and other concerns.

## Submit an IT Concern

*resources/js/pages/FormRequest/ItConcerns/Create.tsx*

[Insert Screenshot: 'Create IT Concern' Form]

1. **Site** — Select the site location from the dropdown. *Required.*
2. **Station Number** — Type the station or computer number. *Required, max 50 characters.*
3. **Category** — Select **Hardware**, **Software**, **Network**, or **Other**. *Required.*
4. **Priority** — Select **Low**, **Medium**, **High**, or **Urgent**. *Required.*
5. **Description** — Describe the issue in detail. *Required, max 1000 characters.*
6. **File for Someone Else** — Toggle this switch on to submit on behalf of another employee. A search field appears — type and select the employee. *(Available to Team Leads, Admin, HR, and Super Admin only.)*
7. Click **Submit** to create the concern. Click **Cancel** to go back.

## View IT Concerns List

*resources/js/pages/FormRequest/ItConcerns/Index.tsx*

[Insert Screenshot: 'IT Concerns' List]

### Filters

1. **Search** — Type a keyword to search.
2. **Status** — Filter by **Pending**, **In Progress**, **Resolved**, or **Cancelled**.
3. **Category** — Filter by category.
4. **Priority** — Filter by priority level.
5. **Site** — Filter by site.
6. **Campaign** — Filter by campaign.

### Table

Columns: **ID**, **User**, **Site**, **Station**, **Category**, **Priority** (colored badge), **Status** (colored badge), **Created**, **Updated**, **Actions**.

**Row Actions:**
- **View** (eye icon) — Open the full concern details.
- **Edit** (pencil icon) — Modify the concern. *(Requires edit permission.)*
- **Delete** (trash icon) — Remove the concern. A confirmation dialog appears.

**Status Change Buttons:**
- **Resolve** (checkmark) — Mark as resolved. Opens a dialog for resolution notes.
- **Cancel** (X) — Cancel the concern. Opens a dialog for cancellation notes.
- **Play/Pause** — Mark as in progress.

*Agents and Team Leads see only their own concerns (or their campaign's concerns for Team Leads). IT and Super Admin see all.*

## View IT Concern Details

*resources/js/pages/FormRequest/ItConcerns/Show.tsx*

[Insert Screenshot: 'IT Concern' Detail]

1. Full details: **User**, **Site**, **Station Number**, **Category**, **Description**, **Priority** badge, **Status** badge.
2. **Assigned To** and **Resolved By** info (if applicable).
3. **Resolution Notes** — Shows the resolution description if resolved.
4. **Action Buttons**:
   - **Edit** — Modify the concern.
   - **Change Status** — Select a new status from the dropdown, add resolution notes, and click **Submit**. *(Requires status change permission.)*
5. Click **Back** to return to the list.

## Edit an IT Concern

*resources/js/pages/FormRequest/ItConcerns/Edit.tsx*

1. The form is pre-filled with existing data.
2. Modify any field. *(Same rules as Create apply.)*
3. **Status** and **Resolution Notes** — Visible only if you have the required permission. *(Admins and IT can change these.)*
4. Click **Submit** to save. Click **Cancel** to go back.

---

# Leave Requests

*resources/js/pages/FormRequest/Leave/*

Submit and manage leave requests (Vacation, Sick, Bereavement, Special, etc.).

## Submit a Leave Request

*resources/js/pages/FormRequest/Leave/Create.tsx*

[Insert Screenshot: 'Create Leave Request' Form]

### Leave Types

| Code | Leave Type |
|---|---|
| **VL** | Vacation Leave |
| **SL** | Sick Leave |
| **BL** | Bereavement Leave |
| **SPL** | Special Leave |
| **LOA** | Leave of Absence |
| **LDV** | Leave with pay deduction |
| **UPTO** | Undertime Pay Out |
| **ML** | Maternity Leave |

### Form Fields

1. **Leave Type** — Select from the dropdown. *Required.*
   - *Each leave type has different date rules. For example, SL cannot be more than 3 weeks in the past or more than 1 month in the future.*
2. **Start Date** and **End Date** — Click the date pickers to select. *Required.*
3. **Reason** — Type your reason. *Required, 10–1000 characters.*
4. **Campaign / Department** — Type the campaign or department. *Required.*
5. **File for Someone Else** — Toggle on to submit on behalf of another employee. Search and select the employee. *(Available to Team Leads, Admin, HR, and Super Admin only.)*
6. **Medical Certificate** (for SL) — Upload a file (JPEG, PNG, GIF, WebP, PDF, 4MB max). Or check **Medical Certificate Submitted** if you have already submitted it separately.
7. **SL with Undertime** (for SL) — Check this if you are combining sick leave with undertime.
8. **SPL Half Days** (for SPL) — If applying for Special Leave, a day-by-day breakdown appears. Check **Half Day** for any days that are half-days only.
9. **Credit Balances** — The system displays your remaining VL, SL, and SPL credits.
   - *If you have 6 or more attendance violation points, a warning appears.*
   - *If you have campaign schedule conflicts, an alert appears.*
10. Click **Submit** to send for approval. Click **Cancel** to go back.

## View Leave Requests List

*resources/js/pages/FormRequest/Leave/Index.tsx*

[Insert Screenshot: 'Leave Requests' List]

### Filters

1. **Status** — Filter by **Pending**, **Approved**, **Denied**, **Cancelled**.
2. **Leave Type** — Filter by type.
3. **Campaign** — Filter by campaign.
4. **Year** — Filter by year.
5. **Date Range** — Pick a range.
6. **User** — Search and select an employee.

### Table

Columns: **User**, **Leave Type** (colored badge), **Dates**, **Days**, **Status** badge, **Campaign**, **Reason**, **Created**, **Medical Cert** (eye icon if uploaded), **Actions**.

**Row Actions:**
- Click the row to view the full details.
- **Edit** (pencil icon) — Modify the request. *(Visible for pending requests if you are the owner or have edit permission.)*
- **Cancel** (X) — Cancel the request. Opens a dialog — type the reason. *(Visible for pending requests if you are the owner.)*
- **Delete** (trash icon) — Remove the request. *(Admin only.)*

**Medical Certificate Viewer:** Click the eye icon to open a full-screen viewer with zoom in/out and rotate controls. Click **Download** to save the file.

## View Leave Request Details

*resources/js/pages/FormRequest/Leave/Show.tsx*

[Insert Screenshot: 'Leave Request' Detail]

1. Full details: employee avatar and name, leave type badge, dates, days count, status badge, campaign, reason, medical certificate.
2. **Day-Level Statuses** — For approved leaves, each day shows its assigned status (SL credited, NCNS, advised absence, VL credited, etc.).
3. **Admin Notes** — Notes from the approving administrator.
4. **Conflict Alerts** — Shows campaign schedule overlaps if any.
5. **Timeline** — History of status changes and who made them.

### Approve/Deny (Admin/HR only)

1. Click **Approve**, **Deny**, or **Partial Deny**.
2. A confirmation dialog opens:
   - **Notes** — Type any notes (optional).
   - **Notify Employee** — Check this to send a notification.
   - **Paid Upgrade** — Toggle this if upgrading the leave to paid.
3. Click **Confirm** to apply.

### Day Status Assignment (Admin only)

For each day in the leave range:
- Select a status from the dropdown (SL credited, NCNS, advised absence, VL credited, UPTO, SPL credited, absent, partial).
- Toggle **Half Day** if applicable.
- Type notes if needed.

## Leave Calendar

*resources/js/pages/FormRequest/Leave/Calendar.tsx*

[Insert Screenshot: 'Leave Calendar' Screen]

1. Navigate months using the left/right arrows.
2. Toggle between **Single Campaign** and **Multi Campaign** view.
3. Filter by **Campaign**, **Leave Type**, or **Status**.
4. Each day shows leave entries with employee name and leave type badge.
5. *Team Leads see only their own campaign's employees.*

## Leave Credits

*resources/js/pages/FormRequest/Leave/Credits/*

### Credits List

*resources/js/pages/FormRequest/Leave/Credits/Index.tsx*

[Insert Screenshot: 'Leave Credits' List]

**Filters:** Year, Campaign, Site, Status. Search by employee name.

**Table:** Each row shows an employee with VL, SL, and SPL totals (used / remaining), carryover badge, and regularization status.

- Click the expand arrow to see a month-by-month breakdown (month name, credits earned, used, balance).
- Click the **pencil** icon on any month to adjust credits (Admin only).
- Click the **carryover** badge to open the carryover adjustment dialog.

**Edit Monthly Credits (Admin):**
1. Click the pencil icon on a month.
2. **Credits Earned** — Type a number (0–20). *Required.*
3. **Reason** — Type the reason for the change. *Required, max 500 characters.*
4. Click **Save**.

**Carryover Adjustment (Admin):**
1. Click the carryover badge.
2. **Carryover Credits** — Type a number (0–30). *Required.*
3. **Year** — Type the year. *Required.*
4. **Reason** — Type the reason. *Required, max 500 characters.*
5. **Notes** — Optional.
6. Click **Save**.

### Employee Credits Detail

*resources/js/pages/FormRequest/Leave/Credits/Show.tsx*

[Insert Screenshot: 'Employee Leave Credits' Detail]

- Employee avatar and name.
- Summary cards: VL used/balance, SL used/balance, SPL used/balance, carryover.
- **Monthly Credit History** table with earned, used, balance per month. Click the pencil to edit.
- **Leave Requests Used** table — linked to the employee's approved leaves.
- **Action Buttons** (Admin only):
  - **Recalculate Credits** — Recalculates all credits from scratch.
  - **Revert Edit** — Undoes the last manual change.
  - **Refresh** — Reloads the data.

---

# Medication Requests

*resources/js/pages/FormRequest/MedicationRequests/*

Request medication from the company clinic.

## Submit a Medication Request

*resources/js/pages/FormRequest/MedicationRequests/Create.tsx*

[Insert Screenshot: 'Create Medication Request' Form]

A 3-step wizard:

### Step 1: Select Medication

1. **Medication Type** — Select one of the following:
   - Declogen
   - Biogesic
   - Mefenamic Acid
   - Kremil-S
   - Cetirizine
   - Saridon
   - Diatabs
   *Required.*

2. **Onset of Symptoms** — Select when symptoms started:
   - **Just today**
   - **More than 1 day**
   - **More than 1 week**
   *Required.*

### Step 2: Reason

1. **Reason** — Describe why you need the medication. *Required, max 1000 characters.*

### Step 3: Agreement

1. **File for Someone Else** — Toggle on to submit on behalf of another employee. Search and select the employee. *(Available to Team Leads, Admin, HR, and Super Admin only.)*
2. Read the policy terms carefully.
3. Check **I agree to the policy**. *Required. If unchecked, the system shows an error.*

Click **Submit** to send the request.

## View Medication Requests

*resources/js/pages/FormRequest/MedicationRequests/Index.tsx*

[Insert Screenshot: 'Medication Requests' List]

**Filters:** Search keyword, filter by status or medication type.

**Table:** Columns — **User**, **Medication Type**, **Reason**, **Onset**, **Status** (colored badge: Pending / Approved / Dispensed / Rejected), **Created**, **Actions** (View, Delete).

Click **View** to see the full request. Click **Delete** to remove (with confirmation).

## View Medication Request Details

*resources/js/pages/FormRequest/MedicationRequests/Show.tsx*

[Insert Screenshot: 'Medication Request' Detail]

- Full details: employee name, work email, medication type, reason, onset, policy agreement indicator.
- Status badge.
- Admin notes (if any).
- Timestamps for created, approved, dispensed.

**Action Buttons (Admin/HR only):**
- **Approve** — Opens a confirmation dialog. Optionally type admin notes. Click **Submit**.
- **Dispense** — Marks as dispensed. Optionally type admin notes.
- **Reject** — Opens a confirmation dialog. Optionally type admin notes. Click **Submit**.

---

# Form Request Retention Policies

*resources/js/pages/FormRequest/RetentionPolicies.tsx*

[Insert Screenshot: 'Form Request Retention Policies' Screen]

Configure how long form request records are kept before automatic cleanup.

### Policies Table

Columns: **Name**, **Description**, **Retention** (months), **Sites** (count), **Status** (Active/Inactive), **Actions**.

**Actions:**
- **Toggle** switch — Activate or deactivate a policy.
- **Edit** (pencil icon) — Modify the policy.
- **Delete** (trash icon) — Remove the policy (with confirmation).

### Add a New Policy

1. Click **+ New Policy**. A dialog opens.
2. **Name** — Type a name. *Required.*
3. **Description** — Optional.
4. **Retention Months** — Type the number of months to keep records.
5. **Sites** — Select the sites this policy applies to.
6. Click **Save** to create.
