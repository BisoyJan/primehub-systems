# Station Management

**Project Reference:** `resources/js/pages/Station/`

---

## Station List

Path: **Stations** (left sidebar menu)

The Station List page shows all workstations in the system. You can filter, select, and manage them.

### Filters

**Station(s) Filter:**
- Click the **Station(s)** dropdown to open a search box
- Type a station name or number to narrow results
- Check the boxes next to stations you want, then click **Apply Filters**
- *System rejects if the station ID is invalid or does not exist*
- Selected stations appear as badges above the filter card — click the **X** on a badge to remove it

**Station # Range:**
- Type a starting number in the **From** field and an ending number in the **To** field
- Press **Enter** on your keyboard or click **Apply Filters**
- *System rejects if "From" is greater than "To" or either value is zero*

**Site Dropdown:**
- Click **Site** and pick a location from the list
- The table updates to show only stations at that site

**Campaign Dropdown:**
- Click **Campaign** and pick a campaign name
- Only stations assigned to that campaign appear

**Status Dropdown:**
- Click **Status** and pick a status (Occupied, Vacant, No PC, Admin, etc.)

**Processor Filter:**
- Click the **Processor** dropdown, check one or more processors, then click **Apply Filters**
- *System rejects if the processor ID is invalid*

**Reset Button:**
- Appears only when filters are active
- Click **Reset** to clear all filters and show every station

**Apply Filters Button:**
- Click after setting filters to refresh the list

**Refresh Button (circular arrow icon):**
- Click to manually refresh the station list

**Auto-Refresh Toggle (play/pause icon):**
- Click the **Play** button to auto-refresh every 30 seconds
- Click the **Pause** button to stop auto-refresh
- The button turns blue when auto-refresh is active

### Action Buttons

**Add Station:**
- Click the **Add Station** button to create a new station (see "Create Station")
- *(Only IT and Super Admin can add stations.)*

**Bulk Assign:**
- Click **Bulk Assign** to open a window where you can assign campaigns, statuses, or monitor types to multiple stations at once (see "Bulk Assignment")
- *(Only IT and Super Admin can bulk-assign/edit stations.)*

**Sites Button:**
- Click **Sites** to go to the Site Management page
- *(Only Admin, IT, and Super Admin can view Sites.)*

**Campaigns Button:**
- Click **Campaigns** to go to the Campaign Management page
- *(Only Admin, IT, and Super Admin can view Campaigns.)*

### Selecting Stations for Actions

The list has checkboxes on the left side of each row.

**Select Individual:**
- Check the box next to a station row

**Select All on Current Page:**
- Check the box in the table header — all stations on the current page are checked

**Select All Across All Pages:**
- After checking some stations, a link appears: **Select all X stations**
- Click it to select every station that matches your current filters across all pages

**Clear Selection:**
- Uncheck the header checkbox, or click the **Clear** button in the blue selection bar

*Selection expires after 15 minutes of inactivity*

### QR Code Actions

Once stations are selected, a blue selection bar appears:

**Download Selected QR Codes:**
- Click **Download Selected QR** to get a ZIP file containing QR code images for every checked station
- *System rejects if no stations are selected*

**Download All QR Codes:**
- Click the **Download All QR** button to download QR codes for all stations (not just selected)
- This button is available even when nothing is checked

### Unassign Selected

- Click **Unassign Selected** to remove PC assignments from all checked stations
- *System rejects if no stations are selected*
- A confirmation dialog appears — click **Confirm** to proceed, or **Cancel** to stop
- *This action cannot be undone — PCs become unassigned and available for other stations*
- *(Requires the `stations.edit` permission — IT and Super Admin only.)*

### Delete Selected

- Click **Delete Selected (X)** to permanently remove all checked stations
- *(Requires the `stations.delete` permission.)*
- A confirmation dialog appears explaining that the action cannot be undone
- **Also delete stations with existing transfer history** — check this box to force-delete stations that have PC transfer history. Left unchecked, stations with transfer history are automatically skipped and a warning message reports how many were skipped
- Click **Delete** to confirm, or **Cancel** to stop

