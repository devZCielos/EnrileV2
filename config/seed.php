<?php
// ============================================================
//  LGU ENRILE HRMS — Database Seeder
//  File: config/seed.php
//
//  Run ONCE to populate the database with sample data.
//  HOW TO USE:
//    1. Make sure lgu_enrile_hrms.sql has been imported first.
//    2. Open browser: http://localhost/your-project/config/seed.php
//    3. Delete or restrict this file after seeding in production!
// ============================================================

require_once __DIR__ . '/db.php';

// Safety flag — set to true only when you want to re-seed
define('ALLOW_SEED', true);

if (!ALLOW_SEED) {
    die('<b style="color:red;">Seeder is disabled. Set ALLOW_SEED to true in seed.php to run.</b>');
}

$errors  = [];
$inserts = 0;

try {
    $pdo->beginTransaction();

    // ──────────────────────────────────────────
    //  1. DEPARTMENTS
    // ──────────────────────────────────────────
    $departments = [
        ['Finance Office',          'Maria Santos Reyes',    'Handles budgeting, accounting, and financial reporting.',      '💰', '#2563eb'],
        ['Engineering Office',      'Jose Ramon Dela Cruz',  'Manages infrastructure, maintenance, and public works.',       '⚙️',  '#0891b2'],
        ['Admin Office',            'Ana Liza Bautista',     'Handles general administrative operations and records.',       '📋', '#7c3aed'],
        ['Health Office',           'Dr. Pedro Villanueva',  'Provides health services and medical programs.',               '🏥', '#dc2626'],
        ['MSWD',                    'Carmen Gloria Santos',  'Manages social welfare programs and community services.',      '❤️',  '#db2777'],
        ['MPDO',                    'Eng. Roberto Aquino',   'Handles municipal planning and development programs.',         '📐', '#d97706'],
        ['Office of the Mayor',     'Atty. Luz Fernandez',   'Executive office overseeing all LGU operations.',             '🏛️',  '#166534'],
        ['Agriculture Office',      'Ben Andres Castillo',   'Supports farming, fisheries, and agricultural development.',   '🌾', '#15803d'],
    ];

    $stmtDept = $pdo->prepare("
        INSERT IGNORE INTO departments (name, head_name, description, icon, color)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($departments as $d) {
        $stmtDept->execute($d);
        $inserts++;
    }

    // ──────────────────────────────────────────
    //  2. USER ACCOUNTS
    //  Default password for all: Password123!
    //  (bcrypt hash — change after first login)
    // ──────────────────────────────────────────
    $hash = password_hash('Password123!', PASSWORD_BCRYPT);

    $users = [
        // [email, hash, role]
        ['admin@lgu-enrile.gov.ph',       $hash, 'admin'],
        ['hr@lgu-enrile.gov.ph',          $hash, 'hr_staff'],
        ['mreyes@lgu-enrile.gov.ph',      $hash, 'employee'],
        ['jdelacruz@lgu-enrile.gov.ph',   $hash, 'employee'],
        ['abautista@lgu-enrile.gov.ph',   $hash, 'employee'],
        ['pvillanueva@lgu-enrile.gov.ph', $hash, 'employee'],
        ['cgsantos@lgu-enrile.gov.ph',    $hash, 'employee'],
        ['raquino@lgu-enrile.gov.ph',     $hash, 'employee'],
        ['lfernandez@lgu-enrile.gov.ph',  $hash, 'dept_head'],
        ['bcastillo@lgu-enrile.gov.ph',   $hash, 'employee'],
        ['gcruz@lgu-enrile.gov.ph',       $hash, 'employee'],
        ['rlopez@lgu-enrile.gov.ph',      $hash, 'employee'],
        ['emendoza@lgu-enrile.gov.ph',    $hash, 'employee'],
        ['ltorres@lgu-enrile.gov.ph',     $hash, 'employee'],
    ];

    $stmtUser = $pdo->prepare("
        INSERT IGNORE INTO user_accounts (email, password_hash, role)
        VALUES (?, ?, ?)
    ");

    foreach ($users as $u) {
        $stmtUser->execute($u);
        $inserts++;
    }

    // helper: get user_id by email
    $getUserId = function(string $email) use ($pdo): ?int {
        $s = $pdo->prepare("SELECT user_id FROM user_accounts WHERE email = ?");
        $s->execute([$email]);
        $row = $s->fetch();
        return $row ? (int)$row['user_id'] : null;
    };

    // helper: get department_id by name
    $getDeptId = function(string $name) use ($pdo): ?int {
        $s = $pdo->prepare("SELECT department_id FROM departments WHERE name = ?");
        $s->execute([$name]);
        $row = $s->fetch();
        return $row ? (int)$row['department_id'] : null;
    };

    // ──────────────────────────────────────────
    //  3. EMPLOYEES
    // ──────────────────────────────────────────
    $employees = [
        // employee_code, first, middle, last, gender, dob, civil, address, contact, email, dept, position, type, status, sg, date_hired
        ['EMP-001', 'Maria',    'Santos',    'Reyes',      'Female', '1988-04-12', 'Married',  'Brgy. Centro, Enrile, Cagayan',      '09171234567', 'mreyes@lgu-enrile.gov.ph',      'Finance Office',      'Accountant I',              'Permanent',   'Active',   'SG-14', '2015-06-01'],
        ['EMP-002', 'Jose',     'Ramon',     'Dela Cruz',  'Male',   '1980-07-25', 'Married',  'Brgy. Divisoria, Enrile, Cagayan',   '09181234567', 'jdelacruz@lgu-enrile.gov.ph',   'Engineering Office',  'Engineer II',               'Permanent',   'Active',   'SG-16', '2010-03-15'],
        ['EMP-003', 'Ana',      'Liza',      'Bautista',   'Female', '1990-11-03', 'Single',   'Brgy. Mabini, Enrile, Cagayan',      '09191234567', 'abautista@lgu-enrile.gov.ph',   'Admin Office',        'Administrative Officer I',  'Permanent',   'Active',   'SG-11', '2018-01-10'],
        ['EMP-004', 'Pedro',    'Cruz',      'Villanueva', 'Male',   '1975-02-18', 'Married',  'Brgy. Rizal, Enrile, Cagayan',       '09201234567', 'pvillanueva@lgu-enrile.gov.ph', 'Health Office',       'Municipal Health Officer',  'Permanent',   'Active',   'SG-22', '2005-07-01'],
        ['EMP-005', 'Carmen',   'Gloria',    'Santos',     'Female', '1985-09-30', 'Married',  'Brgy. Arellano, Enrile, Cagayan',    '09211234567', 'cgsantos@lgu-enrile.gov.ph',    'MSWD',                'MSWDO',                     'Permanent',   'Active',   'SG-22', '2012-08-20'],
        ['EMP-006', 'Roberto',  'Andres',    'Aquino',     'Male',   '1978-05-14', 'Married',  'Brgy. Bonifacio, Enrile, Cagayan',   '09221234567', 'raquino@lgu-enrile.gov.ph',     'MPDO',                'Municipal Planner',         'Permanent',   'Active',   'SG-18', '2008-04-05'],
        ['EMP-007', 'Luz',      'Garcia',    'Fernandez',  'Female', '1970-12-01', 'Married',  'Brgy. Centro, Enrile, Cagayan',      '09231234567', 'lfernandez@lgu-enrile.gov.ph',  'Office of the Mayor', 'Legal Officer II',          'Permanent',   'Active',   'SG-22', '2000-01-15'],
        ['EMP-008', 'Benito',   'Andres',    'Castillo',   'Male',   '1983-08-22', 'Single',   'Brgy. San Juan, Enrile, Cagayan',    '09241234567', 'bcastillo@lgu-enrile.gov.ph',   'Agriculture Office',  'Agricultural Technologist', 'Permanent',   'Active',   'SG-11', '2014-02-28'],
        ['EMP-009', 'Gloria',   'Reyes',     'Cruz',       'Female', '1993-03-10', 'Single',   'Brgy. Libertad, Enrile, Cagayan',    '09251234567', 'gcruz@lgu-enrile.gov.ph',       'Finance Office',      'Bookkeeper',                'Permanent',   'Active',   'SG-08', '2020-06-15'],
        ['EMP-010', 'Ramon',    'Dela',      'Lopez',      'Male',   '1995-06-17', 'Single',   'Brgy. Magsaysay, Enrile, Cagayan',   '09261234567', 'rlopez@lgu-enrile.gov.ph',      'Engineering Office',  'Engineering Aide',          'Contractual', 'Active',   'SG-06', '2022-01-03'],
        ['EMP-011', 'Elvira',   'Santos',    'Mendoza',    'Female', '1991-01-28', 'Married',  'Brgy. Poblacion, Enrile, Cagayan',   '09271234567', 'emendoza@lgu-enrile.gov.ph',    'Admin Office',        'Clerk II',                  'Permanent',   'Active',   'SG-06', '2016-09-01'],
        ['EMP-012', 'Luis',     'Miguel',    'Torres',     'Male',   '1987-10-05', 'Married',  'Brgy. San Pedro, Enrile, Cagayan',   '09281234567', 'ltorres@lgu-enrile.gov.ph',     'Health Office',       'Nurse II',                  'Permanent',   'On Leave', 'SG-15', '2013-11-11'],
    ];

    $stmtEmp = $pdo->prepare("
        INSERT IGNORE INTO employees
          (employee_code, first_name, middle_name, last_name, gender, date_of_birth,
           civil_status, home_address, contact_number, email_address,
           department_id, position_title, employment_type, employment_status,
           salary_grade, date_hired, user_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    foreach ($employees as $e) {
        $deptId = $getDeptId($e[10]);
        $userId = $getUserId($e[9]);
        $stmtEmp->execute([
            $e[0],  // employee_code
            $e[1],  // first_name
            $e[2],  // middle_name
            $e[3],  // last_name
            $e[4],  // gender
            $e[5],  // date_of_birth
            $e[6],  // civil_status
            $e[7],  // home_address
            $e[8],  // contact_number
            $e[9],  // email_address
            $deptId,
            $e[11], // position_title
            $e[12], // employment_type
            $e[13], // employment_status
            $e[14], // salary_grade
            $e[15], // date_hired
            $userId,
        ]);
        $inserts++;
    }

    // helper: get employee_id by code
    $getEmpId = function(string $code) use ($pdo): ?int {
        $s = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_code = ?");
        $s->execute([$code]);
        $row = $s->fetch();
        return $row ? (int)$row['employee_id'] : null;
    };

    // ──────────────────────────────────────────
    //  4. UPDATE department employee_count
    // ──────────────────────────────────────────
    $pdo->exec("
        UPDATE departments d
        SET employee_count = (
            SELECT COUNT(*) FROM employees e
            WHERE e.department_id = d.department_id
              AND e.employment_status != 'Resigned'
              AND e.employment_status != 'Retired'
        )
    ");

    // ──────────────────────────────────────────
    //  5. LEAVE TYPES
    // ──────────────────────────────────────────
    $leaveTypes = [
        ['Vacation Leave',                    'VL',   15.00, 'Annual vacation leave credits per CSC rules (Sec. 51, Rule XVI).'],
        ['Mandatory/Forced Leave',            'MFL',   5.00, 'Annual five-day mandatory leave (Sec. 25, Rule XVI).'],
        ['Sick Leave',                        'SL',   15.00, 'Leave for illness or medical appointments (Sec. 43, Rule XVI).'],
        ['Maternity Leave',                   'ML',  105.00, 'Maternity leave per RA 11210 (105 days).'],
        ['Paternity Leave',                   'PL',    7.00, 'Paternity leave per RA 8187 (7 days).'],
        ['Special Privilege Leave',           'SPL',   3.00, 'Special privilege leave per CSC rules (Sec. 21, Rule XVI).'],
        ['Solo Parent Leave',                 'SOLO',  7.00, 'Solo parent leave per RA 8972 (7 days).'],
        ['Study Leave',                       'STL',   6.00, 'Study leave per CSC rules (up to 6 months).'],
        ['10-Day VAWC Leave',                 'VAWC', 10.00, 'VAWC leave per RA 9262 (10 days).'],
        ['Rehabilitation Privilege',          'RL',    6.00, 'Rehabilitation privilege per CSC rules (up to 6 months).'],
        ['Special Leave Benefits for Women',  'SLBW',  2.00, 'Special leave benefits for women per RA 9710 (up to 2 months).'],
        ['Special Emergency (Calamity) Leave','ECL',   5.00, 'Special emergency/calamity leave per CSC MC No. 2, s. 2012 (up to 5 days).'],
        ['Adoption Leave',                    'AL',    6.00, 'Adoption leave per RA 8552.'],
        ['Emergency Leave',                   'EL',    3.00, 'Leave for unforeseen personal emergencies.'],
    ];

    $stmtLT = $pdo->prepare("
        INSERT IGNORE INTO leave_types (name, code, max_days, description)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($leaveTypes as $lt) {
        $stmtLT->execute($lt);
        $inserts++;
    }

    // helper: get leave_type_id by code
    $getLTId = function(string $code) use ($pdo): ?int {
        $s = $pdo->prepare("SELECT leave_type_id FROM leave_types WHERE code = ?");
        $s->execute([$code]);
        $row = $s->fetch();
        return $row ? (int)$row['leave_type_id'] : null;
    };

    // ──────────────────────────────────────────
    //  6. LEAVE BALANCES  (CY 2025)
    // ──────────────────────────────────────────
    $year     = 2025;
    $empCodes = ['EMP-001','EMP-002','EMP-003','EMP-004','EMP-005',
                 'EMP-006','EMP-007','EMP-008','EMP-009','EMP-010',
                 'EMP-011','EMP-012'];
    $ltCodes  = ['VL','SL','EL','SPL'];

    // Credits and sample used days per employee code
    $usedDays = [
        'EMP-001' => ['VL' => 0,  'SL' => 2,  'EL' => 0, 'SPL' => 0],
        'EMP-002' => ['VL' => 3,  'SL' => 1,  'EL' => 1, 'SPL' => 0],
        'EMP-003' => ['VL' => 5,  'SL' => 0,  'EL' => 0, 'SPL' => 2],
        'EMP-004' => ['VL' => 2,  'SL' => 0,  'EL' => 0, 'SPL' => 0],
        'EMP-005' => ['VL' => 0,  'SL' => 3,  'EL' => 2, 'SPL' => 0],
        'EMP-006' => ['VL' => 4,  'SL' => 1,  'EL' => 0, 'SPL' => 1],
        'EMP-007' => ['VL' => 1,  'SL' => 0,  'EL' => 0, 'SPL' => 0],
        'EMP-008' => ['VL' => 0,  'SL' => 2,  'EL' => 1, 'SPL' => 0],
        'EMP-009' => ['VL' => 2,  'SL' => 1,  'EL' => 0, 'SPL' => 0],
        'EMP-010' => ['VL' => 0,  'SL' => 0,  'EL' => 0, 'SPL' => 0],
        'EMP-011' => ['VL' => 3,  'SL' => 2,  'EL' => 1, 'SPL' => 0],
        'EMP-012' => ['VL' => 10, 'SL' => 5,  'EL' => 3, 'SPL' => 2],
    ];

    $credits = ['VL' => 15, 'SL' => 15, 'EL' => 3, 'SPL' => 5];

    $stmtBal = $pdo->prepare("
        INSERT IGNORE INTO leave_balances (employee_id, leave_type_id, calendar_year, total_credits, used_days)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($empCodes as $code) {
        $empId = $getEmpId($code);
        if (!$empId) continue;
        foreach ($ltCodes as $ltCode) {
            $ltId  = $getLTId($ltCode);
            $used  = $usedDays[$code][$ltCode] ?? 0;
            $total = $credits[$ltCode];
            $stmtBal->execute([$empId, $ltId, $year, $total, $used]);
            $inserts++;
        }
    }

    // ──────────────────────────────────────────
    //  7. LEAVE REQUESTS
    // ──────────────────────────────────────────
    $adminId = $getUserId('admin@lgu-enrile.gov.ph');

    $requests = [
        // [emp_code, lt_code, from, to, days, reason, status, remark]
        ['EMP-001', 'VL',  '2025-03-10', '2025-03-12', 3, 'Family vacation trip.',                    'Pending',  null],
        ['EMP-001', 'EL',  '2025-03-25', '2025-03-25', 1, 'Family emergency matters.',                'Pending',  null],
        ['EMP-001', 'SL',  '2025-02-03', '2025-02-03', 1, 'Flu symptoms and rest.',                   'Approved', 'Approved. Get well soon!'],
        ['EMP-001', 'VL',  '2025-01-06', '2025-01-07', 2, 'New year holiday.',                        'Approved', 'Approved. Enjoy!'],
        ['EMP-001', 'SL',  '2025-01-14', '2025-01-14', 1, 'Fever and headache.',                      'Approved', 'Approved.'],
        ['EMP-001', 'VL',  '2024-12-23', '2024-12-24', 2, 'Christmas holiday.',                       'Denied',   'Denied — skeleton crew required during holidays.'],
        ['EMP-002', 'VL',  '2025-02-17', '2025-02-19', 3, 'Rest and recreation.',                     'Approved', 'Approved.'],
        ['EMP-003', 'SL',  '2025-01-20', '2025-01-20', 1, 'Dental appointment.',                      'Approved', 'Approved.'],
        ['EMP-004', 'VL',  '2025-03-05', '2025-03-06', 2, 'Personal matters.',                        'Pending',  null],
        ['EMP-005', 'EL',  '2025-02-28', '2025-03-01', 2, 'Family emergency.',                        'Approved', 'Approved.'],
        ['EMP-006', 'VL',  '2025-01-13', '2025-01-16', 4, 'Rest and family time.',                    'Approved', 'Approved.'],
        ['EMP-012', 'SL',  '2025-02-10', '2025-02-14', 5, 'Medical condition requiring bed rest.',    'Approved', 'Approved. Please submit medical certificate upon return.'],
        ['EMP-009', 'VL',  '2025-03-17', '2025-03-18', 2, 'Personal trip.',                           'Pending',  null],
        ['EMP-011', 'EL',  '2025-03-20', '2025-03-20', 1, 'Child hospitalization.',                   'Pending',  null],
    ];

    $stmtReq = $pdo->prepare("
        INSERT INTO leave_requests
          (employee_id, leave_type_id, date_from, date_to, days_requested,
           reason, status, admin_remarks, reviewed_by, reviewed_at, filed_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");

    foreach ($requests as $r) {
        $empId   = $getEmpId($r[0]);
        $ltId    = $getLTId($r[1]);
        $status  = $r[6];
        $remark  = $r[7];
        $revBy   = in_array($status, ['Approved','Denied']) ? $adminId : null;
        $revAt   = in_array($status, ['Approved','Denied']) ? date('Y-m-d H:i:s', strtotime($r[2] . ' +1 day')) : null;
        $filedAt = date('Y-m-d H:i:s', strtotime($r[2] . ' -3 days'));

        $stmtReq->execute([$empId, $ltId, $r[2], $r[3], $r[4], $r[5], $status, $remark, $revBy, $revAt, $filedAt]);
        $inserts++;
    }

    // ──────────────────────────────────────────
    //  8. ANNOUNCEMENTS
    // ──────────────────────────────────────────
    $announcements = [
        ['Leave Filing Deadline',   'April leave requests must be filed on or before March 28, 2025 to ensure timely processing.',     'INFO',    $adminId],
        ['Updated Leave Policy',    'New CSC sick leave monetization rules are effective April 1, 2025. Please review the circular.',  'URGENT',  $adminId],
        ['System Maintenance',      'The HR system will be offline on March 10, 2025 from 6:00 PM to 9:00 PM for maintenance.',       'NOTICE',  $adminId],
        ['Service Awards Ceremony', 'Annual service awards scheduled for April 15. All employees are encouraged to attend.',           'INFO',    $adminId],
    ];

    $stmtAnn = $pdo->prepare("
        INSERT INTO announcements (title, body, tag, posted_by) VALUES (?,?,?,?)
    ");

    foreach ($announcements as $a) {
        $stmtAnn->execute($a);
        $inserts++;
    }

    // ──────────────────────────────────────────
    //  9. ACTIVITY LOGS (sample)
    // ──────────────────────────────────────────
    $logs = [
        [$adminId, 'Approved leave request for Maria Reyes (Sick Leave)',        'Leave Requests', null],
        [$adminId, 'Added new employee: Ramon Dela Lopez (EMP-010)',             'Employees',      null],
        [$adminId, 'Posted announcement: Leave Filing Deadline',                 'Announcements',  null],
        [$adminId, 'Denied leave request for Maria Reyes (Vacation Leave)',      'Leave Requests', null],
        [$adminId, 'Updated department: Finance Office',                         'Departments',    null],
    ];

    $stmtLog = $pdo->prepare("
        INSERT INTO activity_logs (user_id, action, module, reference_id) VALUES (?,?,?,?)
    ");

    foreach ($logs as $l) {
        $stmtLog->execute($l);
        $inserts++;
    }

    $pdo->commit();

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Seeder — LGU Enrile HRMS</title>
    <style>
      body{font-family:sans-serif;background:#f0fdf4;color:#166534;padding:40px;max-width:600px;margin:auto;}
      h2{margin-bottom:16px;}
      .box{background:#dcfce7;border:1.5px solid #86efac;border-radius:10px;padding:20px 24px;}
      .warn{background:#fef3c7;border:1.5px solid #fcd34d;border-radius:10px;padding:14px 20px;margin-top:18px;color:#92400e;}
      ul{margin:10px 0 0 18px;line-height:2;}
    </style></head><body>
    <h2>✅ LGU Enrile HRMS — Seeder Complete</h2>
    <div class="box">
      <strong>' . $inserts . ' records inserted successfully.</strong>
      <ul>
        <li>8 Departments</li>
        <li>14 User Accounts</li>
        <li>12 Employees</li>
        <li>6 Leave Types</li>
        <li>48 Leave Balances (CY 2025)</li>
        <li>14 Leave Requests</li>
        <li>4 Announcements</li>
        <li>5 Activity Logs</li>
      </ul>
    </div>
    <div class="warn">
      ⚠️ <strong>Security reminder:</strong> Delete or restrict access to <code>seed.php</code> after seeding.<br><br>
      Default login for all accounts: <code>Password123!</code><br>
      Admin: <code>admin@lgu-enrile.gov.ph</code><br>
      Employee (Maria Reyes): <code>mreyes@lgu-enrile.gov.ph</code>
    </div>
    </body></html>';

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo '<pre style="color:red;"><b>Seeder FAILED:</b> ' . htmlspecialchars($e->getMessage()) . '</pre>';
}