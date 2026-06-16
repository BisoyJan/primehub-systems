# Processor Specs

*resources/js/pages/Computer/ProcessorSpecs*

## Viewing the Processor List

*resources/js/pages/Computer/ProcessorSpecs/Index.tsx*

[Insert Screenshot: 'Processor Specs' List Screen Layout]

The page header reads **Processor Specs Management** with the description *"Manage CPU/processor component specifications and inventory."*

### Filter & Toolbar

1. Click the **Select processors to filter...** dropdown. A searchable list opens — type to filter, then check one or more processors. Selected processors appear as removable badges below the toolbar.
2. Click **Filter** to apply the selection, or **Reset** to clear it.
3. Click the **Refresh** icon (circular arrow) to reload the list manually.
4. Click **Play** to turn on auto-refresh (updates every 30 seconds); click **Pause** to turn it off.
5. **Add Processor** — Opens the create form. *(Only visible if you have `hardware.create` permission.)*

The row above the table shows **"Showing N records"** (with a `(filtered)` suffix when a filter is active) and a **Last updated** timestamp.

### Desktop Table

Columns: **ID** (visible on large screens only), **Manufacturer**, **Model**, **Cores**, **Threads**, **Base Clock** (visible on extra-large screens only), **Boost Clock** (visible on extra-large screens only), **Actions**.

### Mobile View

On narrow screens each processor appears as a stacked card showing Manufacturer, Model, Cores/Threads, Base Clock, and Boost Clock, with the same action buttons full-width.

### Pagination

Use the page numbers at the bottom of the table to move between pages.

## Adding a New Processor Spec

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

## Editing a Processor Spec

*resources/js/pages/Computer/ProcessorSpecs/Edit.tsx*

[Insert Screenshot: 'Edit Processor Specification' Form]

1. On the list screen, locate the processor to change. Click the green **Edit** button for that row. *(Only visible if you have `hardware.edit` permission.)*
2. The page header shows **Edit Processor Specification** with the current Manufacturer and Model as the description, plus a **Back to list** button.
3. Update any field. All rules from Adding apply here.
4. Click **Update Processor Spec** to save. The button shows **Saving...** while the request is in flight. *If a field fails validation, a red error message appears next to it.*

## Deleting a Processor Spec

1. On the list screen, locate the processor to remove. Click the red **Delete** button for that row. *(Only visible if you have `hardware.delete` permission.)*
2. A confirmation dialog appears with the message *"Are you sure you want to delete {Manufacturer} {Model}?"*. Click **Confirm** to permanently remove the entry. *If the processor is assigned to any PC specs, the system blocks the deletion and shows an error message.* Click **Cancel** to keep the record.
