# LGU Enrile HRMS — HR System Audit Overview

**Audited by:** HR Head, Municipality of Enrile, Cagayan
**Audit Date:** May 4, 2026
**System:** LGU Enrile Human Resource Management System (HRMS) v2
**Stack:** PHP / MySQL (XAMPP), Vanilla JS, Chart.js

---

## Executive Summary

The LGU Enrile HRMS is a custom-built PHP/MySQL system covering employee management, leave workflow, contracts, PDS (CS Form 212), SALN, service records, documents, announcements, and direct messaging. It is **functionally promising but not yet production-safe.** Multiple critical security vulnerabilities, business logic gaps, and data integrity risks must be resolved before the system can be trusted to process official government HR transactions.

---

## System Modules Covered

| Module | Admin | Employee |
|--------|-------|----------|
| Dashboard | ✅ | ✅ |
| Employee Roster | ✅ | — |
| Leave Requests | ✅ | ✅ |
| Leave Balances | ✅ | ✅ |
| Leave Types | ✅ | — |
| Contracts | ✅ | — |
| Departments | ✅ | — |
| User Accounts | ✅ | — |
| Reports | ✅ | — |
| PDS (CS Form 212) | ✅ view | ✅ edit |
| SALN (2025 Form) | ✅ view | ✅ edit |
| Service Records | ✅ | ✅ request |
| Documents | ✅ serve | ✅ upload |
| Announcements | ✅ | ✅ view |
| Chat / Messaging | ✅ | ✅ |
| Archives | ✅ | — |

---

## I. Strengths

| Area | Details |
|------|---------|
| **Leave Workflow** | Multi-stage approval (Employee → Dept Head → Admin/HR) — correct for government offices |
| **Leave Balance Tracking** | Auto-generated per employee per year; `remaining_days` is a GENERATED STORED column (no drift) |
| **PDS Coverage** | Full CS Form 212 (Revised 2025) — all sections including government IDs, Q4 questions, references |
| **SALN Integration** | 2025 SALN form with real/personal property schedules and live net worth calculation |
| **Service Records** | Full government employment history with print support |
| **Document Upload Security** | Uses `finfo` MIME detection and `random_bytes` for filenames |
| **Password Security** | `password_hash(PASSWORD_BCRYPT)` — plaintext passwords never stored |
| **Session Fixation Prevention** | `session_regenerate_id(true)` on every login |
| **Password Reset** | Token SHA-256 hashed in DB; raw token never stored; constant-time comparison via `hash_equals()` |
| **Contract Lifecycle** | Active → Expired → Renewed for JO/Contractual/Casual employees |
| **Archive Module** | Employee separation is reversible with full audit logging |
| **Chat System** | Direct messaging with edit, unsend, and conversation-delete features |
| **CSRF Foundation** | Infrastructure exists (`csrf.php`) with cryptographically secure token generation |
| **Announcements** | Priority levels, audience targeting, pinning, draft/published/archived states |

---

## II. Critical Security Issues (Fix Immediately)

### 🔴 CRITICAL-1 — `seed.php` is Publicly Accessible
`config/seed.php` has `ALLOW_SEED = true` and is browser-accessible. Any visitor can wipe and reset the entire database and log in with the known default password `Password123!`.

**Fix:** Delete the file or add `deny from all` to `.htaccess` inside `config/`.

---

### 🔴 CRITICAL-2 — `whoami` Endpoint Exposes All Employee Records
`Employee/Employee_portal.php` has a live `?action=whoami` endpoint returning all employee names, IDs, user IDs, and emails to any logged-in employee.

**Fix:** Remove this endpoint entirely.

---

### 🔴 CRITICAL-3 — Brute-Force Lockout Is Trivially Bypassed
Login attempt counters are stored in `$_SESSION`. Opening a new incognito tab gives a fresh counter — unlimited password guesses are allowed.

**Fix:** Store `failed_attempts` and `lockout_until` in the `user_accounts` database table.

---

### 🔴 CRITICAL-4 — `display_errors = 1` in Production Code
`Employee/Employee_portal.php` has `ini_set('display_errors', 1)`. PHP errors leak SQL queries, file paths, and server internals to end users.

**Fix:** Remove this line entirely.

---

### 🟠 HIGH-5 — Plaintext Password Sent via Email
`Admin/user_accounts.php` emails the raw plaintext password when creating or resetting an account. If the email is intercepted, the credential is permanently compromised.

**Fix:** Email a one-time password reset link only. Enforce password change on first login.

---

### 🟠 HIGH-6 — Host Header Injection in Password Reset
`Login/process_forgot_password.php` builds the reset URL using `$_SERVER['HTTP_HOST']`. A forged `Host:` header can redirect the reset token to an attacker's server.

**Fix:** Use a hardcoded `APP_URL` constant (e.g., `define('APP_URL', 'http://192.168.x.x/enrilecagayann_V2')`).

---

