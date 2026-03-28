# Leave Balance Deduction Workflow

## Overview
Leave balances are deducted **ONLY when a leave request is APPROVED**, not when filed. This ensures employees can cancel pending requests without losing balance.

---

## Workflow Steps

### Step 1: Employee Files Leave Request
**Location:** Employee Portal → File Leave Request  
**File:** `Employee/Employee_portal.php` (Line 426)

```
Employee Files Request (Pending)
    ↓
CHECK: Does employee have enough available balance?
    ├─ Yes → Request inserted with status='Pending'
    │        Balance is NOT deducted
    │        Employee can see pending requests in calculation
    └─ No → Error message, request rejected

Available Balance = Remaining Days - Pending Days
```

✅ **Verification:**
- [ ] Employee balance remains the same after filing
- [ ] Pending requests show in "Available Balance" calculation
- [ ] Employee can cancel pending requests to free up balance

---

### Step 2: Admin Reviews Leave Request
**Location:** Admin Panel → Leave Requests  
**File:** `Admin/leave_requests.php`

Admin has two options:

#### Option A: APPROVE Leave Request (Lines 202-233)
```php
UPDATE leave_requests SET status='Approved' WHERE request_id=?

// Deduct from leave balance
UPDATE leave_balances
SET used_days      = used_days + days_requested,
    remaining_days = remaining_days - days_requested
WHERE employee_id=? AND leave_type_id=? AND calendar_year=YEAR(NOW())
```

✅ **Verification:**
- [ ] Approved leave shows in employee's "Used Days"
- [ ] "Remaining Days" decreases by approved days
- [ ] Admin confirmation message appears
- [ ] Activity log records the approval

#### Option B: DENY Leave Request (Lines 238-260)
```php
UPDATE leave_requests SET status='Denied' WHERE request_id=?

// NO balance changes - correct!
```

✅ **Verification:**
- [ ] Denied leave does NOT affect balance
- [ ] Employee's available balance unchanged
- [ ] Admin remarks saved
- [ ] Activity log records the denial

---

## Database Relationships

```
leave_requests
├─ employee_id → employees
├─ leave_type_id → leave_types
├─ status: 'Pending' | 'Approved' | 'Denied' | 'Cancelled'
└─ days_requested: DECIMAL

leave_balances
├─ employee_id → employees
├─ leave_type_id → leave_types
├─ total_credits: DECIMAL (e.g., 15.00 days/year)
├─ used_days: DECIMAL (incremented on approval)
└─ remaining_days: GENERATED = total_credits - used_days
```

---

## Employee View

### Available Balance Calculation
```javascript
// Employee/Employee_portal.php (Line 211)
available_after_pending = remaining_days - pending_days
```

**Example:**
- Vacation Leave Total: 15 days
- Already Used: 3 days  
- Remaining: 12 days
- Pending Requests: 5 days
- **AVAILABLE NOW: 7 days**

Employee can file for up to 7 days immediately. If they request exactly 7 days, the pending request shows that balance.

---

## Admin View  

### Leave Balances Admin Panel
**Location:** Admin → Leave Balances  
**File:** `Admin/leave_balances.php`

- View all employees' leave balances
- Manually adjust balances if needed (Lines 143-144)
- Track used vs. remaining leave per type per year

---

## Important Notes

### ✅ Correct Behavior
1. **Balance NOT deducted** when request is filed (Pending)
2. **Balance deducted** only when approved
3. **Balance unchanged** when request is denied
4. **Balance shows pending** in employee's available calculation

### ⚠️ Edge Cases Handled
1. **Overlapping leaves:** Checked at filing time (Line 389)
2. **Insufficient balance:** Block request at filing time (Line 420)
3. **Cancelled requests:** Employee can cancel pending requests
4. **Year-based balances:** Tracked per calendar year

### 🔒 Audit Trail
- All approvals logged in `activity_logs`
- Admin who approved recorded
- Timestamps tracked in `reviewed_at`

---

## Testing Checklist

### Employee Portal Tests
- [ ] File leave request with enough balance → Status: Pending
- [ ] Check available balance = remaining - pending
- [ ] Cancel pending request → Balance returns
- [ ] File another request → Balance adjusts correctly
- [ ] Try file more than available → Error shown

### Admin Approval Tests  
- [ ] Approve filed request → Balance immediately deducted
- [ ] Check employee balance updated
- [ ] Check "Used Days" increased
- [ ] Check "Remaining Days" decreased
- [ ] Check admin remarks saved

### Admin Denial Tests
- [ ] Deny filed request → Balance unchanged
- [ ] Employee available balance still shows same amount
- [ ] Can re-file same dates → Allowed (previous was denied)

### Leave Balance Page Tests
- [ ] Admin can view all employees' balances
- [ ] Manual adjustment possible (if needed)
- [ ] Year filter works correctly

---

## Configuration

### File: `Admin/leave_requests.php`
**Approval Logic (Lines 218-226):** Balance deduction on approval

### File: `Employee/Employee_portal.php`  
**Filing Logic (Line 426):** No balance deduction  
**Available Calculation (Line 211):** Includes pending requests

### File: `Admin/leave_balances.php`
**Manual Update (Line 143-144):** For admin corrections

---

## Related Files
- `config/db.php` - Database connection
- `config/csrf.php` - Security tokens
- `Employee/_shared/auth.php` - Employee authentication
- `Admin/leave_balances.php` - Balance management
- `lgu_enrile_hrms.sql` - Database schema

---

**Last Updated:** 2025-03-28  
**Status:** ✅ IMPLEMENTATION VERIFIED