### Table Columns

Each row shows: checkbox, **Site**, **Station #**, **Campaign**, **Status** (color-coded badge), **Monitor Type** (Single/Dual/None), **PC Full Details** (manufacturer and PC number), **Processor** (cores/threads, hidden below the `lg` breakpoint), **PC Issue** (hidden below the `xl` breakpoint), **PC Notes** (hidden below the `xl` breakpoint), and **Actions** (View, Edit, Delete).

**View (eye icon):**
- Click to open the PC Spec Details dialog showing RAM, disk, ports, BIOS date, processor specs
- *If the station has no PC assigned, this button does not appear*

**Edit:**
- Click to edit the station (see "Edit Station")

**Delete:**
- Click to remove the station permanently
- A confirmation dialog appears — click **Delete** to confirm
- *This deletes the station immediately, including any assigned monitors — it is not blocked by an assigned PC or transfer history (only the bulk "Delete Selected" action skips stations with transfer history unless forced)*

### Assigning PCs to Empty Stations

- Check one or more stations that show "No PC" or "No PC assigned"
- The selection bar shows **X empty stations selected**
- Click **Assign PCs** to go to the PC Transfer page with those stations pre-selected
- *System rejects if you select stations that already have a PC assigned*

### Bulk Assignment

Click **Bulk Assign** to open the bulk assignment window. You can create one or more groups with different settings.

**Add a Group:**
- Each group has a **Stations** search field, **Campaign** dropdown, **Status** dropdown, and **Monitor** dropdown
- Click the **Stations** field to search and check the stations for this group
- Select a **Campaign** (or "None" / "Clear" to remove campaign), **Status**, and **Monitor** type
- Click **+ Add Group** to create another group with different stations and settings

**Remove a Group:**
- Click the trash icon next to a group to remove it
- *If only one group remains and you remove it, a fresh empty group appears instead*

**Submit:**
- Click **Apply Bulk Assignment** to save all groups at once
- *System rejects if a group has stations selected but no campaign, status, or monitor type to apply*

[Insert Screenshot: 'Station List Page Showing Filters and Selection Bar' Screen Layout]

---

## Create Station

Path: **Stations** → Click **Add Station**

The Create Station page lets you add one station at a time, or several stations at once using bulk mode.

### Toggle Bulk Mode

- Check **Create Multiple Stations** at the top of the page to switch to bulk mode
- Uncheck to return to single mode

### Single Station Mode

**Site:**
- Click the **Site** dropdown and select a location
- *System rejects if no site is selected*

**Station Number:**
- Type a unique station number (letters and numbers, automatically capitalized)
- *System rejects if the station number already exists*

**Campaign (Optional):**
- Click **Campaign** and pick a campaign, or select **None**

**Status (Optional):**
- Click **Status** and pick Occupied, Vacant, No PC, Admin, or **None**

**Monitor:**
- Click **Monitor** and choose **Single Monitor**, **Dual Monitor**, or **No Monitor**

**PC Spec (Optional):**
- A table shows all available PC specs
- Click a row to select it (highlighted in blue)
- *PC specs already assigned to another station are grayed out and cannot be selected*
- Click **Clear Selection** (red text) to deselect

**Warning/Info Messages:**
- If no PC spec is selected, a yellow warning appears: "No PC spec selected"
- If a PC spec is selected, a blue confirmation appears for 4 seconds

**Save:**
- Click **Save** to create the station
- *System rejects if required fields (Site, Station Number) are missing or invalid*

### Bulk Mode

When **Create Multiple Stations** is checked:

**Starting Station Number:**
- Type a pattern with a number, e.g., `PC-1A`, `ST-001`, `WS-10B`
- Click **Increment Type** to choose:
  - **Number Only** — numbers go up: PC-1A → PC-2A → PC-3A
  - **Letter Only** — letters go up: PC-1A → PC-1B → PC-1C
  - **Both** — both increment: PC-1A → PC-2B → PC-3C

**Quantity:**
- Type how many stations to create (minimum 1, maximum 100)
- *System rejects if quantity is less than 1 or more than 100*

**Preview:**
- A blue box shows a preview of the station numbers that will be created

**PC Specs (Optional):**
- You can select up to the same number as **Quantity**
- Each PC spec will be assigned to one station in order
- If you select fewer PC specs than **Quantity**, remaining stations have no PC spec

**Save:**
- Click **Create X Station(s)** (the button shows the quantity)
- *System rejects if starting number or quantity is missing, or if the pattern is invalid*

[Insert Screenshot: 'Create Station Page with Bulk Mode Active' Screen Layout]

---

## Edit Station

Path: **Stations** → Click **Edit** on a station row

**Site:**
- Click **Site** to change the location
- *System rejects if the site does not exist*

**Station Number:**
- Edit the station number (auto-capitalized)
- *System rejects if the new number is already used by another station*

**Campaign (Optional):**
- Change the campaign or select **None**

**Status (Optional):**
- Change the status or select **None**

**Monitor:**
- Change between Single, Dual, or No Monitor

**PC Spec:**
- Click a different PC spec row to change the assignment
- Click **Remove PC Spec** to unassign the PC from this station
- *PC specs already assigned to other stations are grayed out*
- *PC specs marked as "used" show a "Used" badge — you can still select them, but the system will reassign them*

**Save:**
- Click **Save** to apply changes
- *System rejects if the station number is empty or already taken*

**Cancel:**
- Click **Cancel** to go back to the Station List without saving

[Insert Screenshot: 'Edit Station Page' Screen Layout]

---

## Scan Result (QR Code View)

Path: Scan a station QR code, or navigate from a QR code

This page shows station details in a clean, read-only layout.

**Station Header:**
- Displays the station number and monitor type (Single/Dual/No Monitor)
- Status badge (color-coded)

**Location Info:**
- **Site** card shows the site name
- **Campaign** card shows the campaign name

**PC Specification:**
- If a PC is assigned: shows manufacturer, PC number, memory type, RAM, disk, ports, BIOS release date, processor specs (manufacturer, model, cores/threads, clock speeds)
- If a PC has an **Issue**, it appears in a red box
- If no PC is assigned: shows "No PC assigned to this station"

**Monitors:**
- Lists assigned monitors with brand, model, screen size, resolution, panel type, and quantity

**Action Buttons:**
- Click **Edit Station** to go to the Edit page
- Click **Assign PC** (if no PC) or **Transfer PC** (if PC exists) to go to the PC Transfer page

**Error State:**
- If the QR code is invalid or the station does not exist, a red error message appears: "Station not found or you are not authorized"
- Click **Back to List** to return to the Station List

[Insert Screenshot: 'QR Scan Result Page Showing Station Details' Screen Layout]

---

## Campaign Management

Path: **Stations** → Click **Campaigns**

Campaigns are labels that group stations (e.g., by project, client, or season).

### Searching Campaigns

**Search Bar:**
- Type a campaign name in the search field
- Press **Enter** on your keyboard to search
- Click **Filter** to apply the search
- *System rejects if the search text contains invalid characters*

**Reset Button:**
- Appears when a search is active
- Click **Reset** to clear search and show all campaigns

**Refresh & Auto-Refresh:**
- Click the **Refresh** (circular arrow) icon to refresh the list
- Click **Play** to auto-refresh every 30 seconds
- Click **Pause** to stop auto-refresh

### Table Columns

Each row shows: **ID**, **Name**, and **Actions** (Edit, Delete).

### Adding a Campaign

