# Attendance

*resources/js/pages/Attendance/*

The Attendance module is where you manage attendance records, employee schedules, biometric scans, violation points, and data uploads. It is divided into sub-sections accessible from the **Attendance Hub**.

---

## Attendance Hub

*resources/js/pages/Attendance/Main/Hub.tsx*

[Insert Screenshot: 'Attendance Hub' Screen]

This is the main entry point. Click any action card to navigate:

| Card | What It Does | Who Can See It |
|---|---|---|
| **Calendar View** | View attendance in a monthly calendar | Everyone |
| **View All Records** | Browse and filter all records in a table | Everyone |
| **Spreadsheet View** | Per-employee x per-day grid of hours and leave codes | Everyone |
| **Manual Attendance** | Create attendance records by hand | Users with create permission |
| **Import Biometric** | Upload and process biometric TXT files | Users with import permission |
| **Daily Roster** | Generate attendance for scheduled employees | Users with create permission |
| **Review Flagged** | Review and verify records needing attention | Users with review permission |

**Quick Filters** (below the cards):
- **Pending Verification** — Jump to records awaiting verification.
- **NCNS Records** — Jump to No Call No Show records.
- **Tardy Records** — Jump to tardy records.
- **Unverified Records** — Jump to unverified records.

---

## Attendance Records (View All)

*resources/js/pages/Attendance/Main/Index.tsx*

[Insert Screenshot: 'Attendance Records' Table]

The main records table with search, filtering, multi-select, and quick actions.

### Filters

1. **Employee** — Click to search and select employees. *(Hidden for Agents, IT, Utility — you see only your own records.)*
2. **Status** — Pick from: On Time, Tardy, Half Day Absence, Advised Absence, NCNS, Undertime, Undertime (>1hr), Failed Bio In, Failed Bio Out, Needs Review, On Leave.
3. **Site** — Select a site from the dropdown.
4. **Campaign** — Select one or more campaigns.
5. **Verification Status** — Choose **All Records**, **Pending Verification**, or **Verified**.
6. **Date From** and **Date To** — Click to pick a date range.
7. **Needs Review** — Toggle this button to show only flagged records.
8. Click **Search** to apply. Click **Clear Filters** to reset.

### Action Buttons

- **Refresh** icon — Manually reload the list.
- **Play** / **Pause** — Turn auto-refresh on or off (updates every 30 seconds).
- **Calendar View** — Switch to the calendar view.
- **Manual Attendance** — Go to create a manual entry.
- **Import Biometric** — Go to upload biometric files.
- **Daily Roster** — Go to the daily roster.
- **Review Flagged** — Go to review records.

### Bulk Actions

Select records using the checkboxes on the left. When one or more are selected:

- **Quick Approve (N)** — Approve eligible records in bulk. *(Requires approve permission.)*
- **Delete (N)** — Permanently delete selected records. A confirmation dialog appears: *"Are you sure you want to delete N record(s)? This action cannot be undone."* *(Requires delete permission.)*

### Row-Level Actions

- **Approve** (green check) — For on-time records with no issues.
- **Review** (amber alert) — For records needing review (overtime &gt; 30 min, not approved).
- **Verify** (pencil) — Opens the Review page for unverified records.
- **Notes** — Click to open a dialog showing employee notes and admin notes side by side.

### Pagination

Use the page numbers at the bottom to navigate.

*If a record spans multiple biometric sites, the row appears with an orange background.*

---

## Attendance Calendar

*resources/js/pages/Attendance/Main/Calendar.tsx*

[Insert Screenshot: 'Attendance Calendar' Screen]

A month-at-a-glance calendar for a single employee.

### Select an Employee

1. **Campaign** — Pick a campaign from the dropdown (optional).
2. **Employee** — Click the search field, type a name, and select an employee. *This is required to view the calendar.*
3. **Verification Status** — Choose **All Records**, **Verified Only**, or **Non-Verified Only**.

### Navigate the Calendar

- Click the left/right arrows to move between months.
- The current month and year are shown at the top.
- Each day cell shows:
  - Date number.
  - Color-coded square for attendance status.
  - Time In / Time Out (e.g., "In: 07:02 Out: 16:05").
  - Verified shield icon (green) if verified.
  - Red **X** if unapproved overtime; green if approved.
- Today has a blue highlight.

### View Day Details

Click any day with data. A dialog opens showing:

- **Status** — Primary and secondary status badges.
- **Schedule** — Shift type, time in/out, site.
- **Actual Times** — Time in and time out.
- **Total Hours Worked**.
- **Time Adjustments** — Tardy, undertime, overtime (with approved/not-approved indicators).
- **Notes** — Employee notes and verification notes.
- **Warnings** — Amber card if suspicious patterns detected.

In the dialog footer:
- Click **Approve** (green) for simple on-time records.
- Click **Verify** (blue) to open the Review page for records needing attention.
- *These buttons are only visible if you have the required permissions.*

---

## Daily Roster

*resources/js/pages/Attendance/Main/DailyRoster.tsx*

[Insert Screenshot: 'Daily Roster' Screen]

Record attendance for all scheduled employees on a given date. Has two tabs.

### Filters

1. **Employee** — Search for a specific employee, or leave as **All Employees**.
2. **Date** — Pick the date.
3. **Site** — Select a site.
4. **Campaign** — Select a campaign.
5. **Status** — Choose **All Statuses**, **Pending Entry**, **Already Recorded**, or **On Leave**.
6. Click **Search** to apply. Click **X** to clear.

### Statistics Bar

Shows **Total**, **Pending**, **Recorded**, and **On Leave** counts.

### Tab: Record Time-In

- Each row shows an employee's name, site, campaign, shift type, scheduled time range, and status.
- **Pending Entry** (yellow) — No record yet. Click **Generate** to create one.
- **Recorded** — An entry exists. Click **Edit** to modify.
- **On Leave** (blue) — Employee is on leave. Click **Review** to open the Review page.
- *Team Leads see no Action column in this tab.*

### Tab: Complete Time-Out

- Lists employees from previous shifts whose time-out has not been recorded.
- *If none, a green checkmark shows: "All time-outs are complete."*
- Click **Add Time-Out** for any employee to record their time-out.

### Generate / Edit Dialog

Click **Generate** or **Edit** to open a dialog:

1. Review **Schedule Info** — Site, shift type, scheduled times, grace period.
2. **Status** — The system suggests a status (shown in a green/amber box). You can override it using the dropdown. Click **Use Suggested** to revert.
3. **Time In** — Date and time of the employee's time-in.
4. **Time-In Only** toggle — Turn this on if the employee is still on shift (complete time-out next day).
5. **Time Out** — Date and time of time-out (hidden if Time-In Only is on).
6. **Overtime Approval** (blue section, shown when overtime is detected) — Check the box to approve.
7. **Set Home** (amber section, for undertime over 30 minutes) — Toggle on if the employee was sent home early.
8. **Undertime Approval** — Choose **Generate Points** or **Lunch Used**.
   - *If you are an Admin or HR, you can Approve directly. If you are a Team Lead, click **Request Approval**.*
9. **Notes** — Type a reason or explanation (500 characters max).
10. **Verification Notes** — Type admin notes. Quick-phrase buttons are available: **Manual entry**, **Verified by supervisor**, etc.
11. Click **Create & Verify** (or **Update Record**) to save.

### Add Time-Out Dialog

Click **Add Time-Out** for a pending record:

1. Review the schedule info and existing time-in.
2. **Time Out** — Date and time.
3. A live violations preview shows detected issues or **"On Time — no violations"**.
4. **Overtime Approval** — Check to approve if applicable.
5. **Verification Notes** — Type notes.
6. Click **Complete Time-Out**.

---

## Spreadsheet View

*resources/js/pages/Attendance/Main/Spreadsheet.tsx*

[Insert Screenshot: 'Attendance Spreadsheet' Screen]

A full-month grid showing every employee x every day of the month. Color-coded cells for quick scanning.

### Toolbar

- **Month Navigation** — Click left/right arrows to move between months. Click **Today** to return to the current month.
- **Employee** — Search for a specific employee, or leave as **All Employees**.
- **Campaign** — Select a campaign.
- Click **Apply Filters**.

### Color Legend

At the top, a bar shows what each color means:

| Label | Meaning |
|---|---|---|
| **Hours** | Verified attendance with hours |
| **Tardy** | Late arrival |
| **ABS** | Absent / Half-day / NCNS / Advised |
| **OFF** | Non-work day / Day off |
| **BIO** | Unverified biometric — needs review |
| **PART** | Partially verified — time-out pending |
| **VL** | Vacation Leave |
| **SL** | Sick Leave |
| **P-SL** | Partial-day Absence (SL with Undertime) — worked hours counted |
| **ML** | Maternity / Paternity Leave |
| **LOA** | Leave of Absence |
| **UPTO** | Unpaid Time Off |
| **BL** | Bereavement Leave |
| **SPL** | Special Leave |
| **NMR** | Needs Manual Review |

### Grid

- Sticky left columns: **Name** and **Pts** (total attendance points).
- Each day column shows the day number and weekday abbreviation.
- Weekends have a gray background; Saturdays have a green background with an extra **Wk Hrs** column after them.
- Cells are color-coded based on the legend above.
- Hover over a cell to see a tooltip.

### Edit a Cell

*(Requires create permission. Without it, you can view the grid but not edit.)*

1. Click any cell. An inline editor opens.
2. The editor shows:
   - Employee name, date, and close button.
   - **Schedule** — Shift type, time in/out, campaign, role.
   - **Biometric** — Bio in/out times (yellow if unverified, green if verified).
   - **Status** — Dropdown to change the attendance status.
   - **Time In** / **Time Out** — Time inputs (hidden for NCNS/advised absence).
   - **Hours Worked** — Number input. Auto-fills time in/out from schedule when entered.
   - **Overtime Approval** — Checkbox if overtime is detected.
   - **Undertime Approval** (amber, for undertime over 30 min) — Set Home toggle, Generate Points / Lunch Used buttons.
   - **Notes** — Reason or explanation textarea.
   - **Suspicious Pattern** — Expandable amber warning if detected.
   - **Violations** — Expandable red card listing detected violations.
3. Click **Create** or **Save** to apply changes. Click **Cancel** to close.
4. *If a cell is leave-linked, a note says "Edit the leave request directly."*

### Week Hours

- After each Saturday column, a **Wk Hrs** cell shows the employee's total hours for that week.
- Click **Calc** to calculate. Click **Recalc** to update. Click **X** to remove.

### Live Presence

- The toolbar shows how many people are currently viewing the same spreadsheet (green pulsing dot with avatar icons).
- Cells being edited by others have a colored shadow ring.

### Points Column

- Click the **Pts** value to recalculate GBRO (if you have manage points permission). A loading spinner appears during processing.
- Points appear in **red** if 1 or more, **amber** if greater than 0.

---

## Manual Attendance (Create)

*resources/js/pages/Attendance/Main/Create.tsx*

[Insert Screenshot: 'Manual Attendance' Form]

Create attendance records manually, one at a time or in bulk.

### Create Multiple Records Toggle

- Turn on **Create Multiple Records** to stay on this page after saving (for entering several records in a row).
- The session counter shows **"Records created this session: N"**.

### Single Entry Mode

1. **Employee** — Click the search field, type a name, and select an employee. Their schedule info appears below.
2. **Shift Date** — Pick the date. *Required. If left blank, the system shows an error.*
3. **Status** — The system suggests a status (shown in a green or amber box). You can override it from the dropdown. Options: On Time, Tardy, Half Day Absence, Advised Absence, NCNS (No Call No Show), Undertime (<1hr), Undertime (>1hr), Failed Bio In, Failed Bio Out, Present (No Bio), Non-Work Day, On Leave.
   - *If you override the suggested status, a **Use Suggested** button appears to revert.*
   - *If you leave the status blank, the system shows an error.*
4. **Time In** — Date and time. Auto-filled from the shift. *If the time is invalid, the system shows an error.*
5. **Time Out** — Date and time. Auto-filled for night shifts (next day). *If hour 24 on the same date, the system rejects it.*
6. **Set Home** (for undertime over 30 min) — Toggle on if the employee was sent home early.
7. **Undertime Approval** (if Set Home is off) — Choose **Generate Points**, **Lunch Used**, or **Clear**.
   - *This section only appears if you have approve undertime permission.*
8. **Notes** — Type a reason (500 characters max).
9. Click **Create Attendance Record** to save.

### Bulk Entry Mode

1. Click **Bulk Entry** to switch.
2. **Campaign** — Quick-select a campaign.
3. **Shift Schedule** — Quick-select a shift type.
4. Use **Select All** or **Clear All** buttons, or select individual employees.
5. Fill in the **Shift Date**, **Status**, **Time In**, **Time Out**, and **Notes** — these apply to all selected employees.
6. Click **Create N Records** to save. *The system checks all fields. Anything missing shows an error.*

---

## Import Biometric

*resources/js/pages/Attendance/Main/Import.tsx*

[Insert Screenshot: 'Import Biometric' Screen]

Upload biometric TXT files from fingerprint scanners with a preview step before final import.

### Upload Files

1. Click **Biometric Files (.TXT)** to select one or more TXT files from your computer.
2. Each selected file shows its name, size, and a **Site** dropdown. *Assign a biometric site to each file. If you skip this, the system shows an error.*
3. Click **X** next to a file to remove it.
4. **Date Range** — Pick the **From** and **To** dates. *Required. If left blank, the system shows an error.*
5. **Notes** — Optional.
6. Click **Preview & Upload N file(s)** to analyze the files.

### Preview Dialog

A dialog opens showing:

- Per-file stats: **Total Records**, **Within Range**, **Outside Range**.
- **Outside Range** warning (orange) — Lists dates outside the selected range.
- **Duplicates** warning — Lists existing records that match (first 5 shown).
- **Import ALL records** checkbox — Check this to ignore the date range filter and import everything.

Click **Confirm & Upload** to proceed. Click **Cancel** to go back.

### How It Works

The right panel explains:
- The system detects shifts automatically based on time-in.
- Attendance status rules are applied.
- Morning, afternoon, night, and graveyard shifts are supported.

### Recent Uploads

Below the form, a table shows recent uploads with their status: **Completed** (green), **Processing** (blue), or **Failed** (red). Click a failed upload to see the error message.

---

## Review Flagged Records

*resources/js/pages/Attendance/Main/Review.tsx*

[Insert Screenshot: 'Review Flagged Records' Screen]

Review, verify, and manage attendance records that need attention.

### Status Summary

A collapsible card at the top shows:
- Counts by status (On Time, Tardy, Half Day, NCNS, etc.).
- Counts by verification status: **Pending** (yellow), **Verified** (green), **Partially Verified** (amber).

### Filters

1. **Employee** — Search and select employees.
2. **Status** — Pick one or more statuses.
3. **Site** — Select a site.
4. **Campaign** — Select one or more campaigns.
5. **Verification Status** — Choose **All**, **Pending**, **Partially Verified**, or **Verified**.
6. **Leave Conflicts** — Choose **All** or **Leave Conflicts Only**.
7. **Date From** / **Date To** — Pick a date range.
8. Click **Search** to apply. Click **Clear Filters** to reset.

### Action Bar

- Record count: "Showing X of Y records needing verification."
- **Partial Approve N Records** (amber) — For records missing only the time-out.
- **Verify N Records** — Appears when records are selected.
- **Back to Attendance** — Returns to the main records page.

### Verify a Record (Single)

1. Click the **Verify/Edit** button on any row. A dialog opens.
2. **Leave Warning** (amber, shown if leave conflicts exist).
3. **Current Information** — Scheduled times, site, grace period, bio sites, notes.
4. **Detected Violations** (red) — Lists violations with point values.
5. **Status** — The system suggests a status. You can override from the dropdown.
6. Click **Fill Time In & Out from Schedule** to auto-fill.
7. **Time In** and **Time Out** — Date and time inputs.
8. **Overtime Approval** (blue, shown if OT &gt; 30 min).
9. **Set Home & Undertime** (amber, for undertime over 30 min).
   - *Admin/HR: Click **Generate Points** or **Lunch Used**, then Approve/Reject.*
   - *Team Lead: Click **Generate Points** or **Lunch Used**, then **Request Approval**.*
10. **Notes** textarea (500 char max).
11. **Verification Notes** — Type notes. Click any quick-phrase button: **Verified**, **Corrected**, **Manual entry**, etc.
12. Click **Verify & Save** (or **Edit & Save**). For on-leave employees with biometrics, click **Flag as Reported & Save** (orange).

### Batch Verify

1. Select multiple records using checkboxes. *Only records with the same primary and secondary status can be selected together.*
2. Click **Verify N Records**. A dialog opens.
3. **Common Status** — Pick a status to apply to all selected records.
4. **Approve Overtime for All** — Check if applicable.
5. **Mark as "Set Home" for All** — Toggle for undertime.
6. **Verification Notes** — Type notes. Quick-phrase buttons available.
7. Click **Verify N Records** to save.

### Warnings

- If a row has a **Warnings** button (amber), click it to see a **Suspicious Pattern Detected** dialog with detected issues and a recommendation. Click **Verify Record** to proceed to the verification dialog.

### Partial Approval

- Click **Partial Approve** to approve the time-in only (time-out pending). The record will show a "Partially Verified" badge.

---

## Biometric Records

*resources/js/pages/Attendance/BiometricRecords/Index.tsx*

[Insert Screenshot: 'Biometric Records' Screen]

View and manage raw biometric fingerprint scan records.

### Statistics Cards

Four cards at the top: **Total Records**, **This Month** (with today/this-week counts), **Date Range** (oldest-to-newest), **Auto Cleanup** (eligible cleanup count and next date).

### Filters

1. **Search** — Type an employee name and press Enter.
2. **Employee** — Click to open a searchable list. Select one or more.
3. **Site** — Select a site from the dropdown.
4. **From** and **To** — Pick a date range.
5. Click **Apply Filters**. Click **Clear Filters** to reset.

### Records Table

- Columns: **Date & Time**, **Employee**, **Device Name**, **Site**, **Upload Date**, **Actions**.
- Click **View All** (eye icon) on any row to see all scans for that employee on that date.

### Biometric Record Detail

*resources/js/pages/Attendance/BiometricRecords/Show.tsx*

[Insert Screenshot: 'Biometric Record Detail' Screen]

- Shows the employee name and date.
- Summary cards: **Total Scans**, **First Scan** (likely time-in), **Last Scan** (likely time-out).
- Scans are grouped by upload batch, with a table showing each scan's timestamp, time badge, device, and site.
- A **Timeline View** shows each scan as a dot on a vertical line (green = first, red = last, blue = middle).

---

## Anomaly Detection

*resources/js/pages/Attendance/BiometricRecords/Anomalies.tsx*

[Insert Screenshot: 'Anomaly Detection' Screen]

Detect unusual patterns in biometric scan records.

### Detection Parameters

1. **From** and **To** — Pick a date range. *Required. If left blank, the Run Detection button will show an error.*
2. **Minimum Severity** — Choose **Low and above**, **Medium and above**, or **High only**.
3. **Anomaly Types** — Check one or more:
   - **Simultaneous Sites** — Scans at different sites within 30 minutes.
   - **Impossible Time Gaps** — Time going backwards.
   - **Duplicate Scans** — Multiple scans in the same minute.
   - **Unusual Hours** — Scans between 2–5 AM.
   - **Excessive Scans** — More than 6 scans per day. *At least one type must be selected. If none are checked, the system shows an error.*
4. **Auto-flag High Severity Anomalies** — Check this to mark flagged records for admin review.
5. Click **Run Detection**.

### Results

- Four filter cards: **Total Anomalies**, **High Severity** (red), **Medium Severity** (yellow), **Low Severity** (blue). Click any to filter the list.
- Each anomaly row shows **Severity** (colored badge), **Employee**, **Anomaly Type**, **Description**, and **Records** count.
- Click the expand arrow on a row to see the related biometric scans and details.
- Click **Review Flagged Attendance** to go to the Review page.

---

## Export Records

*resources/js/pages/Attendance/BiometricRecords/Export.tsx*

[Insert Screenshot: 'Export Records' Screen]

Export attendance records and attendance points to Excel.

### Export Attendance Records

1. Info alert explains what is included: employee details, campaign, shift date, times, status, tardy/undertime/overtime minutes, verification status, notes.
2. **Date Range** (optional) — Pick From and To dates.
3. **Filters** (optional) — Click **Campaign**, **Employee**, or **Site** to open searchable multi-select popovers.
4. Selected filters appear as badges. Click **Clear filters** to reset.
5. Click **Export to Excel**. A progress bar shows the export status. The file downloads automatically as .xlsx. *(Requires export permission.)*

### Export Attendance Points

1. Info alert explains what is included: employee details, shift date, point type, value, status, violation details, expiration date, GBRO eligibility.
2. **Date Range** (optional) and **Filters** (Employee, Point Type, Status).
3. Click **Export Points to Excel**. Progress bar and auto-download.

---

## Reprocess Attendance

*resources/js/pages/Attendance/BiometricRecords/Reprocessing.tsx*

[Insert Screenshot: 'Reprocess Attendance' Screen]

Recalculate attendance records from biometric data using the latest algorithm.

### Important Notes

- Existing records can be deleted and recreated.
- Admin-verified and approved records are preserved.

### Steps

1. **Start Date** and **End Date** — Pick the range to reprocess. *Required.*
2. **Campaign** — Select one or more campaigns (optional).
3. **Employee** — Select specific employees (optional, loaded based on date range and campaigns).
4. **Delete existing attendance records before reprocessing** — Check this if desired.
5. **Automatically rescan attendance points after reprocessing** — Check this if desired.
6. Click **Preview** to see what would be affected (a dialog opens with total records, affected employees, and dates). *(Requires preview permission.)*
7. Click **Reprocess** to start. A confirmation dialog asks: *"Are you sure you want to reprocess attendance for this date range? This action cannot be undone."* Click **Proceed** to confirm. *(Requires reprocess permission.)*

### Results

- Shows **Successfully Processed** (green) and **Failed** (red) counts.
- Table with per-employee details.
- Error alert if any records failed.

### Fix Statuses

Click **Fix Statuses** to correct attendance statuses based on existing time in/out records. A confirmation dialog appears before processing.

---

## Retention Policies

*resources/js/pages/Attendance/BiometricRecords/RetentionPolicies.tsx*

[Insert Screenshot: 'Retention Policies' Screen]

Configure rules for how long attendance records and points are kept.

### How It Works

- Higher priority policies override lower ones.
- Site-specific policies override global ones.
- "All Records" acts as a fallback.

### Add a Policy

Click **Add Policy** (requires retention permission). A dialog opens:

1. **Policy Name** — Type a name. *Required.*
2. **Description** — Optional.
3. **Record Type** — Choose **All Records**, **Biometric Records Only**, or **Attendance Points Only**.
4. **Retention Period** — Type the number of months. *Required.*
5. **Applies To** — Choose **Global** or a specific **Site**.
6. **Priority** — Type a number (higher numbers override lower ones).
7. **Active** — Toggle on or off.
8. Click **Create** to save.

### Manage Policies

The policies table shows: **Name**, **Record Type** (colored badge), **Retention Period**, **Applies To**, **Priority**, **Status** (toggle switch), and **Actions**.

- Toggle the switch to activate or deactivate a policy.
- Click the **eye** icon to preview what records would be deleted (shows cutoff date and count).
- Click the **pencil** icon to edit. *(Requires retention permission.)*
- Click the **trash** icon to delete. A confirmation dialog appears. *(Requires retention permission.)*

### Records Statistics by Age

If available, two cards show the age breakdown of biometric records and attendance points (e.g., 0–3 months, 3–6 months, etc.).

---

## Employee Schedules

*resources/js/pages/Attendance/EmployeeSchedules/*

### Schedule List

*resources/js/pages/Attendance/EmployeeSchedules/Index.tsx*

[Insert Screenshot: 'Employee Schedules' Screen]

#### Filters

1. **Employee** — Search and select an employee.
2. **Role** — Select a role.
3. **Campaign** — Select a campaign.
4. **Status** — Choose **All**, **Active**, or **Inactive**.
5. **Active Only** — Toggle to show only active schedules.
6. **Show Resigned** — Toggle to include resigned employees.
7. Click **Apply Filters** or **Clear Filters**.

#### Actions

- Click **Add Employee Schedule** to create a new schedule. *(Requires create permission.)*
- Click **Employees No Schedules** to see a dialog listing:
  - **No Schedule** tab — Employees without schedules. Click **Add Schedule** to create one.
  - **Inactive** tab — Employees with inactive schedules. Click **View Schedules** or **Add New**.
  - **Multiple Schedules** tab — Employees with multiple schedules. Click **View All** or **Add New**.

#### Schedule Table

Columns: **Employee**, **Campaign**, **Site**, **Shift Type** (colored badge), **Time IN/OUT**, **Work Days**, **Status** (toggle switch + Active/Inactive badge), **Actions**.

- Toggle the switch to activate or deactivate a schedule. *Activating will deactivate other schedules for that employee. A confirmation dialog warns about this.*
- Click **Edit** (pencil) to modify the schedule.
- Click **Delete** (trash) to remove. A confirmation dialog appears: *"This action cannot be undone."*

### Create / Edit Schedule

*resources/js/pages/Attendance/EmployeeSchedules/Create.tsx*  
*resources/js/pages/Attendance/EmployeeSchedules/Edit.tsx*

[Insert Screenshot: 'Create Employee Schedule' Form]

1. **Employee** — Search and select. *(For first-time setup, this may be auto-filled.)*
2. **Campaign** — Select a campaign.
3. **Site** — Select a site.
4. **Shift Type** — Choose **Morning**, **Afternoon**, **Night**, **Graveyard**, or **Utility 24h**. The system auto-fills the default time in/out. *If the times you enter don't match the recommended range, an amber notice appears.*
5. **Time In** and **Time Out** — Set the times. *Required.*
6. **Work Days** — Check the boxes for Monday through Sunday. *At least one must be selected.*
7. **Grace Period** — Type the grace period in minutes (0–60). *Default is 15.*
8. **Effective Date** — Pick the start date. *Required.*
9. **End Date** — Optional.
10. Click **Create Schedule** (or **Update Schedule**) to save.

*For Team Leads: A **Managed Campaigns** section appears instead of Campaign. Check the campaigns you manage.*

*During first-time setup, a confirmation dialog reviews all entered info before final submission.*

---

## Attendance Points

*resources/js/pages/Attendance/Points/Index.tsx*

[Insert Screenshot: 'Attendance Points' Screen]

Manage attendance violation points for employees.

### Statistics Cards

Seven cards: **Active Points**, **Whole Day Absence**, **Half-Day Absence**, **Tardy**, **Undertime (Hour)**, **Undertime (>Hour)**, **High Risk Employees** (clickable).

- Click **High Risk Employees** to see a dialog listing employees with 6+ points. Click any name to drill into their individual points.

### Action Toolbar

- **Export** — Export points to Excel (progress bar with download).
- **Rescan Points** — Open a dialog with date range pickers. Click **Rescan** to recalculate.
- **Bulk Add** — Navigate to the Bulk Add page. *(Requires create permission.)*
- **Add Manual Entry** — Open a form dialog to add a single point manually. *(Requires create permission.)*
- **Manage** dropdown (IT/Super Admin only):
  - **How to Use** — Guide dialog explaining management actions.
  - **View Statistics** — Dialog showing missing points, duplicates, pending expirations, expired points, GBRO date anomalies with Quick Action buttons.
  - **Regenerate Points** — Optional date range and employee picker.
  - **Remove Duplicates** — Automatically deduplicates.
  - **Expire All Pending** — Choose SRO, GBRO, or Both. Select employees.
  - **Recalculate GBRO Dates** — All employees or specific ones.
  - **View Anomaly Logs** — Navigate to the anomaly logs page.

### Filters

1. **Employee** — Search and select.
2. **Campaign** — Select a campaign.
3. **Point Type** — Select a type.
4. **Status** — Choose **Active**, **Excused**, or **Expired**.
5. **Expiring within 30 days** — Check to filter.
6. **GBRO Eligible Only** — Check to filter.
7. Click **Apply Filters** or **Clear Filters**.

### Points Table

Columns: **Employee**, **Date**, **Type** (colored badge), **Points**, **Status** (Active/Excused/Expired + Manual badge), **Violation Details** (click to open full info dialog), **Expires** (dates + days remaining), **Actions**.

**Row Actions:**
- **View** (eye) — Navigate to the employee's points detail page.
- **Edit** (pencil) — Edit a manual point entry. *(Requires edit permission.)*
- **Delete** (trash) — Delete a manual point. A confirmation dialog appears. *(Requires delete permission.)*
- **Excuse** (checkmark) — Open a dialog to excuse the point. Type the reason (required) and optional notes. *(Requires excuse permission.)*
- **Remove Excuse** (X) — Unexcuse the point. A confirmation dialog appears.

### Rescan Dialog

1. **From Date** and **To Date** — Pick the range.
2. Click **Rescan** to recalculate.

### Add Manual Entry Dialog

1. **Employee** — Search and select. *Required.*
2. **Violation Date** — Pick the date. *Required.*
3. **Violation Type** — Choose from: Whole Day Absence (1.0 pt), Half-Day Absence (0.5 pt), Tardy (0.25 pt), Undertime ≤60 min (0.25 pt), Undertime 61+ min (0.50 pt). *Required.*
4. **Advised absence** — Check if applicable (for Whole Day only).
5. **Tardy Minutes** / **Undertime Minutes** — Input as needed.
6. **Violation Details** and **Notes** — Optional text.
7. A preview card shows the calculated point value.
8. Click **Create Point** to save.

### Excuse Dialog

1. Review the point summary (employee, date, type, points).
2. **Excuse Reason** — Type the reason. *Required.*
3. **Notes** — Optional.
4. Click **Excuse Point** to save.

---

## Bulk Add Points

*resources/js/pages/Attendance/Points/BulkCreate.tsx*

[Insert Screenshot: 'Bulk Add Attendance Points' Screen]

Add violation points to multiple employees at once.

### Left Panel (Settings)

1. **Violation Details** — Pick a **Date** and **Violation Type**. These apply as defaults.
2. **Advised absence** — Check if applicable.
3. Click **Re-apply to all rows** to push the current settings to every row.
4. **Add Employees** — Search and select employees to add them directly.
5. Click **Add blank row** to add an empty row.

### Right Panel (Entry List)

- Each row shows: employee name, date, type, advised status.
- Click the expand arrow to show extra fields: **Tardy Minutes**, **Undertime Minutes**, **Violation Details**, **Notes**.
- Click **Remove** (X) to delete a row.

### Submit

- The summary shows employee count and total points.
- Click **Submit**. A confirmation dialog lists all entries. Click **Confirm** to save. *If any employee has a duplicate entry for the same date, the system warns about replacements.*

---

## Employee Points Detail

*resources/js/pages/Attendance/Points/Show.tsx*

[Insert Screenshot: 'Employee Points Detail' Screen]

View one employee's full attendance points breakdown.

### Summary Cards

- **Total Active Points**, **Whole Day Absence**, **Half-Day Absence**, **Tardy & Undertime**.

### GBRO Status Card

- Shows **Days Without Violation**, **Days Until GBRO Eligibility**, **Eligible Points for Deduction**.
- Green banner if GBRO-ready. Blue info banner with countdown if not yet eligible.

### Point History Table

Columns: **Date** (with NCNS/GBRO badges), **Type** badge, **Points**, **Status** (Active/Excused/Expired), **Violation Details** (click to open full info), **Expiration** (SRO/GBRO dates + days remaining), **Actions** (Excuse/Remove Excuse).

### Manage Dropdown (Admin/IT/Super Admin only)

- **How to Use** — Guide dialog.
- **Recalculate GBRO Dates** — For this employee.
- **Expire All Pending Points** — Choose SRO, GBRO, or Both.
- **Reset Expired Points** — Reactivates expired points.
- **View Anomaly Logs** — Navigate to anomaly logs for this employee.

### Violation Details Dialog

Opens when you click **View Details** on any point. Shows:
- Employee name, date, type badge, points.
- Violation details, notes, tardy/undertime durations.
- Expiration info.
- GBRO eligibility section (green/blue/orange cards).
- Excuse info (if excused).

---

## Streak Leaderboard

*resources/js/pages/Attendance/Points/Leaderboard.tsx*

[Insert Screenshot: 'Streak Leaderboard' Screen]

Ranking of employees with the longest tardy-free streaks.

### View Standings

1. **Limit** — Choose **Top 10**, **Top 25**, or **Top 50**.
2. The table shows: **Rank** (1–3 with medal icons), **Employee**, **Campaign**, **Current Streak** (days with flame icon), **Longest Streak**, **Badge** (colored tier), **Actions** (View link).
3. Click **View** to see an employee's streak detail page.

### Badge Tiers

- **Starter** — 7 days
- **Bronze** — 30 days
- **Silver** — 90 days
- **Gold** — 180 days
- **Platinum** — 365 days

### Exclude Employees (Admin only)

- The **Excluded Employees** card lists employees who have been removed from the leaderboard.
- Click **Exclude Employee** to open a dialog: search for employees, check the boxes, type a reason, and click **Exclude**.
- Click **Restore** on any excluded employee to add them back.

---

## Streak Detail

*resources/js/pages/Attendance/Points/Streak.tsx*

[Insert Screenshot: 'Streak Detail' Screen]

An individual employee's tardy-free streak.

- **Current Streak** — Days and start date.
- **Longest Streak** — Days and total workdays evaluated.
- **Last Violation** — Date, or "Clean record" if none.
- **Current Badge** — Shows the earned badge with a progress bar to the next tier and days remaining.
- **All Badge Tiers** — Grid of all 5 tiers, each showing earned or unearned status.

---

## Anomaly Logs (GBRO)

*resources/js/pages/Attendance/Points/AnomalyLogs/Index.tsx*

[Insert Screenshot: 'GBRO Anomaly Logs' Screen]

Audit logs for attendance point recalculation anomalies.

### Actions

- **Dry-run audit** — Detects anomalies without fixing them.
- **Run audit & repair** — Detects and auto-fixes anomalies.

### Tabs

- **All** — Total count.
- **Pending** — Unrepaired count (amber).
- **Repaired** — Repaired count (green).

### Filters

- **Type**, **Trigger**, **User ID**, **Batch ID**.
- Click **Apply** or **Reset**.

### Table

Columns: **When** (datetime), **Type** (colored tooltip badge), **Trigger**, **Employee**, **Point** (clickable link to the employee's points page), **Expected**, **Actual**, **Status** (Repaired/Pending), **Batch ID**.

### Clear Logs

- **Clear repaired** — Removes all repaired logs.
- **Clear all** — Removes all logs. Both require confirmation before deleting.

---

## Tools Hub

*resources/js/pages/Attendance/Tools/Index.tsx*

[Insert Screenshot: 'Attendance Tools' Screen]

A navigation hub for biometric and attendance tools.

Click any card to navigate:

| Card | What It Does | Permission Required |
|---|---|---|
| **Recent Uploads** | View and track biometric file uploads | `biometric.view` |
| **Export Records** | Export data to Excel | `biometric.export` |
| **Reprocess Attendance** | Recalculate attendance from biometrics | `biometric.reprocess` |
| **Anomaly Detection** | Detect unusual scan patterns | `biometric.anomalies` |
| **Retention Policies** | Configure data retention rules | `biometric.retention` |

---

## Uploads

*resources/js/pages/Attendance/Uploads/*

### Recent Uploads List

*resources/js/pages/Attendance/Uploads/Index.tsx*

[Insert Screenshot: 'Uploads' Screen]

#### Filters

1. **Search** — Type a filename.
2. **Status** — Choose **All**, **Pending**, **Processing**, **Completed**, or **Failed**.
3. **From** and **To** — Pick a date range.
4. Click **Filter** or **Reset**.

#### Table

Columns: **File** (filename), **Date Range**, **Site** badge, **Records**, **Matched** (green), **Unmatched** (red if >0), **Status** badge, **Uploaded By**, **Date**, **Actions** (View link).

Click **View** to see the upload detail.

### Upload Detail

*resources/js/pages/Attendance/Uploads/Show.tsx*

[Insert Screenshot: 'Upload Detail' Screen]

- **Error alert** (red) — Shown if the upload failed.
- **Summary** — Total Records, Matched (with %), Unmatched (with attention label), Status badge.
- **File Information** — Biometric Site, Date Range, Uploaded By, Date, Notes.
- **Date Validation Warnings** (orange) — Lists unexpected dates found in the file.
- **Unmatched Employee Names** (red) — Grid of employee names from the biometric file that were not matched in the system.
- **Success card** (green) — "Upload Completed Successfully" with all-matched confirmation.
