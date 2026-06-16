# Proccessor Specs

*resources/js/pages/Computer/ProcessorSpecs*

## Viewing the Proccessor List

[Insert Screenshot: 'Proccessor Specs' List Screen Layout]

1. Navigate to the **Proccessor Specs** screen. A table shows each processor entry with **Manufacturer**, **Model**, **Cores**, **Threads**, **Base Clock**, and **Boost Clock**.
2. To narrow the list, click the **Select processors to filter...** field. Search or pick from the list, then click **Filter**.
3. To clear all filters, click **Reset**.
4. Click the **Refresh** icon to update the list. Click **Play** to turn on auto-refresh (updates every 30 seconds).
5. Use the page numbers at the bottom to move between pages.

## Adding a New Proccessor Spec

[Insert Screenshot: 'Create Proccessor Specification' Form]

1. From the list screen, click the **Add Processor** button.
2. **Manufacturer** — Select **Intel** or **AMD** from the dropdown. *If you do not select a manufacturer, the system will show an error message.*
3. **Model** — Type the model name (e.g., Core i5-12400). *If you leave this blank, the system will show an error message.*
4. **Core Count** — Type the number of cores (e.g., 6). *If you type a number lower than 1 or leave it blank, the system will show a red error message.*
5. **Thread Count** — Type the number of threads (e.g., 12). *If you type a number lower than 1 or leave it blank, the system will show a red error message.*
6. **Base Clock (GHz)** — Type the base speed (e.g., 2.50). *If you leave this blank, the system will show a red error message.*
7. **Boost Clock (GHz)** — Type the boost speed (e.g., 4.40). This field is optional. *If you type a value lower than 0, the system will show a red error message.*
8. Click **Add Processor Spec** to save. *The system checks all fields. Anything missing or incorrect shows a red error message next to the field.*

## Editing a Proccessor Spec

[Insert Screenshot: 'Edit Proccessor Specification' Form]

1. On the list screen, locate the processor to change. Click the green **Edit** button for that row.
2. Update any field. All rules from Adding apply here.
3. Click **Update Processor Spec** to save. *If a field fails the system check, a red error message appears next to it.*

## Deleting a Proccessor Spec

1. On the list screen, locate the processor to remove. Click the **Delete** button.
2. A confirmation dialog appears. Click **Confirm** to permanently remove the entry. *If the processor is assigned to any PC specs, the system will block the deletion and show an error message.* Click **Cancel** to keep the record.
