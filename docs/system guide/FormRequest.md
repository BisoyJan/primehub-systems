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
2. **Station Number** — Type the station or computer number. *Required (max 50 characters — enforced server-side).*
3. **Category** — Select **Hardware**, **Software**, **Network/Connectivity**, or **Other**. *Required.*
4. **Priority** — Select **Low**, **Medium**, **High**, or **Urgent**. *Required.*
5. **Description** — Describe the issue in detail. A live character counter shows your length. *Required (max 1000 characters — enforced server-side).*
6. **File for Someone Else** — Toggle this switch on to submit on behalf of another employee. A search field appears — type and select the employee. *(Available only when the backend exposes the employee list — Team Leads, Admin, HR, and Super Admin.)*
7. Click **Submit** to create the concern. Click **Cancel** to go back.

## View IT Concerns List

*resources/js/pages/FormRequest/ItConcerns/Index.tsx*

[Insert Screenshot: 'IT Concerns' List]

### Filters

1. **Search** — Type a keyword to search.
2. **Status** — Filter by **Pending**, **In Progress**, **Resolved**, or **Cancelled**.
3. **Category** — Filter by **Hardware**, **Software**, **Network/Connectivity** (shown as "Network" in the filter dropdown), or **Other**.
4. **Priority** — Filter by priority level.
5. **Site** — Filter by site.
6. **Campaign** — Filter by campaign.

There are also **Refresh** and **Play/Pause** (30-second auto-refresh) buttons in the toolbar.

### Table

Columns: **Date**, **Priority** (colored badge), **Submitted By**, **Site**, **Station**, **Category**, **Description**, **Status** (colored badge), **Resolved By**, **Actions**. *(Team Leads do not see the Actions column.)*

**Row Actions:**
- **Edit** (pencil icon) — Modify the concern. *(Visible if you have `it_concerns.edit` permission, or if you are the owner and the status is **Pending** or **In Progress**.)*
- **Cancel** (X icon) — Cancel the concern. Opens a confirmation dialog (no notes field). *(Visible only if you are the owner and the status is **Pending** or **In Progress**.)*
- **Delete** (trash icon) — Remove the concern. A confirmation dialog appears. *(Visible if you have `it_concerns.delete` permission, or if you are the owner and the status is **Pending**.)*

**Status Change Buttons** (require status-change permission):
- **Resolve** (checkmark) — Mark as resolved. Opens a dialog with **Status**, **Priority**, and **Resolution Notes** fields.
- *There are no separate "Cancel notes" or "Mark as In Progress" quick-buttons in the row; in-progress is reached via the Resolve / Update Status dialog.*

