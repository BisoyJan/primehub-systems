# Dashboard

*resources/js/pages/Dashboard*

The Dashboard is your main landing screen after logging in. What you see changes based on your role. The sections below are organized by role so you can quickly find what applies to you.

[Insert Screenshot: 'Dashboard' Full Screen Layout]

---

## What Agents See

### Tabs

You have access to four tabs: **My Dashboard**, **Attendance**, **Presence Insights**, and **Coaching**.

### Sidebar Widgets

You see two widgets: **Notifications** and **Coaching Follow-ups**.

### My Dashboard Tab

[Insert Screenshot: 'My Dashboard' Tab - Agent View]

**My Schedule Card**
- Displays your **Shift Type**, **Grace Period**, **Time-In / Time-Out**, **Work Days**, **Campaign**, and **Site**.
- A list of your next 7 work days appears below.
- *This is for viewing only. If no schedule is assigned, the card says "No active schedule found."*

**Attendance Summary Card**
- Shows your counts for **Present**, **On Time**, **Tardy**, **Absent**, **NCNS** as icon tiles in the top row, and **Half Day** and **On Leave** as a second row of tiles below.
- A **donut chart** (colored ring) displays your attendance status breakdown.
- A **points gauge** shows your current attendance points out of the threshold (default is 6). The status reads **Good standing** (green), **Moderate risk** (yellow), or **Approaching threshold** (red).
- A **bar chart** breaks down points by type (Full Day Absence, Half Day Absence, Tardy, Undertime, etc.).
- **Upcoming expirations** list shows point type, value, and expiration date.
- *This is for viewing only.*

**Recent Requests Card**
- Three sub-tabs with badge counts:
  - **Leaves** — Shows up to 5 recent leave requests with type, date range, days, and status. *If there are no requests, the tab reads "No leave requests."*
  - **IT Concerns** — Shows up to 5 recent IT issues with category, description, priority, and status. *If there are none, it reads "No IT concerns."*
  - **Medication** — Shows up to 5 recent medication requests with name, type, and status. *If there are none, it reads "No medication requests."*

**Leave Credits Card**
- If you are not yet eligible, the card shows your eligibility date and a note about "6 months after hire date."
- If eligible, displays your **Balance** (large number), **Monthly Rate**, **Total Earned**, **Total Used**, and a usage bar with percentage.
- *This is for viewing only.*

### Attendance Tab

[Insert Screenshot: 'Attendance' Tab - Agent/Team Lead View]

**Filters Bar**
1. **Campaign** — Select a campaign from the dropdown. (Hidden for some roles.)
2. **Verification** — Choose **All Records**, **Verified Only**, or **Non-Verified**.
3. **Start Date** and **End Date** — Click the date fields to pick a range.
4. Click **Apply** to reload the data.

**Statistics Cards**
Eight cards show: **Total Records**, **On Time** (green), **Time Adjustment** (purple, with OT/UT breakdown), **Tardy** (yellow), **Half Day** (orange), **NCNS** (red), **Advised/On Leave** (blue), **Needs Verification** (purple). Each shows a count and percentage.

**My Leave Credits Card**
- Shows **Rate/Month**, **Earned** (green), **Used** (orange), and **Balance**.
- Click **Request Leave** to go to the leave request form.

**Charts Section**
Three charts in a row:
1. **Donut chart** — Status distribution with total in center.
2. **Horizontal bar chart** — Count by status.
3. **Radial gauge** (carousel) — Click the left/right arrows to cycle through per-status percentages.

**Monthly Attendance Trends**
- Area chart showing trends. Click left/right arrows to switch views: **All Status**, **On Time**, **Time Adjustment**, **Tardy**, **Half Day**, **NCNS**, **Advised**.
- Use the **Month** dropdown to zoom into a specific month.
- Summary at bottom shows latest month's total, on-time, tardy, and absence counts.

### Coaching Tab — Agent View

[Insert Screenshot: 'Coaching' Tab - Agent View]

