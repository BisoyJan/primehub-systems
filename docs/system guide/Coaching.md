# Coaching

*resources/js/pages/Coaching/*

The Coaching module manages one-on-one coaching sessions between Team Leads and Agents, tracks compliance, and provides dashboards for monitoring coaching progress.

---

## What Each Role Can Do

| Capability | Agent | Team Lead | Admin |
|---|---|---|---|
| View your own coaching logs | ✓ | ✓ | ✓ |
| View team coaching dashboard | — | ✓ | ✓ |
| Create coaching sessions | — | ✓ | ✓ |
| Edit sessions | — | If permitted | If permitted |
| Delete sessions | — | If permitted | If permitted |
| Acknowledge sessions | ✓ | ✓ | — |
| Review sessions (Verify/Reject) | — | — | ✓ |
| Manage exclusions | — | — | ✓ |
| Configure coaching settings | — | — | ✓ |
| Coach Team Leads directly | — | — | ✓ |
| Export data | — | ✓ | ✓ |

---

## My Coaching Logs (Agent View)

*resources/js/pages/Coaching/MyCoachingLogs/Index.tsx*

[Insert Screenshot: 'My Coaching Logs' Screen]

This is the coaching page for Agents. It shows your personal coaching history.

1. **Pending Acknowledgements** banner (amber) — *"You have X coaching session(s) pending acknowledgement."* Appears if you have unacknowledged sessions.
2. **Summary Cards** — Your coaching status badge, last coached date, previous date, total sessions count, pending ack count.
3. **Coaching Progress** — A yearly progress bar showing X out of 52 sessions with remaining count. *The goal is one session per week.*
4. **Sessions Table** — Columns: **Date**, **Coach**, **Purpose**, **Ack Status** badge, **Severity** badge, **Actions**.
   - Click **View** (eye icon) to see session details.
   - Click **Acknowledge** (green checkmark) on pending sessions to open the acknowledgement dialog.
5. In the **Acknowledge Session** dialog:
   - **Comment** (optional) — Type any notes.
   - **Your Reflection / Response** (optional) — Type your personal response.
   - Click **Acknowledge** to confirm.

*You cannot create, edit, or delete sessions as an Agent.*

---

## Coaching Sessions List

*resources/js/pages/Coaching/Sessions/Index.tsx*

[Insert Screenshot: 'Coaching Sessions' List]

### Tabs

- **Team Leads**: **Team Sessions** | **My Sessions** (with red pending-ack badge) | **Drafts** (with amber badge)
- **Admins**: **All Sessions** | **Needs Review** (with red badge) | **Drafts** (with amber badge)
- **Agents**: No tabs — just a flat session list.

### Agent / Team Lead Summary Panel

Shows your coaching status badge, last coached date, session count, and pending acknowledgements.

### Filters

1. **Agent** — Search and select an agent.
2. **Team Lead** — Search and select a team lead. *(Admin only.)*
3. **Campaign** — Select one or more campaigns. *(Admin and Team Lead only.)*
4. **Coachee Role** — Choose **Agent** or **Team Lead**. *(Admin only.)*
5. **Compliance Status** — Filter by compliance.
6. **Purpose** — Filter by session purpose.
7. **Ack Status** — Filter by acknowledgement status.
8. **Date Range** — Pick **From** and **To** dates, or use presets: **This Week**, **This Month**, **Last 30 Days**, **This Quarter**.
9. Click **Search** to apply. Click **Reset** to clear.

### Status Count Row

Shows a breakdown of sessions by compliance status.

### Sessions Table

Columns: **Date**, **Coachee**, **Coach**, **Purpose**, **Severity** (colored badge), **Ack Status**, **Compliance**, **Actions**.

**Row Actions:**
- **View** (eye icon) — Open session details.
- **Edit** (pencil icon) — Modify the session. *(Requires edit permission.)*
- **Copy** (copy icon) — Clone the session as a new one.
- **Delete** (trash icon) — Remove the session. A confirmation dialog appears: *"This action cannot be undone."* *(Disabled for Verified or Rejected sessions.)*

### Create New Session

Click **New Session** to create a coaching session. *(Requires create permission.)*

---

## Create Coaching Session

*resources/js/pages/Coaching/Sessions/Create.tsx*

[Insert Screenshot: 'Create Coaching Session' Form]

### Mode Toggle (Admin only)

- **Assign TL → Agent** — Default. Select a Team Lead to coach an Agent.
- **Coach a Team Lead** — Direct coaching for Team Leads.

### Bulk Coaching Queue

You can add multiple agents to a queue and create sessions for all of them in sequence.

- Each agent in the queue shows as a chip:
  - **Done** (green checkmark) — Session created.
  - **Draft** (blue save) — Saved as draft.
  - **Active** (filled circle) — Currently working on this one.
  - **Pending** (hollow circle) — Waiting.
- Click the **X** on a chip to remove that agent.
- Click **Cancel queue** to dissolve the entire queue.

### Auto-Save

The form auto-saves a draft 5 seconds after your last change. Status indicators:

- **Idle** — No changes.
- **Saving** (animated cloud) — Saving in progress.
- **Saved** (checkmark + timestamp) — Successfully saved.
- **Error** (warning triangle + message) — Save failed.

### Form Fields

1. **Coaching Session Date** — Pick the date. *Cannot be in the future.*
2. **Coach** — Select the Team Lead. *(Admin only in assign mode.)*
3. **Agent** — Select the Agent being coached. *(Shows coached-this-week and drafted-this-week indicators.)*
4. **Purpose** — Select from the dropdown. *Required.*
5. **Agent Profile** — Check at least one box: **New Hire**, **Tenured**, **Returning**, **Previously Coached Same Issue**. *At least one required.*
6. **Focus Areas** — Check at least one box. *At least one required.*
   - If **Other** is checked, type notes.
7. **Performance Description** — Type a detailed description. *Minimum 10 characters. This is a rich text editor.*
8. **Root Causes** — Check at least one box. *At least one required.*
   - If **Other** is checked, type notes.
9. **Agent Strengths / Wins** — Type notes (rich text).
10. **SMART Action Plan** — Type the action plan. *Minimum 10 characters. Rich text.*
11. **Severity Flag** — Select a severity level (optional).
12. **Follow-up Date** — Pick a follow-up date. *Must be today or later.*
13. **Attachments** — Upload up to 10 images (JPEG, PNG, GIF, WebP, 4MB max each).

### Submit Actions

- **Save as Draft** — Saves and stays on the page.
- **Save Draft & Next** — Saves and moves to the next agent in the queue.
- **Save All as Draft** — Saves all queued agents at once, then redirects to the sessions list.
- **Create Coaching Session** — Submits the session. *(Performs final validation. If any required field is missing, the system shows an error.)*

*If you try to navigate away with unsaved changes, a dialog asks: "Stay" or "Leave anyway."*

---

## View Coaching Session Details

*resources/js/pages/Coaching/Sessions/Show.tsx*

[Insert Screenshot: 'Coaching Session' Detail]

### Draft Banner

If the session is still a draft, an amber banner shows: **"Draft — not submitted yet"** and an **Edit & Submit** button.

### Status Bar

- **Ack Status** badge.
- **Compliance Status** badge.
- **Severity** badge.

### Sections

- **Session Details** — Date, coach, coachee, purpose.
- **Agent Profile** — Checked profile traits.
- **Focus Areas** — Selected focus areas.
- **Performance Description** — Full rich text content.
- **Attachments** — Thumbnail grid. Click any thumbnail to open a lightbox with zoom slider (25%–300%).
- **Root Causes** — Selected root causes.
- **Agent Strengths / Wins** — Rich text content.
- **SMART Action Plan** — Full action plan.
- **Acknowledgement & Compliance** — Shows ack status, reviewer, timestamp, and notes.
- **Coaching History** — Browse past sessions by year (tabs) and month (pills). Click any past session to view it.

### Action Buttons

- **Edit** — Opens the edit form. *(Shown if you have edit permission.)*
- **Print** — Opens a printer-friendly view.
- **Acknowledge Session** (green) — For Agents. Opens a dialog with optional comment and response textareas. Click **Acknowledge** to confirm.
- **Review Session** (blue) — For Admins. Opens a dialog:
  - **Compliance Status** — Choose **Verify** or **Reject**. *Required.*
  - **Notes** (optional) — Type notes. *Required if rejecting.*
  - Click **Submit Review**.

---

## Edit Coaching Session

*resources/js/pages/Coaching/Sessions/Edit.tsx*

[Insert Screenshot: 'Edit Coaching Session' Form]

1. The form is pre-filled with existing session data.
2. Modify any field. *(Same fields and rules as Create.)*
3. **Remove** existing attachments by clicking the **X** on them. Upload new ones as needed.
4. For draft sessions:
   - Click **Save Draft** to keep as draft.
   - Click **Submit Coaching Session** to finalize.
   - *Before submit, the system checks that performance description and SMART action plan are at least 10 characters, and that purpose, date, profile, focus, and root causes are filled in.*
5. For non-draft sessions:
   - Click **Update Coaching Session** to save changes.
6. Click **Cancel** to go back to the details page.

---

## Team Coaching Dashboard (Team Lead)

*resources/js/pages/Coaching/Dashboard/Index.tsx*

[Insert Screenshot: 'Coaching Dashboard' Screen]

### Summary Cards

Six cards showing: total agents, coaching status breakdowns.

### Follow-up Compliance Rate

A card showing the completion rate percentage, completion count, and a progress bar (green ≥ target, amber near target, red below target).

### Tabs

1. **Agent Overview** (with count badge)
2. **Follow-ups** (with overdue count badge)
3. **Recent Sessions**

### Tab: Agent Overview

1. **Filters** — Coaching status dropdown, date range, **Filter** / **Reset** buttons.
2. **Bulk Actions** — When agents are selected:
   - **Coach Selected** — Creates a coaching queue and navigates to the Create page.
3. **Table** — Columns: **Name**, **Account**, **Coaching Status** (colored badge), **Last Coached**, **Total Sessions**, **Trend** (up/down icon), **Pending Acks**, **Exclusion Info**.
   - Click **Coach Agent** button on any row.
   - Sort columns by clicking headers.

### Tab: Follow-ups

- Sub-tabs: **Upcoming**, **Overdue**, **Calendar**.
- Each follow-up shows: follow-up date, agent name, coach name, purpose, session date.
- Urgency labels: **"1d overdue"** (red), **"Today"**, **"Tomorrow"**, **"X days away"**.
- Calendar view highlights dates with follow-ups.

### Tab: Recent Sessions

Table with **Date**, **Coachee**, **Purpose**, **Ack Status**, **Severity**, **Actions** (View icon).

### Export

Click **Export** to download coaching data as a CSV file. A progress indicator shows the export status.

---

## Coaching Compliance Dashboard (Admin)

*resources/js/pages/Coaching/Admin/Index.tsx*

[Insert Screenshot: 'Coaching Compliance Dashboard' Screen]

### Toggle

Switch between **Agent** and **Team Lead** view.

### Summary Cards

Shows total agents (or Team Leads) and status breakdowns.

### Filters

1. **Campaign** — Multi-select.
2. **Team Lead** — Select a team lead. *(Hidden if you are a Team Lead.)*
3. **Coaching Status** — Dropdown.
4. **Coachee Role** — **Agent** or **Team Lead**.
5. **Date Range** — From and To.
6. Click **Filter** or **Reset**.

### Tabs

| Tab | What It Shows |
|---|---|
| **Overview** | Agents grouped by account/campaign with color-coded status rows |
| **Unacknowledged** | Sessions pending agent acknowledgement |
| **For Review** | Sessions pending admin compliance review |
| **At Risk** | Agents flagged as at-risk |
| **Upcoming** | Follow-ups (sub-tabs: Upcoming, Overdue, Calendar) |

### Campaign Coaching Completion Section

- Collapsible section with per-campaign progress bars.
- Each campaign shows: fully-coached count, eligible count, behind-weekly count, at-risk count, expected sessions.
- Health colors: green (on track), amber (needs attention), red (behind).
- Click a campaign row to drill into its details.
- Click **Export** to download campaign completion data.

### Tab: For Review — Bulk Verify

- Select sessions using checkboxes.
- Click **Bulk Verify** to approve selected sessions.
- To review individually, click the **Review** button on a session to open the **Review Session** dialog (Verify/Reject + notes).

### Tab: Upcoming — Calendar

- Month view showing dates with scheduled follow-ups.
- Click a date to see the sessions on that day.

---

## Coaching Exclusions

*resources/js/pages/Coaching/Exclusions/Index.tsx*  
*resources/js/pages/Coaching/Exclusions/History.tsx*

[Insert Screenshot: 'Coaching Exclusions' Screen]

Manage which agents or Team Leads are temporarily excluded from coaching calculations and dashboards.

### Filters

1. **User** — Search by name or email.
2. **Role** — Choose **All**, **Agent**, or **Team Lead**.
3. **Campaign** — Dropdown.
4. **Status** — Choose **All**, **Excluded Only**, or **Included Only**.
5. Click **Reset** to clear.

### Exclusions Table

Columns: **Name + Email**, **Role**, **Campaign**, **Status** (Excluded / Active badge), **Reason**, **Excluded At**, **Expires**, **Actions**.

**Actions:**
- **Exclude** — Opens the exclude dialog for a single user.
- **Restore** — Revokes the exclusion. Opens a confirmation dialog with optional notes.
- **History** (clock icon) — Opens the user's exclusion history page.

### Exclude a User

1. Click **Exclude** on a user row, or select multiple users via checkboxes and click **Bulk Exclude**.
2. In the dialog:
   - **Reason** — Select from the predefined list. *Required.*
   - **Start Date** — Pick the start date.
   - **End Date** — Optional. Use date presets: **This month**, **Next month**, **30 days**, **60 days**, **Forever**.
   - **Notes** — Optional.
3. Click **Exclude** (or **Bulk Exclude X user(s)**).

### Restore a User

1. Click **Restore** on an excluded user.
2. Optionally type notes.
3. Click **Restore** to confirm.

### Exclusion History

Click the **History** icon on any row to view all past exclusion records for that user. Shows reason, status, dates, who excluded/revoked, and notes.

---

## Coaching Status Settings (Admin Only)

*resources/js/pages/Coaching/Settings/Index.tsx*

[Insert Screenshot: 'Coaching Status Settings' Screen]

Configure the day thresholds that determine coaching status labels.

### How It Works

The number of days since an employee's last coaching session determines their status. Each setting defines the maximum days since last coaching for that status. If the days exceed all thresholds, the status becomes **"Please Coach ASAP."**

| Setting Key | Status Label | Color |
|---|---|---|
| `coaching_done_max_days` | **Coaching Done** | Green |
| `needs_coaching_max_days` | **Needs Coaching** | Yellow |
| `badly_needs_coaching_max_days` | **Badly Needs Coaching** | Orange |
| `no_record_days` | **No Record / ASAP** | Red |

### Adjust Thresholds

1. Each setting shows the status label, current value, and default value.
2. Type a new number (1–365) in the **days** input.
3. Click **Save Settings** to apply.
4. Click **Reset to Defaults** to restore factory values.