*Agents and Team Leads see only their own concerns (or their campaign's concerns for Team Leads). IT and Super Admin see all.*

## View IT Concern Details

*resources/js/pages/FormRequest/ItConcerns/Show.tsx*

[Insert Screenshot: 'IT Concern' Detail]

1. Full details: **User**, **Site**, **Station Number**, **Category**, **Description**, **Priority** badge, **Status** badge.
2. **Assigned To** and **Resolved By** info (if applicable).
3. **Resolution Notes** — Shows the resolution description if resolved.
4. **Action Buttons**:
   - **Edit** — Modify the concern.
   - **Update Status** — Opens a dialog where you select a new **Status** and **Priority** from dropdowns, type **Resolution Notes**, and click **Submit**. *(Requires status change permission.)*
5. Click **Back** to return to the list.

## Edit an IT Concern

*resources/js/pages/FormRequest/ItConcerns/Edit.tsx*

1. The form is pre-filled with existing data.
2. Modify any field. *(Same rules as Create apply.)*
3. **Status** and **Resolution Notes** — Visible only if you have the `it_concerns.assign` permission (IT staff).
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
| **SPL** | Solo Parent Leave |
| **LOA** | Leave of Absence |
| **LDV** | Leave due to Domestic Violence |
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
7. **SL with Undertime** (for SL) — Check this if you are combining sick leave with undertime. *When checked, every day of the leave is treated as a **Partial-day Absence** during approval.*
8. **SPL Half Days** (for SPL) — If applying for Solo Parent Leave, a day-by-day breakdown appears. Check **Half Day** for any days that are half-days only. *If your remaining SPL credits are not enough for a full day, the system automatically downgrades that day to a half-day.*
9. **Credit Balances** — The system displays your remaining VL, SL, and SPL credits.
   - *If you have 6 or more attendance violation points, a warning appears.*
   - *If you have campaign schedule conflicts, an alert appears.*
10. Click **Submit** to send for approval. Click **Cancel** to go back.

## View Leave Requests List

*resources/js/pages/FormRequest/Leave/Index.tsx*

[Insert Screenshot: 'Leave Requests' List]

### Filters

A row of status tabs across the top: **All**, **Upcoming**, **Pending**, **Approved**, **Denied**, **Cancelled**. Plus the following filter controls:

1. **Leave Type** — Multi-select of all leave codes.
2. **Campaign** — Multi-select campaign filter.
3. **Period** — Pick **All Periods**, **Upcoming**, **This Week**, **This Month**, or **Past**.
4. **User** — Search and select an employee.
5. Click **Filter** to apply, or **Clear** to reset.

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
   - **Review Notes** — Type any notes (optional).
   - **Day Status Assignment** (for SL/VL/SPL) — For each day in the range, pick the status (SL credited, NCNS, advised absence, VL credited, UPTO, SPL credited, absent, partial). For SL with Undertime, all days are locked to **Partial-day Absence**.
   - **SPL Half-Day Settings** (for SPL) — Toggle Half Day on any day; auto-downgrade applies when credits run low.
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
2. Toggle between **Single** (one month) and **Multi** (3 months — previous, current, next) view.
3. Filter by **Campaign**, **Leave Type**, or **Status** (Approved / Pending).
4. Each day shows leave entries with employee name and leave type badge.
5. *Team Leads see only their own campaign's employees.*

## Leave Credits

*resources/js/pages/FormRequest/Leave/Credits/*

### Credits List

*resources/js/pages/FormRequest/Leave/Credits/Index.tsx*

[Insert Screenshot: 'Leave Credits' List]

**Filters:** Year, Campaign, Role, Eligibility Status. Search by employee name.

**Table:** Each row shows an employee with VL, SL, and SPL totals (used / remaining), carryover badge, and regularization status.

- Click the expand arrow to see a month-by-month breakdown (month name, credits earned, used, balance).
- Click the **pencil** icon on any month to adjust credits (Admin only).
- Click the **carryover** badge to open the carryover adjustment dialog.

**Edit Monthly Credits (Admin):**
1. Click the pencil icon on a month.
2. **Credits Earned** — Type a number. *Required; range (0–20) is enforced server-side.*
3. **Reason** — Type the reason for the change. *Required; max 500 characters is enforced server-side.*
4. Click **Save**.

**Carryover Adjustment (Admin):**
1. Click the carryover badge.
2. **Carryover Credits** — Type a number. *Required; range (0–30) is enforced server-side.*
3. **Year** — Type the year. *Required.*
4. **Reason** — Type the reason. *Required; max 500 characters is enforced server-side.*
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
  - **Recalculate Credits** — Header button. Recalculates all credits from scratch.
  - **Revert Edit** — *Not a standalone button.* Open the **Credit Edit History** table and click a history entry to revert that specific change.

---

# Medication Requests

*resources/js/pages/FormRequest/MedicationRequests/*

Request medication from the company clinic.

## Submit a Medication Request

*resources/js/pages/FormRequest/MedicationRequests/Create.tsx*

[Insert Screenshot: 'Create Medication Request' Form]

A 2-step wizard:

### Step 1: Request Details

1. **Request for Employee** — *(Only shown to Team Leads, Admin, HR, and Super Admin.)* Search and select an employee to submit on their behalf. *Required if you have this permission; otherwise the request is filed for yourself.*
2. **Medication Type** — Select from the options listed by the system. *Default catalog includes:* Declogen, Biogesic, Mefenamic Acid, Kremil-S, Cetirizine, Saridon, Diatabs. *Required.*
3. **Reason** — Describe why you need the medication. *Required, max 1000 characters.*
4. **Onset of Symptoms** — Select when symptoms started. *Default options:* **Just today**, **More than 1 day**, **More than 1 week**. *Required.*

Click **Next** to continue.

### Step 2: Policy Agreement

1. Read the policy terms carefully.
2. Check **I agree to the policy**. *Required. If unchecked, the system shows an error.*
3. Click **Back** to return to Step 1, or **Submit** to send the request.

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
- **Mark as Dispensed** — Marks the request as dispensed. Optionally type admin notes.
- **Reject** — Opens a confirmation dialog. Optionally type admin notes. Click **Submit**.

---

# Form Request Retention Policies

*resources/js/pages/FormRequest/RetentionPolicies.tsx*

[Insert Screenshot: 'Form Request Retention Policies' Screen]

Configure how long form request records are kept before automatic cleanup.

### Policies Table

Columns: **Name**, **Description**, **Retention Period (Months)**, **Form Type**, **Applies To**, **Priority**, **Status** (Active/Inactive), **Actions**.

**Actions:**
- **Toggle** switch — Activate or deactivate a policy.
- **Edit** (pencil icon) — Modify the policy.
- **Delete** (trash icon) — Remove the policy (with confirmation).

### Add a New Policy

1. Click **+ New Policy**. A dialog opens.
2. **Policy Name** — Type a name. *Required.*
3. **Description** — Optional.
4. **Retention Period (Months)** — Type the number of months to keep records. *Required.*
5. **Priority** — Type a priority number. Higher numbers override lower ones. *Required.*
6. **Form Type** — Choose **All Form Types**, **Leave Requests Only**, **IT Concerns Only**, **Medication Requests Only**, or **Leave Credits Only**. *Required.*
7. **Applies To** — Choose **All Sites (Global)** or **Specific Site**. *Required.*
   - If **Specific Site**, pick the site from the dropdown that appears.
8. **Active Policy** — Toggle on or off.
9. Click **Save** to create.