- **Status Banner** — Large coaching status badge with icon (Coaching Done, Needs Coaching, Badly Needs, Coach ASAP, or No Record). Shows total sessions and sessions this month.
- Three cards: **Sessions This Month**, **Pending Acknowledgements** (warning if >0), **Pending Reviews** (warning if >0).
- **Upcoming Follow-ups** — List of sessions with purpose, team lead name, and date. Click any entry to navigate to that session.
- **Pending Acknowledgements** alert (yellow border) — "You have N pending acknowledgements." Click **View** to review.
- **Quick Links** — **Coaching Dashboard** and **My Sessions** buttons.

### Notifications Widget (Sidebar)

- Shows latest notifications with title, message, and time (e.g., "5m ago").
- A red badge shows your unread count.
- Click **View All** to go to the Notifications page.

### Coaching Follow-ups Widget (Sidebar)

Same as the **Coaching Follow-ups Widget** described in the Team Lead section below.

### Presence Insights Tab — Agent View

Agents see the basic **Presence Overview**, **Leave Calendar**, and **Attendance Points Overview** sections. The campaign-level sections (Campaign Presence Comparison, Points by Campaign) and the Leave Conflicts Alert are **not** shown to Agents.

---

## What Team Leads See

### Tabs

You have access to three tabs: **Attendance**, **Presence Insights**, and **Coaching**. *(There is no "My Dashboard" tab for Team Leads.)*

### Sidebar Widgets

You see three widgets: **Notifications**, **Pending Leave Approvals**, and **Coaching Follow-ups**.

### My Dashboard Tab

*Not available to Team Leads.*

### Attendance Tab

Same base content as the Agent view (filters, statistics cards, charts, Monthly Attendance Trends). **You also see** the **Leave Conflicts Alert** described in the Admin section below (whenever any conflicts exist).

### Presence Insights Tab

[Insert Screenshot: 'Presence Insights' Tab - Team Lead View]

**Presence Overview**
- Five cards: **Total Scheduled**, **Present** (green), **Absent** (red), **On Leave** (warning), **Unaccounted** (warning if >0).
- Use the **Date** picker or click **Today** to change the view date.
- Only the **Present** and **Absent** cards open a detail dialog when clicked. **Total Scheduled**, **On Leave**, and **Unaccounted** are informational only.

**Leave Calendar**
- Navigate months using left/right arrows. Click **Today** to return to current month.
- Calendar colors days **amber** if employees are on leave. A **red dot** appears if multiple employees are on leave the same day. A ring highlights today.
- Right side: scrollable employee list with avatar, leave type, campaign, date range.
- Click an employee entry to open a detail dialog (avatar, name, campaign, leave type, duration, date range, reason).
- Click **Full Calendar** to go to the full leave calendar page.

**Attendance Points Overview**
- Four clickable cards: **Total Active Points** (warning), **Total Violations**, **High-Risk Employees** (danger if >0), **Points Trend** (icon).
- Preview shows up to 4 high-risk employee cards. Click any card or **View All** to open dialogs:

  - **High-Risk Employees** — List of employees with 6+ points. Click an employee to see individual violation details (type, points, shift date, expiration). Click **View Full History** or **Back to List**.
  - **Points Breakdown** — Distribution by type with points and percentage.
  - **Points Trend** — Bar chart of monthly points over 6 months.

### Coaching Tab — Team Lead View

[Insert Screenshot: 'Coaching' Tab - Team Lead View]

- **TL Personal Status Banner** — Shows your own coaching status as a coachee.
- **Agent Status Cards** — Up to 6 cards showing counts for each coaching status category with percentage.
- **Not Coached This Week** — List with name, campaign, coaching status badge. Click any entry to create a new coaching session.
- **Coached This Week** — List with name, campaign, coaching status badge, last coached date.
- **Coaching Summary** — Total agents, sessions this month, pending acks, pending reviews, urgency alert.
- **Quick Links** — **Coaching Dashboard** and **All Sessions** buttons.

### Notifications Widget

Same as Agent view (see above).

### Pending Leave Approvals Widget

