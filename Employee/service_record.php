<?php
// ============================================================
//  LGU ENRILE HRMS — Service Record
//  File: Employee/service_record.php
//  Accessible to all employment types
// ============================================================
require_once __DIR__ . '/_shared/auth.php';
require_once __DIR__ . '/_shared/helpers.php';

$_isAjax = !empty($_GET['action']) || !empty($_POST['action']);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    ob_start();
    @ini_set('display_errors', 0);
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(true); }

    try {
        switch ($action) {
            case 'get_service_record':
                $stmt = $pdo->prepare("
                    SELECT sr.*, d.name AS department_name
                    FROM service_records sr
                    LEFT JOIN departments d ON d.department_id = sr.department_id
                    WHERE sr.employee_id = ?
                    ORDER BY sr.from_date DESC
                ");
                $stmt->execute([$employeeId]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                break;

            case 'request_service_record':
                $purpose = sanitize($_POST['purpose'] ?? '');
                if (empty($purpose)) {
                    echo json_encode(['success' => false, 'message' => 'Purpose is required.']);
                    break;
                }
                
                // Check for pending requests
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt FROM service_record_requests
                    WHERE employee_id = ? AND status = 'Pending'
                ");
                $stmt->execute([$employeeId]);
                if ($stmt->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'message' => 'You have a pending service record request. Please wait for approval.']);
                    break;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO service_record_requests (employee_id, purpose, status, requested_at)
                    VALUES (?, ?, 'Pending', NOW())
                ");
                $stmt->execute([$employeeId, $purpose]);
                echo json_encode(['success' => true, 'message' => 'Service record request submitted. Awaiting admin approval.']);
                break;

            case 'get_sr_requests':
                $stmt = $pdo->prepare("
                    SELECT srr.*, e.first_name, e.last_name
                    FROM service_record_requests srr
                    JOIN employees e ON e.employee_id = srr.employee_id
                    WHERE srr.employee_id = ?
                    ORDER BY srr.requested_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$employeeId]);
                echo json_encode(['success' => true, 'data' => $stmt->fetch()]);
                break;

            case 'render':
                ob_clean();
                header('Content-Type: text/html');
                ?>
                <div class="hero" style="background:linear-gradient(130deg,#06b6d4 0%,#0891b2 40%,#0e7490 70%,#22d3ee 100%);border-radius:14px;padding:26px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;animation:fadeUp .4s ease both;">
                  <div>
                    <h1 style="font-family:'Playfair Display',serif;font-size:1.4rem;color:#fff;margin:0;">📜 Service Record</h1>
                    <p style="font-size:.85rem;color:rgba(255,255,255,.75);margin-top:8px;">View your service history or request service record</p>
                  </div>
                  <div style="font-size:2.2rem;">📋</div>
                </div>

                <div id="srrAlert" style="display:none;margin-bottom:16px;"></div>

                <div class="card">
                  <div class="card-header">
                    <div class="card-title">📝 Request Service Record</div>
                    <div class="card-sub">Official service documentation</div>
                  </div>
                  <form id="srRequestForm" style="padding:20px;display:grid;gap:12px;">
                    <div>
                      <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Purpose</label>
                      <select id="purposeSelect" required style="width:100%;padding:11px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:6px;font-family:'Nunito',sans-serif;">
                        <option value="">-- Select purpose --</option>
                        <option value="Loan Application">Loan Application</option>
                        <option value="Visa/Travel">Visa/Travel Documentation</option>
                        <option value="Employment Application">Employment Application</option>
                        <option value="Educational Purpose">Educational Purpose</option>
                        <option value="Other">Other</option>
                      </select>
                    </div>
                    <button type="submit" style="padding:11px 14px;background:#06b6d4;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">📤 Request Service Record</button>
                  </form>
                </div>

                <div class="card">
                  <div class="card-header">
                    <div class="card-title">📋 Your Service History</div>
                    <div class="card-sub">Complete chronological record</div>
                  </div>
                  <div id="serviceHistoryContainer" style="padding:20px;min-height:100px;">Loading service history…</div>
                </div>

                <script>
                  (async function() {
                    // Check for pending requests
                    const sreq = await fetch('service_record.php?action=get_sr_requests').then(r => r.json());
                    if (sreq.success && sreq.data) {
                      const alert = document.getElementById('srrAlert');
                      const status = sreq.data.status;
                      const statusColor = status === 'Approved' ? '#dcfce7' : status === 'Rejected' ? '#fee2e2' : '#fef3c7';
                      const statusTextColor = status === 'Approved' ? '#166534' : status === 'Rejected' ? '#991b1b' : '#92400e';
                      alert.innerHTML = `
                        <div style="background:${statusColor};border-left:5px solid ${statusTextColor};padding:14px;border-radius:6px;">
                          <strong style="color:${statusTextColor};">Service Record Request ${status}</strong>
                          <p style="margin:6px 0 0 0;font-size:.82rem;color:${statusTextColor};">
                            Requested: ${new Date(sreq.data.requested_at).toLocaleDateString('en-PH')} • Purpose: ${sreq.data.purpose}
                          </p>
                        </div>
                      `;
                      alert.style.display = 'block';
                    }

                    // Load service history
                    const res = await fetch('service_record.php?action=get_service_record').then(r => r.json());
                    if (res.success && res.data && res.data.length > 0) {
                      const container = document.getElementById('serviceHistoryContainer');
                      container.innerHTML = res.data.map((sr, idx) => `
                        <div style="position:relative;padding-bottom:${idx < res.data.length - 1 ? '24px' : '0'};margin-bottom:${idx < res.data.length - 1 ? '24px' : '0'};border-bottom:${idx < res.data.length - 1 ? '2px solid var(--g200)' : 'none'};">
                          <div style="position:absolute;left:-16px;top:6px;width:28px;height:28px;border-radius:50%;background:#06b6d4;border:3px solid #fff;box-shadow:0 0 0 2px #06b6d4;"></div>
                          <div style="margin-left:20px;">
                            <strong style="font-size:.95rem;color:var(--g800);">${sr.position_title || 'Position'}</strong>
                            <div style="font-size:.8rem;color:var(--g600);margin-top:4px;">
                              ${sr.department_name || 'Department'} • ${fmt(sr.from_date)}${sr.to_date ? ' – ' + fmt(sr.to_date) : ' – Present'}
                            </div>
                            ${sr.notes ? '<div style="font-size:.78rem;color:var(--g500);margin-top:6px;font-style:italic;">' + sr.notes + '</div>' : ''}
                          </div>
                        </div>
                      `).join('');
                    } else {
                      document.getElementById('serviceHistoryContainer').innerHTML = '<div style="text-align:center;padding:30px;color:var(--g400);">No service history found</div>';
                    }

                    document.getElementById('srRequestForm').addEventListener('submit', submitSRRequest);
                  })();

                  async function submitSRRequest(e) {
                    e.preventDefault();
                    const purpose = document.getElementById('purposeSelect').value;
                    if (!purpose) {
                      alert('Please select a purpose.');
                      return;
                    }

                    const res = await fetch('service_record.php', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                      body: `action=request_service_record&purpose=${encodeURIComponent(purpose)}`
                    }).then(r => r.json());

                    if (res.success) {
                      alert(res.message);
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

header('Location: index.php');
exit();
?>