- Click **Add Campaign**
- A dialog opens. Type the campaign name in the **Name** field
- Click **Save**
- *System rejects if the name is empty or a campaign with the same name already exists*

### Editing a Campaign

- Click **Edit** next to a campaign
- The dialog opens with the current name filled in
- Edit the name and click **Save**
- *System rejects if the name is empty or another campaign already uses that name*

### Deleting a Campaign

- Click **Delete** next to a campaign
- A confirmation dialog asks: "Are you sure you want to delete [name]?"
- Click **Delete** to confirm, or **Cancel** to stop
- *System rejects if the campaign is assigned to existing stations*

[Insert Screenshot: 'Campaign Management Page' Screen Layout]

---

## Site Management

Path: **Stations** → Click **Sites**

Sites represent physical locations (buildings, floors, offices) where stations are set up.

### Searching Sites

**Search Bar:**
- Type a site name — the list automatically filters as you type (500ms delay)
- Click **Reset** to clear the search
- *System rejects if the search text contains invalid characters*

**Refresh & Auto-Refresh:**
- Click the **Refresh** (circular arrow) icon to refresh the list
- Click **Play** to auto-refresh every 30 seconds
- Click **Pause** to stop auto-refresh

### Table Columns

Each row shows: **ID**, **Name**, and **Actions** (Edit, Delete).

### Adding a Site

- Click **Add Site**
- A dialog opens. Type the site name in the **Name** field
- Click **Save**
- *System rejects if the name is empty or a site with the same name already exists*

### Editing a Site

- Click **Edit** next to a site
- The dialog opens with the current name filled in
- Type the new name and click **Save**
- *System rejects if the name is empty or another site already uses that name*

### Deleting a Site

- Click **Delete** next to a site
- A confirmation dialog asks: "Are you sure you want to delete [name]?"
- Click **Delete** to confirm, or **Cancel** to stop
- *System rejects if the site has stations assigned to it*

[Insert Screenshot: 'Site Management Page' Screen Layout]

---

## PC Transfer

Path: **Stations** → Click **PC Transfer** (or the **Transfer** button on the Station List)

PC Transfer lets you move or assign PC specs between stations.

### Transfer List

**Filters:**
- **Search** box — type station name, site, or campaign to narrow results
- **Site** dropdown — filter by location
- **Campaign** dropdown — filter by campaign
- Check **Reset** to clear filters (appears when any filter is active)
- Click **Apply Filters** to refresh results
- Click the **Refresh** icon to manually refresh
- Click **Play** to auto-refresh every 30 seconds, **Pause** to stop

**Page Header Actions:**
- **Bulk Transfer** — click to enter bulk mode (see "PC Transfer — Bulk Mode")
- **Transfer** — click to go to the Transfer page for a single station
- **View History** — click to see the PC transfer history log

**Table Columns:**
- **Station** — station number
- **Site** — site name
- **Campaign** — campaign name
- **Status** — color-coded badge (green=Occupied, yellow=Vacant, red=No PC, blue=Admin)
- **Current PC** — manufacturer name and PC number (if assigned), or "No PC assigned"
- **PC Details** — processor, RAM, disk type, ports
- **Actions** — **Transfer/Assign** button and **Unassign** button

**Transfer/Assign Button:**
- If the station has a PC, click **Transfer** to move that PC to another station
- If the station has no PC, click **Assign** to give it a PC
- Clicking opens the PC Transfer interface (see below)

**Unassign Button:**
- Appears only for stations that have a PC
- Click **Unassign** to remove the PC from this station
- *System rejects if the station has no PC*
- A confirmation dialog appears — click **Confirm** to proceed
- *The PC becomes "floating" (unassigned) and available for other stations*

### PC Transfer — Bulk Mode

Click **Bulk Transfer** to enter bulk mode.

- Checkboxes appear next to each station
- Check one or more stations (a count badge shows **X selected**)
- Click **Configure Transfers** to go to the Transfer page with all selected stations

