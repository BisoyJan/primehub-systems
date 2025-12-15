# Leave Credits Export Feature

## Overview
The Leave Credits Export feature allows administrators to export a summary of all employee leave credits for a specific year. This generates an Excel file with detailed information about earned, used, and remaining leave credits for each employee.

## How to Use

### From the UI
1. Navigate to **Form Requests → Leave Requests** (`/form-requests/leave-requests`)
2. Click the **"Export Credits"** button (visible only to admins)
3. In the dialog, enter the year you want to export (defaults to last year)
4. Click **"Export"**
5. Wait for the progress bar to complete
6. The file will automatically download when ready

### Export Contains
The exported Excel file includes:
- Employee Name
- Email
- Role
- Hire Date
- Total Earned Credits (for the year)
- Total Used Credits (for the year)
- Balance (remaining credits for the year)
- **Summary row with totals**

### Features
- **Background Processing**: Export runs as a queued job to avoid timeouts
- **Progress Tracking**: Real-time progress updates (0-100%)
- **Auto-download**: File downloads automatically when complete
- **Year-specific**: Only shows employees who had credits in the selected year
- **Professional Formatting**: 
  - Color-coded headers
  - Borders around all cells
  - Auto-sized columns
  - Bold summary row with totals
  - Number formatting (2 decimal places)

## Technical Details

### Backend Components

#### Job: `GenerateLeaveCreditsExportExcel`
- **Location**: `app/Jobs/GenerateLeaveCreditsExportExcel.php`
- **Purpose**: Generates the Excel file in the background
- **Queue**: Database queue (configured in `config/queue.php`)
- **Cache**: Uses cache for progress tracking (1 hour TTL)

#### Controller Methods: `LeaveRequestController`
- `exportCredits()` - Starts the export job
- `exportCreditsProgress()` - Checks job progress
- `exportCreditsDownload()` - Downloads the completed file

#### Routes
```php
POST   /form-requests/leave-requests/export/credits
GET    /form-requests/leave-requests/export/credits/progress
GET    /form-requests/leave-requests/export/credits/download/{filename}
```

### Frontend Components

#### Index Page
- **Location**: `resources/js/pages/FormRequest/Leave/Index.tsx`
- **Export Button**: Only visible to admins
- **Export Dialog**: Modal with year input and progress tracking
- **Polling**: Checks progress every 2 seconds
- **Timeout**: 5 minutes maximum wait time

### File Storage
- **Temporary Path**: `storage/app/temp/`
- **Naming**: `leave_credits_{year}_{datetime}.xlsx`
- **Auto-cleanup**: Files are deleted after download

### Permissions
Only users with `leave.view` permission and admin role (Super Admin, Admin, HR) can access the export feature.

## Business Rules

### Credit Calculation
Credits are calculated per year using the `LeaveCredit` model:
- **Total Earned**: Sum of `credits_earned` for the year
- **Total Used**: Sum of `credits_used` for the year  
- **Balance**: Sum of `credits_balance` for the year

### Employee Inclusion
Only employees who meet **any** of these criteria are included:
- Have earned credits > 0 for the year
- Have used credits > 0 for the year
- Have balance > 0 for the year

This excludes:
- Employees without hire dates
- Employees hired after the export year
- Employees with no credit activity in that year

### Year Constraints
- **Minimum**: 2020
- **Maximum**: Current year + 1
- **Default**: Last year (current year - 1)

## Queue Configuration

### Running the Queue Worker
```bash
# Start the queue worker
php artisan queue:work

# Or use supervisor (production)
# See deploy/supervisor.conf
```

### Sync Queue (Development)
If `QUEUE_CONNECTION=sync` in `.env`, jobs run immediately without background processing.

## Troubleshooting

### Export Takes Too Long
- Check queue worker is running: `php artisan queue:work`
- Increase timeout in job if needed
- Check database performance

### File Not Found Error
- Files expire after download
- Check `storage/app/temp/` directory exists
- Verify file permissions (0755)

### Progress Stuck at 0%
- Ensure queue worker is processing jobs
- Check logs: `storage/logs/laravel.log`
- Verify cache is working: `php artisan cache:clear`

### Empty Export
- Verify employees have credits for the selected year
- Check `leave_credits` table has data: `php artisan tinker`
  ```php
  LeaveCredit::whereYear('accrued_at', 2024)->count();
  ```

## Example Export Data

| Employee Name | Email | Role | Hire Date | Total Earned | Total Used | Balance |
|--------------|-------|------|-----------|--------------|------------|---------|
| John Doe | john@example.com | Agent | 2023-01-15 | 15.00 | 5.00 | 10.00 |
| Jane Smith | jane@example.com | Team Lead | 2022-06-01 | 18.00 | 8.00 | 10.00 |
| **TOTAL** | | | | **33.00** | **13.00** | **20.00** |

## Future Enhancements
- [ ] Filter by role or department
- [ ] Include month-by-month breakdown

---

*Last updated: December 15, 2025*
- [ ] Export as PDF option
- [ ] Email export to specific recipients
- [ ] Schedule automatic monthly exports
