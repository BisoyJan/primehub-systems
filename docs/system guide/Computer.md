# Computer

*resources/js/pages/Computer/PcSpecs/*, *resources/js/pages/Computer/PcMaintenance/*

> **Processor Specs** is covered separately in the *Processor Specs* guide ([ProcessorSpecs.md](./ProcessorSpecs.md)).

---

## PC Specifications (PcSpecs)

### PC Specs List

*resources/js/pages/Computer/PcSpecs/Index.tsx*

[Insert Screenshot: 'PC Specs List' Screen Layout]

The main inventory of all company computers and their hardware details.

#### Filters Row

- **All PCs** dropdown — Click to open a searchable multi-select list. Type to search, check the boxes next to the PCs you want, then click outside to close. Shows badge count when multiple are selected. Selected choices appear as removable badges below the bar. *At least one PC must be checked to filter; leaving all unchecked shows everything.*
- **All Processors** dropdown — Same searchable multi-select. Each processor shows core/thread count. Selected processors appear as a count badge. *Selecting multiple processors shows PCs that match any of them.*
- **PC number range** — Two boxes labeled **PC From** and **PC To**. Type a starting and ending PC number to show only those within that range.
- **QR Number Order** dropdown — Click and select **Ascending (A-Z)** or **Descending (Z-A)** to change the sort order.
- **Filter** button — Click to apply all selected filters.
- **Reset** button — Click to clear all filters and show everything.
- **Refresh** button — Click to reload the list.
- **Auto-refresh (30s)** — Click **Play** to turn on automatic refresh; click **Pause** to stop.
- **Add PC Spec** button — Click to open the create form.

#### QR Code Selection Bar

When one or more PCs are checked, a blue bar appears at the top:

- Shows the count of selected PCs.
- **Clear Selection** — Click to uncheck all PCs.
- **Download Selected QR Codes as ZIP** — Click to download QR code images for only the checked PCs. A floating green progress indicator shows the status.
- **Download All QR Codes as ZIP** — Click to download QR codes for every PC in the list. A floating blue progress indicator shows the status.

*QR generation can take a moment for large selections; wait for the download to start before navigating away.*

#### Desktop Table

Columns:

- **Checkbox** — Click the top checkbox to select or deselect all PCs on this page. Click individual row checkboxes to select specific PCs for QR download.
- **Stock Number** — The PC's QR number (e.g., PC1, PC2). A dash means no number assigned.
- **Station** — Badges showing which station numbers the PC is assigned to, or a dash if unassigned.
- **Manufacturer** — The brand name.
- **Processor** — Manufacturer and model (e.g., Intel Core i5-12400). Hidden on screens below the **xl** breakpoint (1280 px).
- **Cores** — Cores/Threads (e.g., 6C/12T). Hidden on screens below the **xl** breakpoint (1280 px).
- **RAM (GB)** — Amount of RAM.
- **Disk (GB)** — Storage capacity.
- **Ports** — Available port types (HDMI, USB-C, etc.). Hidden on screens below the **xl** breakpoint (1280 px).
- **Notes** — Inline editor (also hidden on screens below the **xl** breakpoint). A truncated preview of existing notes shows in the cell. Click **Add** (when empty) or **Edit** (when notes exist) to open a small dialog. Type your notes and click **Save**. *Leaving notes blank removes them.*
- **Issue** — Inline editor: Click **Add** or **Edit** to open a dialog. If an issue exists, it shows a red warning icon with the issue text. Type or clear the text, then click **Save**. *Adding an issue marks the PC with a red badge on its detail page.*
- **Actions** — Three buttons per row:
  - **Edit** (green button) — Click to open the edit form.
  - **Details** (outline button) — Click to open a read-only dialog showing full specs, memory type, RAM, disk, ports, BIOS date, and processor info in a grid layout.
  - **Delete** (red button) — Click to delete the PC spec. A confirmation dialog appears asking *"Are you sure you want to delete {PC number}? This action cannot be undone."* Click **Yes, Delete** to confirm.

#### Mobile Cards

On narrow screens, each PC shows as a card with icon headers. The same **Edit**, **Details**, **Issue**, and **Notes** actions are available inside each card, plus the **Delete** button at the bottom (with the same confirmation dialog).

### Create PC Spec

*resources/js/pages/Computer/PcSpecs/Create.tsx*

[Insert Screenshot: 'Create PC Spec' Screen Layout]

Opens when you click **Add PC Spec**.

**Core Info section:**
- **QR Number** — Type a number (e.g., `1`) or PC + number (e.g., `PC1`). The system automatically converts plain numbers to PC format. *Invalid formats like letters without PC prefix cause a red error.*
- **Quantity** — Type how many PCs to create sequentially. For example, QR Number `10` with quantity `3` creates PC10, PC11, PC12. A preview shows the list below the field.
- **Manufacturer** — Type the brand name.
- **Notes** — Optional text area for general notes.

**Specifications section:**
- **Memory Type** — Select **DDR3**, **DDR4**, or **DDR5**.
- **RAM (GB)** — Enter the amount.
- **Disk (GB)** — Enter the storage size.
- **Available Ports** — Type port names separated by commas (e.g., `HDMI, DisplayPort, USB-C`).
- **Bios Release Date** — Click the calendar icon to pick the BIOS date.

**Processor section:**
A toggle button switches between two modes:

- **Select Existing** (default) — A searchable dropdown. Click to open, type to search, then click a processor from the list. Each item shows the core/thread count and clock speeds.
- **Create New** — Click the **Create New** button to switch. Fill in:
  - **Manufacturer** — Select **Intel** or **AMD**.
  - **Model** — Type the model name.
  - **Core Count**, **Thread Count**, **Base Clock (GHz)**, **Boost Clock (GHz)**.
  - *When creating new, a new processor record is saved automatically.*

**Submit:**
- If quantity is 1: **Create PC Spec** button.
- If quantity is more than 1: **Create {number} PCs** button.
- *Required fields left blank or invalid values cause a red error message above the field.*

### Edit PC Spec

*resources/js/pages/Computer/PcSpecs/Edit.tsx*

Same form as Create but pre-filled with current values. Only one PC can be edited at a time (no quantity field). Click **Update PC Spec** to save. *Invalid fields show red errors.*

### PC Spec Details Page

*resources/js/pages/Computer/PcSpecs/Show.tsx*

A standalone read-only detail card for a single PC spec (separate from the Details dialog opened from the list).

- **Header** — PC number (or `PC #{id}` if unset), manufacturer, and a **PC Details** badge.
- **Specifications grid** — Manufacturer, Chipset, Memory Type, RAM (GB), Disk (GB), Available Ports, Bios Release Date, and the assigned Station (only shown when the PC has one).
- **Processor(s)** section — Each linked processor is listed in its own card with manufacturer, model, cores/threads, and clock speeds.

### PC Spec Scan Result

*resources/js/pages/Computer/PcSpecs/ScanResult.tsx*

Shown after scanning a PC's QR code. Displays a clean card layout:

- **PC label** with manufacturer and a **Has Issue** or **No Issues** badge.
- If an issue was reported, a red alert card shows the issue text.
- **Bios Release Date** if available.
- **RAM** tile (size in GB plus memory type) and **Storage** tile (size in GB).
- **Available Ports** if listed.
- **Notes** if any.
- **Processor(s)** — Each processor shows manufacturer, model, and badges for cores/threads and clock speeds.
- **Assigned Stations** — Badges showing station numbers and status.
- Three buttons at the bottom:
  - **Edit PC Spec** — Opens the edit form.
  - **Assign to Station** — Opens the transfer page with this PC pre-selected.
  - **Back to List** — Returns to the PC Specs list.

*If the QR code scan fails, an error screen appears with a Back to List button.*

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