Click **Cancel Bulk Mode** to exit selection mode.

### PC Transfer Interface

This page has two sides:

**Left Side — Available PC Specs:**
- Shows all PC specs with search, assignment status filter (All/Available/Assigned), Site filter, and Campaign filter
- Click **Select** on a PC to add it to your transfer list
- PCs already assigned to a station show "At [Station #]"
- Available (unassigned) PCs show "Available" badge
- *System rejects if you try to select a PC that is already in the transfer list*

**Right Side — Destination Stations:**
- Shows stations with search, PC status filter (All/Empty/Has PC), Site filter, and Campaign filter
- After selecting a PC, click **Select** on a station to assign that PC to it
- *System rejects if you select a station that is already assigned in a different transfer in the same batch*

**Selected Transfers Card:**
- Lists all pending transfers in a collapsible card
- Each transfer shows: PC name → Station name
- Each transfer pair has a unique random color for easy matching
- Click the **X** icon to remove a transfer from the list
- If a PC is replacing another PC at a station, the replaced PC shows as "Floating" with an orange background
- Click **Assign** on a floating PC to add it to the transfer list (so it gets assigned somewhere instead of becoming unassigned)

**Transfer Notes (Optional):**
- Type notes about the transfer in the text area (max 500 characters)
- Character count shown below (e.g., "0/500 characters")

**Submit:**
- Click **Transfer X PC(s)** to execute all pending transfers
- *System rejects if any transfer is incomplete (PC selected but no destination station)*
- *System rejects if a PC is being transferred to the station it is already at*
- *System rejects if the same station receives multiple PCs in one batch*
- *System rejects if the same PC is included more than once*
- On success, a message shows "X PC(s) transferred successfully!" and auto-redirects after 3 seconds

**Help Button:**
- Click the animated help icon to open a help dialog explaining how to:
  - Assign a PC to an empty station
  - Transfer a PC from one station to another
  - Use bulk mode for multiple stations
  - Understand the color code system (blue highlight, random colors, orange alert)
- Click **Got it!** to close the help dialog

**Cancel:**
- Click **Back to Transfers** to return to the Transfer List page

[Insert Screenshot: 'PC Transfer Interface Showing Two-Panel Layout' Screen Layout]

---

## PC Transfer History

Path: **Stations** → **PC Transfer** → Click **View History**

This page shows a complete log of all PC transfers (assignments, swaps, and removals).

**Auto-Refresh:**
- Click the **Refresh** (circular arrow) icon to manually refresh the history
- Click **Play** to auto-refresh every 30 seconds
- Click **Pause** to stop auto-refresh

**Table Columns (Desktop):**
- **Date & Time** — when the transfer happened
- **PC** — the PC that was moved
- **From Station** — the station the PC left (or "-" if it was unassigned)
- **To Station** — the station the PC went to (or "-" if it was removed)
- **Type** — badge showing "assign" (blue), "swap" (gray), or "remove" (red)
- **User** — who performed the transfer
- **Notes** — any notes added during the transfer

**Mobile View:**
- Cards show PC, date, From/To stations with arrow, user, notes
- Type shown as a colored badge

**Pagination:**
- Pages shown at the bottom
- Click a page number to go to that page

[Insert Screenshot: 'PC Transfer History Page' Screen Layout]

---

## PC Maintenance

### Maintenance List

*resources/js/pages/Computer/PcMaintenance/Index.tsx*

[Insert Screenshot: 'PC Maintenance List' Screen Layout]

Tracks when each PC received maintenance and when the next maintenance is due.

#### Filters Row