### 🟠 HIGH-7 — No CSRF on Chat API POST Actions
`Admin/chat_api.php` — `send`, `edit`, `unsend`, and `delete_conversation` have no CSRF verification. Cross-site requests can send messages or delete conversations as the logged-in user.

**Fix:** Add `csrf_verify(true)` to all POST actions in `chat_api.php`.

---

### 🟠 HIGH-8 — Raw Reset Token Stored in Session
`Login/process_forgot_password.php` stores `$_SESSION['reset_link']` with the raw reset token — a development convenience that must not ship to production.

**Fix:** Remove entirely or gate behind `APP_ENV === 'local'`.

---

### 🟠 HIGH-9 — Role Value Not Validated on Account Save
`Admin/user_accounts.php` accepts any string as `role` from POST without validation. A crafted request can create an account with an arbitrary role string.

**Fix:** Validate against `['admin','hr_staff','dept_head','employee']` using `in_array()`.

---

## III. Business Logic Problems

### Leave Management

| # | Issue | File |
|---|-------|------|
| L-1 | Balance deducted using `YEAR(NOW())` not `YEAR(date_from)` — wrong year deducted for year-boundary leaves | `Admin/leave_requests.php` |
| L-2 | No overlap detection on approval — two leaves for the same employee on the same dates can both be approved | `Admin/leave_requests.php` |
| L-3 | No balance sufficiency check on approval — remaining days can go negative | `Admin/leave_requests.php` |
| L-4 | Leave day count includes weekends and holidays — government offices use business days | `Employee/leave_file.php` |
| L-5 | Gender restriction on leave type not enforced at submission | `Employee/leave_file.php` |
| L-6 | Employee cannot cancel a "Pending Dept Head" request — only exact `"Pending"` status is cancellable | `Employee/leave_requests.php` |
| L-7 | Leave report does not exclude soft-deleted requests | `Admin/reports.php` |

### Employee Records

| # | Issue |
|---|-------|
| E-1 | Hard delete removes all employee data permanently with no archive step; orphaned contract rows remain |
| E-2 | No duplicate name check — two employees with identical names can be created |
| E-3 | Separated employees invisible in PDS, SALN, and Service Record admin views |
| E-4 | `departments.head_name` is free-text, not a foreign key — goes stale if the head is reassigned or separated |
| E-5 | `departments.employee_count` is a stored integer with no trigger — drifts from the actual headcount |

### Contracts

| # | Issue |
|---|-------|
| C-1 | No validation that `end_date > start_date` |
| C-2 | Multiple Active contracts can coexist for the same employee simultaneously |
| C-3 | Contract auto-expiry only triggers when someone visits the Contracts page, not proactively |
| C-4 | `contracts` table is absent from the master `lgu_enrile_hrms.sql` schema file |

### Reports

| # | Issue |
|---|-------|
| R-1 | No CSV/Excel/PDF export — browser print only; insufficient for CSC, DILG, and BIR submissions |
| R-2 | No pagination on the leave report — all rows returned at once; large datasets will be slow |
| R-3 | Correlated subquery for `credits_left` is O(n) per row |

### PDS / SALN

| # | Issue |
|---|-------|
| P-1 | Admin cannot create or correct a PDS/SALN on behalf of an employee |
| P-2 | SALN net worth is never written to the database — only computed client-side |
| P-3 | No PDS/SALN version history or audit trail |
| P-4 | Sensitive government IDs (TIN, PhilSys, PAG-IBIG, PhilHealth) stored as plaintext JSON blobs |

### Announcements

| # | Issue |
|---|-------|
| A-1 | `dept_head` can publish URGENT system-wide announcements — no publishing gate by role |
| A-2 | Audience targeting is stored but never enforced when delivering announcements to employees |
| A-3 | Expiry dates are stored but no scheduled job auto-archives expired announcements |

---

## IV. Data Integrity Issues

| # | Issue |
|---|-------|
| D-1 | `sanitize()` calls `htmlspecialchars()` before DB inserts — names stored as `O&#039;Brien` instead of `O'Brien` |
| D-2 | `announcements` schema in the SQL file is incompatible with the runtime schema PHP creates (8 missing columns) |
| D-3 | `leave_notifications` table is absent from the master schema file |
| D-4 | `service_records.designation` and `status` are free-text — reporting inconsistency ("Casual" vs "casual" vs "CASUAL") |
| D-5 | Profile photo orphaned files when a re-upload uses a different file extension |
| D-6 | `email_address` update in employee profile does not sync `user_accounts.email` — login desynchronized |
| D-7 | Chat `edited_at` column exists in the schema but is never written when messages are edited |

---

## V. Missing Features / Gaps

