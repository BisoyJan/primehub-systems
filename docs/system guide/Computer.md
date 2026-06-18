# Computer

*resources/js/pages/Computer/PcSpecs/*, *resources/js/pages/Computer/ProcessorSpecs/*, *resources/js/pages/Computer/PcMaintenance/*

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

## Processor Specs

*resources/js/pages/Computer/ProcessorSpecs*

### Processor Specs List

*resources/js/pages/Computer/ProcessorSpecs/Index.tsx*

[Insert Screenshot: 'Processor Specs List' Screen Layout]

The page header reads **Processor Specs Management** with the description *"Manage CPU/processor component specifications and inventory."*

#### Filter & Toolbar

1. Click the **Select processors to filter...** dropdown. A searchable list opens — type to filter, then check one or more processors. Selected processors appear as removable badges below the toolbar.
2. Click **Filter** to apply the selection, or **Reset** to clear it.
3. Click the **Refresh** icon (circular arrow) to reload the list manually.
4. Click **Play** to turn on auto-refresh (updates every 30 seconds); click **Pause** to turn it off.
5. **Add Processor** — Opens the create form. *(Only visible if you have appropriate permissions.)*

The row above the table shows **"Showing N records"** (with a `(filtered)` suffix when a filter is active) and a **Last updated** timestamp.

#### Desktop Table

Columns: **ID** (visible on large screens only), **Manufacturer**, **Model**, **Cores**, **Threads**, **Base Clock** (visible on extra-large screens only), **Boost Clock** (visible on extra-large screens only), **Actions**.

#### Mobile View

On narrow screens each processor appears as a stacked card showing Manufacturer, Model, Cores/Threads, Base Clock, and Boost Clock, with the same action buttons full-width.

#### Pagination

Use the page numbers at the bottom of the table to move between pages.

### Adding a New Processor Spec

*resources/js/pages/Computer/ProcessorSpecs/Create.tsx*

[Insert Screenshot: 'Create Processor Specification' Form]

The page header is **Create Processor Specification** with a **Back to list** button in the top-right.

1. From the list screen, click the **Add Processor** button.
2. **Manufacturer** — Select **Intel** or **AMD** from the dropdown. *Required; if you do not select a manufacturer, the system shows a red error message below the field.*
3. **Model** — Type the model name (e.g., Core i5-12400). *Required; if you leave this blank, the system shows a red error message.*
4. **Core Count** — Type the number of cores (e.g., 6). *Required; the value must be 1 or higher.*
5. **Thread Count** — Type the number of threads (e.g., 12). *Required; the value must be 1 or higher.*
6. **Base Clock (GHz)** — Type the base speed (e.g., 2.50), in 0.01 increments. *Required; the value must be 0 or higher.*
7. **Boost Clock (GHz)** — Type the boost speed (e.g., 4.40), in 0.01 increments. *Optional; leave blank if not applicable. If filled, the value must be 0 or higher.*
8. Click **Add Processor Spec** to save. The button shows **Saving...** while the request is in flight. *If any field fails validation, a red message appears next to it.*

### Editing a Processor Spec

*resources/js/pages/Computer/ProcessorSpecs/Edit.tsx*

[Insert Screenshot: 'Edit Processor Specification' Form]

1. On the list screen, locate the processor to change. Click the green **Edit** button for that row. *(Only visible if you have appropriate permissions.)*
2. The page header shows **Edit Processor Specification** with the current Manufacturer and Model as the description, plus a **Back to list** button.
3. Update any field. All rules from Adding apply here.
4. Click **Update Processor Spec** to save. The button shows **Saving...** while the request is in flight. *If a field fails validation, a red error message appears next to it.*

### Deleting a Processor Spec

1. On the list screen, locate the processor to remove. Click the red **Delete** button for that row. *(Only visible if you have appropriate permissions.)*
2. A confirmation dialog appears with the message *"Are you sure you want to delete {Manufacturer} {Model}?"*. Click **Confirm** to permanently remove the entry. *If the processor is assigned to any PC specs, the system blocks the deletion and shows an error message.* Click **Cancel** to keep the record.

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