- Shows upcoming leave requests needing approval. Each entry shows employee name, leave type, date range, countdown.
- Entries due today/tomorrow appear in **red**; within 3 days in **orange**; further out in **yellow**.
- Click any request to go to the leave detail page.
- Click **View All Pending** to go to the pending list.

### Coaching Follow-ups Widget

- Upcoming follow-up sessions with agent name, purpose, date, countdown.
- Lists agents not coached this week with status badge.
- Click a follow-up to go to that session. Click an uncoached agent to create a new session.
- Click **View All Sessions** to go to the full list.

---

## What HR Sees

### Tabs

You have access to three tabs: **Attendance** (with Enhanced Analytics), **Presence Insights**, and **Coaching**. *(No "My Dashboard" tab.)*

### Sidebar Widgets

You see four widgets: **Notifications**, **Pending Leave Approvals**, **Coaching Follow-ups**, and **Biometric Anomaly**.

### My Dashboard Tab

*Not available to HR.*

### Attendance Tab (with Enhanced Analytics)

Same base content as Team Lead view (filters, stats, charts, trends, Leave Conflicts Alert). **You also see the following:**

**Enhanced Analytics section:**
- **Attendance Compliance Rate** — Large percentage colored green (≥80%), yellow (≥60%), or red (<60%). Includes progress bar and breakdown counts.
- **Leave Utilization** — Combo chart: bar chart of earned vs. used per month with line overlay for utilization rate.
- **Points Escalation Alert** — Amber-bordered card listing employees with 4.00–5.99 points (near 6-point threshold). Shows name, role, violations, points, and remaining before threshold. Badge shows total at-risk count.
- **NCNS Trend** — Line chart of No Call No Show incidents over 6 months. Badge shows **Increasing** (red), **Decreasing** (green), or **Stable**.

### Presence Insights Tab

Same as Team Lead view (Presence Overview, Leave Calendar, Attendance Points Overview).

*Note: Campaign-level comparison sections are for Admin/Super Admin only and are not visible to HR.*

### Coaching Tab — HR View

Same content and layout as the Team Lead view (see above).

### Sidebar Widgets

- **Notifications** — Same as Agent view.
- **Pending Leave Approvals** — Same as Team Lead view.
- **Coaching Follow-ups** — Same as Team Lead view.
- **Biometric Anomaly** — Shows counts for 5 anomaly types: Simultaneous Sites, Impossible Gaps, Duplicate Scans, Unusual Hours, Excessive Scans. Badge shows total (yellow) or **Clear** (green). Click **View Details** to go to the Attendance Anomalies page.

---

## What Admins & Super Admins See

### Tabs

- **Admins** have access to four tabs: **Attendance** (with Enhanced Analytics), **Presence Insights** (with Campaign sections), **Coaching**, and **Infrastructure**.
- **Super Admins** have access to five tabs: the four above plus **IT Concerns**.
- *Neither role has a "My Dashboard" tab. **IT Concerns** is available only to Super Admin (and IT) — not to Admin.*

### Sidebar Widgets

Both roles see six widgets: **Notifications**, **Pending Leave Approvals**, **Coaching Follow-ups**, **User Account Stats**, **Recent Activity**, and **Biometric Anomaly**.

### My Dashboard Tab

*Not available to Admins or Super Admins.*

### Attendance Tab (with Enhanced Analytics)

Same as HR view (base content + Enhanced Analytics section). See above for details.

**Leave Conflicts Alert**
- Lists employees with biometric activity during approved leave. Each row shows employee name, leave type, worked date, leave range.
- Click **Review** to examine a record. Click **Review All** to go to leave conflict review.
- *"+N more conflicts pending review" appears if more than 5 exist.*

### Presence Insights Tab (with Campaign Sections)

Same as Team Lead/HR view (Presence Overview, Leave Calendar, Attendance Points Overview). **You also see the following:**

**Campaign Presence Comparison**
- Stacked bar chart comparing campaigns. Each bar shows **Present** (green), **Absent** (red), **On Leave** (blue).
- Summary cards per campaign: name, scheduled count, presence rate badge (green ≥80%, secondary ≥60%, red <60%).