| Priority | Gap |
|----------|-----|
| 🔴 Urgent | **Payroll module** — no computation, payslip, or statutory deduction tracking (GSIS, PhilHealth, Pag-IBIG, BIR withholding tax) |
| 🔴 Urgent | **Holiday calendar** — no `holidays` table; leave day count cannot exclude non-working days |
| 🟠 High | **Report export (CSV/Excel/PDF)** — required for CSC, DILG, and BIR reportorial submissions |
| 🟠 High | **Attendance / DTR module** — no daily time records or biometric integration |
| 🟠 High | **Forced password change on first login** for all newly created accounts |
| 🟠 High | **Two-factor authentication** — CSC recommendation for systems handling PDS and SALN data |
| 🟠 High | **Admin-side PDS/SALN data entry** on behalf of employees who cannot access the portal |
| 🟡 Medium | **Leave filing for JO/Contractual/Casual employees** — absences currently untracked |
| 🟡 Medium | **Historical leave balance view** — employees can only see the current year |
| 🟡 Medium | **Notification on SR request approval/denial** to employee |
| 🟡 Medium | **SMTP email configuration** — `mail()` on XAMPP/shared hosting is unreliable |
| 🟡 Medium | **Performance evaluation (IPCR/OPCR) module** |
| 🟡 Medium | **Leave monetization / terminal leave computation** — required for retirement processing |
| 🟡 Low | **Plantilla position management** — positions are free-text strings, not tracked against an approved plantilla |
| 🟡 Low | **Training records module** — CSC requires training hours for promotion eligibility |

---

## VI. Priority Action Plan

### Phase 1 — Immediate (Before Going Live)

1. Delete or block `config/seed.php` from web access
2. Remove `?action=whoami` from `Employee/Employee_portal.php`
3. Remove `ini_set('display_errors', 1)` from `Employee/Employee_portal.php`
4. Move brute-force lockout counters to the `user_accounts` database table
5. Replace `$_SERVER['HTTP_HOST']` in password reset with a hardcoded `APP_URL`
6. Remove `$_SESSION['reset_link']` from `Login/process_forgot_password.php`
7. Add `csrf_verify(true)` to all `Admin/chat_api.php` POST actions
8. Validate `role` against allowed set in `Admin/user_accounts.php`
9. Stop emailing plaintext passwords; enforce first-login password change

### Phase 2 — Short-Term (Within 1 Month)

10. Add a `holidays` table and implement business-day leave day calculation
11. Enforce gender restriction check on leave submission (`Employee/leave_file.php`)
12. Add overlap check and balance sufficiency check before admin leave approval
13. Fix `YEAR(NOW())` → `YEAR(date_from)` in leave balance deduction logic
14. Allow cancellation of "Pending Dept Head" / "Pending HR" leave requests
15. Replace `sanitize()` HTML-escape pattern with parameterized queries + output-time escaping
16. Sync `email_address` update to `user_accounts.email`
17. Validate `end_date > start_date` on contract save
18. Enforce announcement audience targeting in employee-facing queries
19. Write SALN net worth back to the database on every save
20. Add `contracts` table DDL to `lgu_enrile_hrms.sql`

### Phase 3 — Medium-Term (Within 3 Months)

21. Implement report export — CSV minimum for leave report and employee roster
22. Add admin-side PDS/SALN entry form and print functionality
23. Configure SMTP (PHPMailer or Symfony Mailer) to replace `mail()`
24. Add `Content-Security-Policy` header to all authentication guards
25. Enforce `Secure`, `HttpOnly`, and `SameSite=Strict` on session cookies via `session_set_cookie_params()`
26. Add database index on `user_accounts.remember_token` and `documents.employee_id`
27. Add audit log entries for all leave balance manual adjustments
28. Make separated/archived employees visible in admin PDS/SALN/Service Record views
29. Refactor all runtime `CREATE TABLE IF NOT EXISTS` / `ALTER TABLE` in PHP into versioned SQL migration files

### Phase 4 — Long-Term (Roadmap)

30. Payroll computation and payslip generation module
31. Daily Time Record (DTR) / Attendance module with biometric integration
32. Performance evaluation (IPCR/OPCR) module
33. Training and development records module
34. Two-factor authentication
35. Leave monetization and terminal leave computation
36. Approved Plantilla position management

---

## VII. Overall Assessment

| Dimension | Rating | Remarks |
|-----------|--------|---------|
| Functional Coverage | **6 / 10** | Core HR workflows present; payroll, attendance, and IPCR are absent |
| Security | **3 / 10** | Multiple critical vulnerabilities — not safe for production use |
| Data Integrity | **5 / 10** | Balance tracking is solid; schema drift and HTML-encoding corruption are serious |
| Usability / UI | **7 / 10** | Modern, well-structured UI; mobile experience not verified |
| CSC / DILG Compliance | **4 / 10** | PDS/SALN/SR forms present but no exports, no payroll, no 2FA |

> **Verdict:** The LGU Enrile HRMS is a solid foundation with a modern UI and correct HR workflow concepts. However, it **must not be deployed to production** until all Phase 1 security items are resolved. Phase 2 business logic corrections are required before the system produces reliable official records. Phase 3 hardening and Phase 4 features will bring it to full CSC/DILG compliance.

---

*Audit conducted by reviewing all PHP modules, the database schema (`lgu_enrile_hrms.sql`), authentication layer, session handling, CSRF protection, and frontend logic as of May 4, 2026.*
