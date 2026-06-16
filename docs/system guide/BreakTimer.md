# Break Timer

*resources/js/pages/BreakTimer/*

The Break Timer module lets employees track their break and lunch sessions with a real-time countdown timer. Supervisors can monitor live sessions, manage overages, and generate reports.

---

## Break Timer (Main Page)

*resources/js/pages/BreakTimer/Index.tsx*

[Insert Screenshot: 'Break Timer' Main Screen]

This is the main timer page — a circular countdown clock in the center of the screen with themed animations in the background.

### Before Starting

1. **Station** — Type your workstation number (e.g., ST-01, PC-05). *Required for Agents and Team Leads. If you leave it blank, the system will show an error.*

2. **Theme** — Click the theme dropdown (top-right) to choose a visual background. **14 themes available**: Default, Cozy Cafe, Rainy Window, Sakura, Ocean Tide, Neon City, Golden Hour, Deep Forest, Snowfall, Moonlit, Aurora, Cyberpunk, Synthwave, Desktop Goose.

3. **Alarm Sound** — Click the alarm dropdown to choose what sound plays when time runs out. Options: No Sound, Zen Bell, Corporate, 8-Bit, Beep, Urgent, Chime, Alert, Buzzer.

4. **Fullscreen** — Click the fullscreen toggle to expand the timer to fill your screen.

### Starting a Break

1. The status reads **"Ready"** when no session is active.
2. The info pills show: **Breaks left** (X of Y) and **Lunch** (Available or Used).
3. Click one of the start buttons:

   - **Start Break** — Starts a standard break session.
   - **Start Lunch** — Starts a lunch session.
   - **Break + Lunch** — Click the dropdown arrow to select how many breaks to combine with lunch.
   - **Combine Breaks** — Appears if you have 2+ breaks remaining. Click the dropdown to select how many to combine.

4. A confirmation dialog appears showing the session type, duration, and remaining allowance. Click **Confirm** to start.
   *If you have no breaks remaining, the start buttons will be disabled.*
   *If you already have an active session, the start buttons are hidden.*

### During a Break

- The circular ring animates down as time passes.
- The digital clock shows remaining time in MM:SS or HH:MM:SS format.
- Status label shows: **Break**, **Lunch**, or **Combined**.
- Secondary label shows "of X min."

**Pause:**
1. Click **Pause**. A dialog opens.
2. Select a pause reason from the dropdown, or type your own (depending on policy settings).
3. Click **Pause** to freeze the timer. *If you pause without entering a reason, the system shows an error.*

**Resume:**
- Click **Resume** to continue the timer. The timer resumes immediately from where it stopped.

**End Early:**
1. Click **End**. If time remains, a dialog appears: *"End Break Early?"* showing the remaining time.
2. Click **End Now** to stop early. Click **Cancel** to continue.
3. *If you are over time, the session ends immediately without confirmation.*

### When Time Runs Out

- The status changes to **Overage**.
- The alarm sound plays on repeat.
- A browser notification appears: *"Break Timer Overage! Your break time has ended. Please return to work."*
- The browser tab title flashes: **"⚠️ OVERBREAK!"**
- The overage counter shows how long past the allotted time.

### End of Day

- Click **Reset Shift** to clear all today's session data. *(Only visible if you have `break_timer.reset` permission.)* A confirmation dialog appears — type the approval details and click **Reset Shift**.

### Today's Sessions

Below the timer, a collapsible list shows all sessions from today. Each entry shows session type, start/end times, status badge, and overage. Click to expand the timeline of events (start, pause, resume, end, etc.).

---

## Break Dashboard (Live Monitoring)

*resources/js/pages/BreakTimer/Dashboard.tsx*

[Insert Screenshot: 'Break Dashboard' Screen]

Monitor all active and completed break sessions across employees in real time. Auto-refreshes every 15 seconds.

### Summary Cards

Seven cards: **Total Sessions**, **Active Now** (green), **Currently Overbreak** (red), **Completed** (blue), **Overage** (red), **Avg Overage**, **Auto-Reset Today** (orange).

### Filters

1. **Date** — Pick a date.
2. **Employee** — Search and select.
3. **Status** — Choose **Active**, **Paused**, **Completed**, or **Overage**. *(Leave blank to show all.)*
4. **Type** — Choose **1st Break**, **2nd Break**, **Lunch**, or **Combined**. *(Leave blank to show all.)*
5. **Campaign** — Select a campaign.
6. Click **Filter** or **Reset**.

### Sessions Table

Columns: **Agent**, **Campaign**, **Station**, **Break Type**, **Status** (with optional **Overbreak** badge), **Timeline** (start/end times), **Expected End**, **Overage** (color-coded), **Pause/Resume Events**, **Actions**.

**Actions per session:**
- **View Timeline** — Opens a dialog showing the full event timeline with timestamps and reasons.
- **Force End** (red) — Ends immediately. Type the reason (required, min 3 chars). *(Requires `break_timer.force_end` permission.)*
- **Restore** — Restores remaining time. Optionally check **Restore full break minutes** (Admin/Super Admin/Team Lead only). Type the reason. *(Requires `break_timer.restore` permission.)*
- **Void** (orange) — Voids the session and frees the break/lunch slot. Type the reason. *(Requires `break_timer.void_session` permission.)*
- **Reimburse** (green) — Adds minutes back. Shows allotted time, current overage, already reimbursed, max reimbursable. Type minutes (1–180) and reason. *(Requires `break_timer.reimburse` permission.)*

### Timeline Dialog

Shows all events in order (**Start**, **Pause**, **Resume**, **End**, **Time Up**, **Auto-End**, **Reset**, **Force-End**, **Restore**, **Reimburse**) with timestamps, remaining seconds, overage seconds, and reason. Click **Undo from here** on eligible events to rewind — the button only appears on events the backend has flagged as rewindable. *(Requires `break_timer.restore` permission.)*

---

## Break Policies

*resources/js/pages/BreakTimer/Policies.tsx*

[Insert Screenshot: 'Break Policies' Screen]

Configure rules that control breaks and lunches.

### Policies Table

Columns: **Name**, **Max Breaks**, **Break Duration** (min), **Max Lunch**, **Lunch Duration** (min), **Grace Period** (sec), **Retention**, **Shift Reset**, **Status**, **Actions**.

- Toggle the switch to activate/deactivate.
- Click **Edit** (pencil) to modify.
- Click **Delete** (trash) to remove. Confirmation: *"This cannot be undone."*

### Add / Edit a Policy

*(Requires `break_timer.manage_policy` permission.)*

1. Click **Add Policy**. A dialog opens.
2. **Name** — Required.
3. **Max Breaks** — 0–10. Required.
4. **Break Duration** — Minutes (1–120). Required.
5. **Max Lunch** — 0–5. Required. *(Helper text below the field recommends 0–3 per day.)*
6. **Lunch Duration** — Minutes (1–240). Required. *(Helper text below the field recommends 1–180 minutes.)*
7. **Grace Period** — Seconds (0–1800). Required.
8. **Shift Reset Time** — 24-hour format.
9. **Data Retention** — Months (1–120) or empty for forever.
10. **Allowed Pause Reasons** — Comma-separated. Leave empty for free-text.
11. **Active** — Toggle on/off.
12. Click **Create** or **Update**.

---

## Break Reports

*resources/js/pages/BreakTimer/Reports.tsx*

[Insert Screenshot: 'Break Reports' Screen]

View historical break session data.

### Summary Cards

Six cards: **Total Sessions**, **Overage** (red), **Avg Overage** (orange), **Resets**, **Force Ended** (red), **Restored** (green).

### Filters

**Date Range**, **Employee**, **Type**, **Status**, **Admin Action** (Any / Force Ended / Restored / Reset / Auto-Ended), **Campaign**. Click **Filter** or **Reset**.

### Table

Columns: **Date**, **Agent**, **Campaign**, **Station**, **Type**, **Status**, **Started**, **Ended**, **Overage** (color-coded), **Ended By**, **Actions** (View Timeline).

### Export

Click **Export Excel** to download filtered data as .xlsx.

---

## Visual Themes

*resources/js/pages/BreakTimer/themes.ts*

| Theme | Visual Effect |
|---|---|
| **Default** | No decorations |
| **Cozy Cafe** ☕ | Rising steam, coffee beans, warm glow |
| **Rainy Window** 🌧️ | Rain streaks, fog, water droplets |
| **Sakura** 🌸 | Cherry blossom petals, birds |
| **Ocean Tide** 🌊 | Waves, fish, bubbles |
| **Neon City** 🌃 | Skyline with blinking windows |
| **Golden Hour** 🌅 | Sun, light rays, floating dust |
| **Deep Forest** 🌲 | Parallax trees, fireflies |
| **Snowfall** ❄️ | Snowflakes, frost |
| **Moonlit** 🌙 | Moon, stars, clouds |
| **Aurora** ✨ | Aurora bands, sparkles |
| **Cyberpunk** 🌃 | Grid, matrix rain, hexagons |
| **Synthwave** 🌇 | Retro sun, grid, palm trees |
| **Desktop Goose** 🪿 | Interactive goose that chases cursor, honks, panics during overage |

Select from the dropdown on the main timer page.

---

## Alarm Sounds

*resources/js/pages/BreakTimer/useAlarmSound.ts*

| Sound | Icon |
|---|---|
| **No Sound** | 🔇 |
| **Zen Bell** | 🎐 |
| **Corporate** | 🏢 |
| **8-Bit** | 👾 |
| **Beep** | 🔔 |
| **Urgent** | 🚨 |
| **Chime** | 🎵 |
| **Alert** | 📢 |
| **Buzzer** | ⏰ |

When time runs out, the alarm plays on repeat until you end the session. A browser notification also appears.