- **Search PC** box — Type a PC number and press **Enter** to search.
- **Station range** — Two small boxes labeled **From** and **To**. Type station numbers to show only PCs within that station range.
- **Assignment** dropdown — Select **Assigned**, **Unassigned**, or **All PCs**.
- **All sites** dropdown — Select a specific site or keep **All sites**.
- **All statuses** dropdown — Select **All statuses**, **Completed**, **Pending**, or **Overdue**.
- **Filter** button — Click to apply.
- **Reset** button — Click to clear all filters.
- **Refresh** button — Click to reload.
- **Auto-refresh (30s)** — Click **Play**/**Pause** to toggle.
- **Add Record** button — Click to open the create form.

#### Status Badges

Each record shows a colored badge:
- **Completed** (green) — Maintenance was done.
- **Overdue** (red) — The next due date has passed.
- **Due Soon** (yellow) — Due within 7 days.
- **Pending** (outline) — No urgent action needed.

The **Days Until Due** column shows text like "5 days left", "Due today", or "3 days overdue" with red or yellow coloring when urgent.

#### Bulk Selection & Update

Checkboxes persist across page navigation for up to 15 minutes.

- Click the header checkbox to select all on the current page.
- After selecting, a bulk action bar appears showing the count. If there are more records across pages, click **Select all X records** to select everything matching the current filters.
- **Bulk Update Maintenance** — Opens a dialog where you can set common values for all selected records:
  - **Last Maintenance Date** and **Next Due Date** (next due auto-calculates to 4 months after the last date).
  - **Maintenance Type** (e.g., Quarterly, Annual).
  - **Performed By** — Type the technician name.
  - **Status** — Select **Completed**, **Pending**, or **Overdue**.
  - **Notes** — Optional notes apply to all.
  - Click **Update {count} Records** to save. *Clicking Cancel discards all changes.*
- **Clear Selection** — Deselects all.

#### Desktop Table

Columns: **Stock Number**, **Current Station**, **Site**, **Maintenance Type**, **Maintenance Date** (last + next due on two lines), **Days Until Due** (with color), **Performed By**, **Status** (badge), **Actions** (Edit pencil icon, Delete with confirmation).

#### Mobile Cards

On narrow screens, each maintenance entry appears as a stacked card showing all fields and actions.

#### Pagination

Page number links appear below the table. Count shows "Showing X-Y of Z maintenance records".

### Create Maintenance Record

*resources/js/pages/Computer/PcMaintenance/Create.tsx*

Opens when you click **Add Record**. Has two sections:

**Step 1 — Select PCs for Maintenance:**
- **Assignment Status** dropdown — Select **Assigned**, **Not Assigned**, or **All PCs**. *(The list page uses the label "Unassigned" for the same option.)*
- **Filter by Site** dropdown — Select a site or keep **All sites**.
- **Station Range** (From/To boxes).
- A table lists matching PCs with checkboxes. Each row shows **PC Number**, **Model**, **Current Station**, and **Site**.
- Use the header checkbox to select/deselect all filtered PCs.
- The selected count updates in real time.

*You must select at least one PC; the Create button stays disabled until you do.*

**Step 2 — Maintenance Details (applies to all selected PCs):**
- **Last Maintenance Date** — Pick the date maintenance was performed. Defaults to today.
- **Next Due Date** — Auto-calculated to 4 months after the last date. You can override it.
- **Maintenance Type** — Defaults to **Routine Maintenance**. Type a different type if needed.
- **Performed By** — Type the name of the technician.
- **Status** — Defaults to **Completed**. Can be changed to **Pending** or **Overdue**.
- **Notes** — Optional notes.

Click **Create ({count})** at the bottom. The button shows **Creating...** while processing. *Any missing required date fields cause a red error.*

### Edit Maintenance Record

*resources/js/pages/Computer/PcMaintenance/Edit.tsx*

Pre-filled form for a single maintenance record:

- **PC** dropdown — Change the PC if needed. Each option shows the PC number, current station, and site.
- Current station info is displayed below the dropdown.
- Same fields as Create: **Last Maintenance Date**, **Next Due Date**, **Maintenance Type**, **Performed By**, **Status**, **Notes**.
- Click **Update** to save or **Cancel** to return to the list.
