<?php
// ============================================================
//  LGU ENRILE HRMS — Leave Requests History
//  File: Employee/leave_requests.php
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
            case 'requests':
                $status = $_GET['status'] ?? '';
                $query = "
                    SELECT lr.*, lt.name AS type_name
                    FROM leave_requests lr
                    JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
                    WHERE lr.employee_id = ?
                ";
                $params = [$employeeId];
                if (!empty($status) && $status !== 'All') {
                    $query .= " AND lr.status = ?";
                    $params[] = $status;
                }
                $query .= " ORDER BY lr.filed_at DESC";
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                break;

            case 'cancel_leave':
                $leaveId = (int)($_POST['leave_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT status FROM leave_requests WHERE leave_request_id = ? AND employee_id = ?");
                $stmt->execute([$leaveId, $employeeId]);
                $leave = $stmt->fetch();
                if (!$leave) {
                    echo json_encode(['success' => false, 'message' => 'Leave record not found.']);
                    break;
                }
                if ($leave['status'] !== 'Pending') {
                    echo json_encode(['success' => false, 'message' => 'Only pending requests can be cancelled.']);
                    break;
                }
                $pdo->prepare("UPDATE leave_requests SET status = 'Cancelled' WHERE leave_request_id = ?")
                    ->execute([$leaveId]);
                echo json_encode(['success' => true, 'message' => 'Leave request cancelled.']);
                break;

            case 'render':
                // Return HTML for leave requests page
                ob_clean();
                header('Content-Type: text/html');
                ?>
                <div class="hero" style="background:linear-gradient(130deg,#2563eb 0%,#1e40af 40%,#1e3a8a 70%,#1d4ed8 100%);border-radius:14px;padding:26px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;animation:fadeUp .4s ease both;">
                  <div>
                    <h1 style="font-family:'Playfair Display',serif;font-size:1.4rem;color:#fff;margin:0;">📋 Your Leave Requests</h1>
                    <p style="font-size:.85rem;color:rgba(255,255,255,.75);margin-top:8px;">View all your leave applications</p>
                  </div>
                  <div style="font-size:2.2rem;">📝</div>
                </div>

                <div class="card">
                  <div class="card-header">
                    <div class="card-title">All Requests</div>
                    <div id="statusFilters" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
                  </div>
                  <div id="reqsContainer" style="padding:20px;min-height:100px;"></div>
                </div>

                <script>
                  const statuses = ['All', 'Pending', 'Approved', 'Denied', 'Cancelled'];
                  let currentStatus = 'All';

                  function initFilters() {
                    const container = document.getElementById('statusFilters');
                    container.innerHTML = statuses.map(s => `
                      <button onclick="filterByStatus('${s}')" style="padding:7px 14px;border:1.5px solid ${s === 'All' ? '#2563eb' : 'var(--g300)'};background:${s === 'All' ? '#2563eb' : '#fff'};color:${s === 'All' ? '#fff' : 'var(--g700)'};border-radius:6px;font-size:.8rem;font-weight:700;cursor:pointer;transition:all .2s;" id="filterBtn${s}" class="status-filter">
                        ${['⏳', '✅', '✗', '—'].includes(s[0]) ? '' : s[0]}${s}
                      </button>
                    `).join('');
                  }

                  async function filterByStatus(status) {
                    currentStatus = status;
                    const reqs = await fetch(`leave_requests.php?action=requests&status=${status}`).then(r => r.json());
                    renderRequests(reqs.data || []);
                    
                    document.querySelectorAll('.status-filter').forEach(b => {
                      const isActive = b.textContent.trim().endsWith(status);
                      b.style.background = isActive ? '#2563eb' : '#fff';
                      b.style.color = isActive ? '#fff' : 'var(--g700)';
                      b.style.borderColor = isActive ? '#2563eb' : 'var(--g300)';
                    });
                  }

                  function renderRequests(requests) {
                    const container = document.getElementById('reqsContainer');
                    if (requests.length === 0) {
                      container.innerHTML = '<div style="text-align:center;padding:30px;color:var(--g400);">No leave requests found</div>';
                      return;
                    }

                    container.innerHTML = requests.map(r => `
                      <div style="padding:16px;margin-bottom:12px;border:1.5px solid var(--g200);border-radius:10px;background:var(--g50);display:flex;justify-content:space-between;align-items:center;gap:20px;">
                        <div>
                          <div style="font-weight:800;font-size:.9rem;color:var(--g800);">${r.type_name}</div>
                          <div style="font-size:.78rem;color:var(--g600);margin-top:4px;">${fmt(r.date_from)} to ${fmt(r.date_to)}</div>
                          <div style="font-size:.75rem;color:var(--g500);margin-top:3px;">Filed ${empTimeAgo(r.filed_at)}</div>
                        </div>
                        <div style="display:flex;align-items:center;gap:16px;">
                          <div style="text-align:center;">
                            <div style="font-size:1.5rem;font-weight:800;color:#2563eb;">${r.days_requested}</div>
                            <div style="font-size:.7rem;color:var(--g600);">days</div>
                          </div>
                          <div style="display:flex;flex-direction:column;gap:6px;">
                            <span style="display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:20px;font-size:.7rem;font-weight:700;white-space:nowrap;background:${r.status === 'Approved' ? '#dcfce7' : r.status === 'Denied' ? '#fee2e2' : r.status === 'Cancelled' ? '#f3f4f6' : '#fef3c7'};color:${r.status === 'Approved' ? '#166534' : r.status === 'Denied' ? '#991b1b' : r.status === 'Cancelled' ? '#6b7280' : '#92400e'};">
                              ${r.status === 'Approved' ? '✅' : r.status === 'Denied' ? '✗' : r.status === 'Cancelled' ? '—' : '⏳'} ${r.status}
                            </span>
                            ${r.status === 'Pending' ? `<button onclick="cancelLeave(${r.leave_request_id})" style="padding:4px 12px;border:1px solid #fecaca;background:#fef2f2;color:#7f1d1d;border-radius:6px;font-size:.7rem;font-weight:700;cursor:pointer;">Cancel</button>` : ''}
                          </div>
                        </div>
                      </div>
                    `).join('');
                  }

                  async function cancelLeave(leaveId) {
                    if (!confirm('Cancel this leave request?')) return;
                    const res = await fetch('leave_requests.php', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                      body: `action=cancel_leave&leave_id=${leaveId}`
                    }).then(r => r.json());
                    if (res.success) {
                      alert('Leave request cancelled.');
                      filterByStatus(currentStatus);
                    } else {
                      alert('Error: ' + res.message);
                    }
                  }

                  (async function() {
                    initFilters();
                    filterByStatus('All');
                  })();
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