**Points by Campaign**
- Bar chart showing points per campaign (orange bars).
- Summary cards per campaign: name, total points, violations, employees with points, high-risk count badge (red).

### Coaching Tab — Admin View

[Insert Screenshot: 'Coaching' Tab - Admin View]

- **TL Coaching Overview** — Grid of Team Lead status counts.
- **Agent Status Cards** — Up to 6 cards with counts per coaching status.
- **Role Filter** — Dropdown to show **All**, **Team Leads**, or **Agents**.
- **Not Coached This Week** — Same as Team Lead view.
- **Coached This Week** — Same as Team Lead view.
- **Coaching Summary** — Same as Team Lead view.
- **TL Coaching Summary** — Same structure but for Team Leads.
- **Quick Links** — **Coaching Dashboard** and **All Sessions** buttons.

### Infrastructure Tab

[Insert Screenshot: 'Infrastructure' Tab]

Six clickable cards:

1. **Total Stations** — Click to open a dialog showing breakdown by site (list with location icon and count).
2. **Available PCs** (green if >0) — Click to open a dialog listing unassigned PC specs (PC number, model, RAM, disk, CPU, and any issue).
3. **Stations Without PCs** (warning) — Click to open a dialog, then click a site to see station numbers that need PCs.
4. **Vacant Stations** — Click to open a dialog, then click a site to see available station numbers.
5. **Dual Monitor Setups** — Click to open a dialog with breakdown by site.
6. **Maintenance Due** (danger if >0) — Click to open a dialog listing stations with overdue maintenance (station, site, due date, days overdue). *If more than 10, "...and N more" appears.*

All dialogs have a **Back** button for drill-down navigation.

### IT Concerns Tab

*Super Admin only. Admins do not see this tab.*

[Insert Screenshot: 'IT Concerns' Tab]

**Stat Cards**
1. **Total Concerns** (green if >0) — Click to open an **IT Concerns by Site** dialog with data table. Columns: IT Concern type (Pending, In Progress, Resolved, Total) per site. Click **View IT Concerns List** to go to the full list.
2. **Pending** (warning if >0) — Click to go to pending IT concerns list.
3. **In Progress** — Click to go to in-progress IT concerns list.
4. **Resolved** (green) — Click to go to resolved IT concerns list.

**IT Concern Trends**
- Area chart. Click left/right arrows to toggle: **All Concerns**, **Pending Trend**, **In-Progress Trend**, **Resolved Trend**.
- Summary at bottom shows latest month's totals.

### Sidebar Widgets

- **Notifications** — Same as Agent view.
- **Pending Leave Approvals** — Same as Team Lead view.
- **Coaching Follow-ups** — Same as Team Lead view.
- **User Account Stats** — Shows total user count, breakdown by role, pending approvals, resigned count, deactivated count. *Informational only.*
- **Recent Activity** — Up to 10 recent activity entries with event type, description, person name, time. Click **View All** to go to Activity Logs.
- **Biometric Anomaly** — Same as HR view.

---

## What IT Sees

### Tabs

You have access to three tabs, in this order: **Infrastructure**, **IT Concerns**, and **Attendance**. *(No "My Dashboard" tab and no "Coaching" tab.)*

### Sidebar Widgets

You see one widget: **Notifications**.

### My Dashboard Tab

*Not available to IT.*

### Attendance Tab

Same base content as Agent/Team Lead view. Because IT is a non-restricted role, the **Leave Conflicts Alert** is also shown when conflicts exist. (No Enhanced Analytics section.)

### Infrastructure Tab

Same as Admin/Super Admin view (see above).

### IT Concerns Tab

Same as Super Admin view (see above).

### Notifications Widget

Same as Agent view (see above).

---

## What Utility Sees

### Tabs

You have access to two tabs: **My Dashboard** and **Attendance**.

### Sidebar Widgets

You see one widget: **Notifications**.

### My Dashboard Tab

Same as Agent view (see above).

### Attendance Tab

Same base content as Agent view — no Enhanced Analytics and no Leave Conflicts Alert (Utility is a restricted role and sees only their own records).

### Notifications Widget

Same as Agent view (see above).
