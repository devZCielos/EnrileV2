# Leave Notification System - Implementation Guide

## Overview
This system automatically notifies employees about:
1. **Filing Deadlines**: N days before a leave request should be filed (based on leave type requirements)
2. **Leave Ending Reminders**: 1 day before their scheduled leave ends

## Database Changes

### 1. leave_types Table - New Columns
The following columns have been added:
- `applicable_gender` (ENUM: 'Male', 'Female', 'All') - Controls which employees can apply for this leave type
- `require_married` (TINYINT) - Whether employee must be married to apply
- `advance_notice_days` (INT) - Number of days in advance employee must file this leave (0 = same-day filing allowed)

**Configuration by Leave Type:**
- **Vacation Leave (VL)**: 5 days advance notice required
- **Mandatory/Forced Leave (MFL)**: Same-day filing (0 days)
- **Sick Leave (SL)**: Same-day filing (0 days)
- **Maternity Leave (ML)**: 30 days advance notice, Female only
- **Paternity Leave (PL)**: 14 days advance notice, Male only, Married required
- **Special Privilege Leave (SPL)**: Same-day filing (0 days)
- **Solo Parent Leave (SOLO)**: 5 days advance notice
- **Study Leave (STL)**: 30 days advance notice
- **VAWC Leave**: 14 days advance notice, Female only
- **Emergency Leave (EL)**: Same-day filing (0 days)

### 2. leave_notifications Table - New Table
Stores all leave-related notifications with tracking for delivery status.

**Schema:**
- `notification_id`: Primary key
- `employee_id`: Target employee
- `request_id`: Reference to leave_requests (nullable)
- `leave_type_id`: Reference to leave_types
- `notification_type`: ENUM (FILING_DEADLINE, LEAVE_REMINDER, LEAVE_ENDING, OTHER)
- `title`: Notification title
- `message`: Full notification text
- `target_date`: Date when notification should be shown
- `is_sent`: Whether notification has been viewed (1 = yes)
- `read_at`: Timestamp when employee viewed notification
- `created_at`: When notification was created
- `updated_at`: Last update timestamp

## Implementation Details

### 1. Notification Creation (Employee_portal.php - submit_leave case)
When an employee submits a leave request:
1. The `submit_leave` handler checks if the leave type requires advance notice (`advance_notice_days > 0`)
2. If advance notice is needed:
   - Calculates filing deadline: `date_from - advance_notice_days`
   - Creates a FILING_DEADLINE notification for that date
3. Always creates a LEAVE_ENDING reminder:
   - Set to 1 day before `date_to`
   - Alerts employee to prepare for work return

### 2. Background Job (cron_leave_notifications.php)
**Purpose**: Processes notifications daily to mark them as sent and generate new ones for approved leaves

**Execution**: Should be run daily (recommended: 6:00 AM)
```bash
0 6 * * * php /path/to/enrile/config/cron_leave_notifications.php
```

**Operations:**
1. Marks notifications as `is_sent = 1` if `target_date <= TODAY`
2. Checks for newly approved leaves and creates filing deadline notifications
3. Generates leave ending reminders for active leaves (in progress)

### 3. Notification Display (Employee_portal.php - get_notif_count action)
The existing notification polling system has been enhanced to include leave notifications:
- Queries `leave_notifications` table for unsent notifications that have reached their target date
- Displays with appropriate icons:
  - 📋 for filing deadlines (purple)
  - ⏰ for leave ending reminders (amber)
- Maintains read/unread state using existing notification system
- Appears in employee portal notification bell

## Setup Instructions

### Step 1: Execute Migration SQL
Run the migration SQL file to update the database schema:

```bash
mysql -u [username] -p'[password]' lgu_enrile_hrms < /path/to/enrile/config/migration_leave_notifications.sql
```

Or execute manually in phpMyAdmin:
1. Go to SQL tab
2. Copy contents of `config/migration_leave_notifications.sql`
3. Execute

### Step 2: Configure Cron Job
Add to your server's crontab:

```bash
# Using crontab -e
0 6 * * * cd /var/www/html/enrile && php config/cron_leave_notifications.php >> /var/log/leave_notifications.log 2>&1
```

**For Windows (Task Scheduler):**
1. Create a new scheduled task
2. Action: Start a program
3. Program: `C:\xampp\php\php.exe`
4. Arguments: `C:\xampp\htdocs\enrile\config\cron_leave_notifications.php`
5. Schedule: Daily at 6:00 AM

### Step 3: Verify Installation
1. Check database:
   ```sql
   DESC leave_types;  -- Should show new columns
   DESC leave_notifications;  -- Should exist
   ```

2. Test notification creation:
   - Login as employee
   - Submit a Vacation Leave request for 10 days from now
   - Should see notification in portal notification bell

3. Test cron job (manual execution):
   ```bash
   php config/cron_leave_notifications.php
   ```
   Should display: ✓ Leave notification cron completed successfully.

## Testing Checklist

### Test Case 1: Advance Notice Leave (Vacation)
1. Submit Vacation Leave for 10 days from now
2. Verify: FILING_DEADLINE notification created for 5 days before
3. Verify: LEAVE_ENDING reminder created for 1 day before leave end

### Test Case 2: Same-Day Leave (Sick Leave)
1. Submit Sick Leave for today
2. Verify: NO FILING_DEADLINE notification created (advance_notice_days = 0)
3. Verify: LEAVE_ENDING reminder created for 1 day before leave end

### Test Case 3: Notification Display
1. Submit a leave request
2. Wait for target_date or manually run cron job
3. Check employee portal notification bell
4. Verify notification appears with correct:
   - Title
   - Message
   - Icon (📋 or ⏰)
   - Read/unread state

### Test Case 4: Multiple Leaves
1. Submit multiple overlapping notifications
2. Verify all display in notification bell
3. Verify correct sorting (newest first)
4. Verify read state is tracked per notification

## API Endpoints

### GET get_notif_count
**URL**: `Employee_portal.php?action=get_notif_count`
**Response**: JSON array of all active notifications including leave notifications
**Leave Notification Fields:**
- `icon`: 📋 or ⏰
- `color`: Purple (#8b5cf6) or Amber (#f59e0b)
- `title`: Notification title
- `body`: Full message
- `time`: created_at timestamp
- `page`: 'requests' (navigates to leave requests page)
- `nid`: Unique notification ID for tracking

## Troubleshooting

### Notifications Not Appearing
1. Check if `leave_notifications` table exists:
   ```sql
   SHOW TABLES LIKE '%notification%';
   ```
2. Verify migration was applied successfully
3. Check `advance_notice_days` values in `leave_types`:
   ```sql
   SELECT name, advance_notice_days FROM leave_types;
   ```

### Cron Job Not Running
1. Check if file exists: `config/cron_leave_notifications.php`
2. Verify PHP executable path
3. Check server logs for errors
4. For Windows, verify Task Scheduler has proper permissions

### Duplicate Notifications
The cron job uses `ON DUPLICATE KEY UPDATE` to prevent duplicates. If you see duplicates:
1. Check if table has proper indexes
2. Manually clean duplicates:
   ```sql
   DELETE FROM leave_notifications 
   WHERE notification_id NOT IN (
       SELECT MIN(notification_id) FROM (
           SELECT MIN(notification_id) FROM leave_notifications
           GROUP BY request_id, notification_type
       ) AS tmp
   );
   ```

## Performance Considerations

- Notifications are queried only for current employee
- Index on `(employee_id, is_sent)` optimizes polling queries
- Index on `target_date` helps cron job identify notifications to send
- Cleanup: Archive old notifications periodically (recommended: quarterly)

## Future Enhancements

1. **Email Notifications**: Send email alerts along with portal notifications
2. **SMS Alerts**: Critical deadlines sent via SMS
3. **Escalation**: Auto-escalate if filing deadline passes without submission
4. **Custom Reminders**: Allow employees to customize notification preferences
5. **Mobile App**: Push notifications to employee mobile app

---
**Last Updated**: 2025
**System**: LGU Enrile HRMS
**Version**: 1.0
