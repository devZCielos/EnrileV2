<?php
// ============================================================
//  LGU ENRILE HRMS — File Leave Request
//  File: Employee/leave_file.php
//  IMPORTANT: Restricted to Permanent employees only
// ============================================================
require_once __DIR__ . '/_shared/auth.php';
require_once __DIR__ . '/_shared/helpers.php';

$_isAjax = !empty($_GET['action']) || !empty($_POST['action']);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    ob_start();
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(true); }

    try {
        switch ($action) {
            case 'leave_types':
                // Only if Permanent
                if (!isLeaveFeatureAvailable($employmentType)) {
                    echo json_encode(['success' => false, 'message' => 'Leave filing is restricted to permanent employees only.']);
                    break;
                }
                $stmt = $pdo->prepare("
                    SELECT leave_type_id, name, max_days, gender_restriction
                    FROM leave_types
                    WHERE status = 'Active'
                    ORDER BY name ASC
                ");
                $stmt->execute();
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                break;

            case 'balance':
                // Only if Permanent
                if (!isLeaveFeatureAvailable($employmentType)) {
                    echo json_encode(['success' => false, 'message' => 'Not available for your employment type.']);
                    break;
                }
                $typeId = (int)($_GET['type_id'] ?? 0);
                $stmt = $pdo->prepare("
                    SELECT lb.*, lt.name, lt.gender_restriction
                    FROM leave_balances lb
                    JOIN leave_types lt ON lt.leave_type_id = lb.leave_type_id
                    WHERE lb.employee_id = ? AND lb.leave_type_id = ?
                ");
                $stmt->execute([$employeeId, $typeId]);
                $balance = $stmt->fetch();
                if ($balance) {
                    echo json_encode(['success' => true, 'data' => $balance]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Leave type not found.']);
                }
                break;

            case 'submit_leave':
                // ===== CRITICAL: EMPLOYMENT TYPE CHECK =====
                if (!isLeaveFeatureAvailable($employmentType)) {
                    echo json_encode(['success' => false, 'message' => 'Only permanent employees can file leave requests.']);
                    break;
                }
                
                $typeId = (int)($_POST['type_id'] ?? 0);
                $dateFrom = sanitize($_POST['date_from'] ?? '');
                $dateTo = sanitize($_POST['date_to'] ?? '');
                $remarks = sanitize($_POST['remarks'] ?? '');

                if (empty($typeId) || empty($dateFrom) || empty($dateTo)) {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
                    break;
                }

                $from = strtotime($dateFrom);
                $to = strtotime($dateTo);
                if ($from === false || $to === false || $from > $to) {
                    echo json_encode(['success' => false, 'message' => 'Invalid date range.']);
                    break;
                }

                // P2-4 — Count working days only (excludes weekends + holidays table)
                $daysRequested = countBusinessDays($pdo, $dateFrom, $dateTo);
                if ($daysRequested < 1) {
                    echo json_encode(['success' => false, 'message' => 'The selected date range contains no working days.']);
                    break;
                }

                // P2-5 — Enforce gender restriction on leave type
                $ltStmt = $pdo->prepare("SELECT gender_restriction FROM leave_types WHERE leave_type_id=?");
                $ltStmt->execute([$typeId]);
                $lt = $ltStmt->fetch();
                if ($lt && !empty($lt['gender_restriction']) && $lt['gender_restriction'] !== 'Any') {
                    // Fetch employee's sex from the employees table
                    $sexStmt = $pdo->prepare("SELECT sex FROM employees WHERE employee_id=?");
                    $sexStmt->execute([$employeeId]);
                    $empSex = $sexStmt->fetchColumn();
                    if ($empSex && strtolower($empSex) !== strtolower($lt['gender_restriction'])) {
                        echo json_encode(['success' => false, 'message' => 'This leave type is restricted to ' . $lt['gender_restriction'] . ' employees only.']);
                        break;
                    }
                }

                // Check balance
                $stmt = $pdo->prepare("
                    SELECT balance FROM leave_balances
                    WHERE employee_id = ? AND leave_type_id = ?
                ");
                $stmt->execute([$employeeId, $typeId]);
                $bal = $stmt->fetch();
                if (!$bal || $bal['balance'] < $daysRequested) {
                    echo json_encode(['success' => false, 'message' => 'Insufficient balance.']);
                    break;
                }

                // Check conflicts
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt FROM leave_requests
                    WHERE employee_id = ? AND leave_type_id = ?
                    AND status IN ('Pending', 'Approved')
                    AND (
                        (date_from <= ? AND date_to >= ?)
                        OR
                        (date_from >= ? AND date_from <= ?)
                    )
                ");
                $stmt->execute([$employeeId, $typeId, $dateTo, $dateFrom, $dateFrom, $dateTo]);
                if ($stmt->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'message' => 'Overlapping leave request found.']);
                    break;
                }

                // Submit
                $stmt = $pdo->prepare("
                    INSERT INTO leave_requests (employee_id, leave_type_id, date_from, date_to, days_requested, remarks, status, filed_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW())
                ");
                $stmt->execute([$employeeId, $typeId, $dateFrom, $dateTo, $daysRequested, $remarks]);
                echo json_encode(['success' => true, 'message' => 'Leave request filed successfully.', 'leave_id' => $pdo->lastInsertId()]);
                break;

            case 'render':
                // Return HTML for file leave page
                ob_clean();
                header('Content-Type: text/html');
                
                // Check employment type - if not permanent, show restricted message
                $isRestricted = !isLeaveFeatureAvailable($employmentType);
                ?>
                <div class="hero" style="background:linear-gradient(130deg,#059669 0%,#047857 40%,#10b981 70%,#34d399 100%);border-radius:14px;padding:26px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;animation:fadeUp .4s ease both;">
                  <div>
                    <h1 style="font-family:'Playfair Display',serif;font-size:1.4rem;color:#fff;margin:0;">📝 File Leave Request</h1>
                    <p style="font-size:.85rem;color:rgba(255,255,255,.75);margin-top:8px;">Apply for leave</p>
                  </div>
                  <div style="font-size:2.2rem;">✍️</div>
                </div>

                <?php if ($isRestricted): ?>
                <div class="card" style="background:#fef3c7;border-left:5px solid #fbbf24;padding:16px;">
                  <div style="display:flex;gap:12px;">
                    <div>⚠️</div>
                    <div>
                      <strong style="color:#92400e;">Leave Filing Restricted</strong>
                      <p style="margin:6px 0 0 0;font-size:.84rem;color:#78350f;">Only permanent employees can file leave requests. Your current employment type (<strong><?php echo htmlspecialchars($employmentType); ?></strong>) does not have access to leave features. You can upload documents, view/edit PDS, view SALN, and request service records below.</p>
                    </div>
                  </div>
                </div>
                <?php else: ?>
                <div class="card">
                  <div class="card-header">
                    <div class="card-title">Apply for Leave</div>
                    <div class="card-sub">Select leave type and dates</div>
                  </div>
                  <form id="leaveForm" style="padding:20px;display:grid;gap:16px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                      <div>
                        <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;display:block;">Leave Type</label>
                        <select id="leaveTypeSelect" required style="width:100%;padding:11px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:6px;font-family:'Nunito',sans-serif;">
                          <option value="">-- Select type --</option>
                        </select>
                      </div>
                      <div>
                        <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;display:block;">From Date</label>
                        <input type="date" id="dateFromInput" required style="width:100%;padding:11px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:6px;font-family:'Nunito',sans-serif;">
                      </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                      <div>
                        <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;display:block;">To Date</label>
                        <input type="date" id="dateToInput" required style="width:100%;padding:11px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:6px;font-family:'Nunito',sans-serif;">
                      </div>
                      <div>
                        <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;display:block;">Days Requested</label>
                        <input type="number" id="daysDisplay" readonly style="width:100%;padding:11px 12px;border:1.5px solid var(--g200);border-radius:8px;font-size:.9rem;margin-top:6px;background:var(--g100);font-family:'Nunito',sans-serif;font-weight:700;">
                      </div>
                    </div>
                    <div>
                      <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;display:block;">Remarks (Optional)</label>
                      <textarea id="remarksInput" placeholder="Any additional notes..." style="width:100%;padding:11px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:6px;font-family:'Nunito',sans-serif;resize:vertical;min-height:80px;"></textarea>
                    </div>
                    <div id="balanceInfo" style="padding:14px;border-radius:8px;background:var(--g50);border:1.5px solid var(--g200);font-size:.8rem;color:var(--g600);display:none;"></div>
                    <div style="display:flex;gap:10px;">
                      <button type="submit" style="flex:1;padding:11px 14px;background:#059669;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">Submit Request</button>
                      <button type="reset" style="flex:1;padding:11px 14px;background:var(--g200);color:var(--g700);border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">Clear</button>
                    </div>
                  </form>
                </div>
                <?php endif; ?>

                <script>
                  (async function() {
                    if (!<?php echo $isRestricted ? 'true' : 'false'; ?>) {
                      const types = await fetch('leave_file.php?action=leave_types').then(r => r.json());
                      if (types.success) {
                        const select = document.getElementById('leaveTypeSelect');
                        select.innerHTML += types.data.map(t => `
                          <option value="${t.leave_type_id}" data-max="${t.max_days}">${t.name}</option>
                        `).join('');
                      }

                      document.getElementById('leaveTypeSelect').addEventListener('change', updateBalance);
                      document.getElementById('dateFromInput').addEventListener('change', calculateDays);
                      document.getElementById('dateToInput').addEventListener('change', calculateDays);
                      document.getElementById('leaveForm').addEventListener('submit', submitLeave);
                    }
                  })();

                  function calculateDays() {
                    const fromStr = document.getElementById('dateFromInput').value;
                    const toStr = document.getElementById('dateToInput').value;
                    if (fromStr && toStr) {
                      const from = new Date(fromStr);
                      const to = new Date(toStr);
                      if (from <= to) {
                        const days = Math.floor((to - from) / 86400000) + 1;
                        document.getElementById('daysDisplay').value = days;
                        updateBalance();
                      }
                    }
                  }

                  async function updateBalance() {
                    const typeId = document.getElementById('leaveTypeSelect').value;
                    if (!typeId) return;
                    const res = await fetch(`leave_file.php?action=balance&type_id=${typeId}`).then(r => r.json());
                    const info = document.getElementById('balanceInfo');
                    if (res.success) {
                      const bal = res.data;
                      const used = bal.max_days - bal.balance;
                      info.innerHTML = `<strong>${bal.name}</strong><br>Balance: <strong>${bal.balance}</strong> days | Used: ${used} / ${bal.max_days} days`;
                      info.style.display = 'block';
                    }
                  }

                  async function submitLeave(e) {
                    e.preventDefault();
                    const formData = new FormData(document.getElementById('leaveForm'));
                    formData.append('action', 'submit_leave');
                    formData.append('type_id', document.getElementById('leaveTypeSelect').value);
                    formData.append('date_from', document.getElementById('dateFromInput').value);
                    formData.append('date_to', document.getElementById('dateToInput').value);
                    formData.append('remarks', document.getElementById('remarksInput').value);
                    
                    const res = await fetch('leave_file.php', {method: 'POST', body: formData}).then(r => r.json());
                    if (res.success) {
                      alert('Leave request submitted successfully!');
                      location.reload();
                    } else {
                      alert('Error: ' + res.message);
                    }
                  }
                </script>
                <?php
                exit();
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// If not AJAX, redirect to index
header('Location: index.php');
exit();
?>