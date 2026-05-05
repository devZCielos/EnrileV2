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
                  if (strtolower($status) === 'pending') {
                    $query .= " AND LOWER(lr.status) LIKE 'pending%'";
                  } else {
                    $query .= " AND lr.status = ?";
                    $params[] = $status;
                  }
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
                if (!in_array($leave['status'], ['Pending', 'Pending Dept Head', 'Pending HR'], true)) {
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
                  let allRequests = [];

                  function initFilters(counts = {}) {
                    const container = document.getElementById('statusFilters');
                    container.innerHTML = statuses.map(s => {
                      let count = counts[s] !== undefined ? counts[s] : '';
                      return `
                        <button onclick="filterByStatus('${s}')" style="padding:7px 14px;border:1.5px solid ${s === 'All' ? '#2563eb' : 'var(--g300)'};background:${s === 'All' ? '#2563eb' : '#fff'};color:${s === 'All' ? '#fff' : 'var(--g700)'};border-radius:6px;font-size:.8rem;font-weight:700;cursor:pointer;transition:all .2s;" id="filterBtn${s}" class="status-filter">
                          ${['⏳', '✅', '✗', '—'].includes(s[0]) ? '' : s[0]}${s} <span style="font-weight:800;">${count !== '' ? count : ''}</span>
                        </button>
                      `;
                    }).join('');
                  }


                  async function filterByStatus(status) {
                    currentStatus = status;
                    // Always fetch all requests for accurate counts
                    const reqs = await fetch(`leave_requests.php?action=requests&status=All`).then(r => r.json());
                    allRequests = reqs.data || [];

                    // Calculate counts for each tab
                    const counts = {
                      'All': allRequests.length,
                      'Pending': allRequests.filter(r => r.status && r.status.toLowerCase().startsWith('pending')).length,
                      'Approved': allRequests.filter(r => r.status === 'Approved').length,
                      'Denied': allRequests.filter(r => r.status === 'Denied').length,
                      'Cancelled': allRequests.filter(r => r.status === 'Cancelled').length
                    };
                    initFilters(counts);

                    // Filter for display
                    let filtered = allRequests;
                    if (status === 'Pending') {
                      filtered = allRequests.filter(r => r.status && r.status.toLowerCase().startsWith('pending'));
                    } else if (status !== 'All') {
                      filtered = allRequests.filter(r => r.status === status);
                    }
                    renderRequests(filtered);

                    document.querySelectorAll('.status-filter').forEach(b => {
                      const isActive = b.textContent.trim().toLowerCase().startsWith(status.toLowerCase());
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

                    container.innerHTML = requests.map(r => {
                      // Status badge colors and icons
                      let statusColor = '#fef3c7', statusTextColor = '#92400e', statusIcon = '⏳';
                      if (r.status === 'Approved') { statusColor = '#dcfce7'; statusTextColor = '#166534'; statusIcon = '✅'; }
                      else if (r.status === 'Denied') { statusColor = '#fee2e2'; statusTextColor = '#991b1b'; statusIcon = '✗'; }
                      else if (r.status === 'Cancelled') { statusColor = '#f3f4f6'; statusTextColor = '#6b7280'; statusIcon = '—'; }

                      // Leave type icons (example mapping)
                      const typeIcons = {
                        'Vacation': '🏖️', 'Sick': '🤒', 'Emergency': '🚨', 'Maternity': '🤰', 'Paternity': '👨‍👧',
                        'Special': '⭐', 'VAWC': '🟣', 'Solo Parent': '👩‍👧', 'Study': '📚', 'Others': '📄'
                      };
                      let typeIcon = typeIcons[r.type_name] || '📄';

                      return `
                        <div style="box-shadow:0 2px 12px rgba(37,99,235,0.07);border-radius:16px;background:#fff;border:1.5px solid #e2e8f0;margin-bottom:18px;padding:0 0 0 0;overflow:hidden;display:flex;flex-direction:column;">
                          <div style="display:flex;align-items:center;gap:16px;padding:20px 24px 12px 24px;">
                            <div style="font-size:2.1rem;">${typeIcon}</div>
                            <div style="flex:1;min-width:0;">
                              <div style="font-weight:900;font-size:1.08rem;color:#1e293b;line-height:1.2;">${r.type_name}</div>
                              <div style="font-size:.89rem;color:#475569;margin-top:2px;">${fmt(r.date_from)} <span style="color:#a3a3a3;">to</span> ${fmt(r.date_to)}</div>
                              <div style="font-size:.78rem;color:#64748b;margin-top:2px;">Filed: ${empTimeAgo(r.filed_at)}</div>
                            </div>
                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:7px;">
                              <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 16px;border-radius:18px;font-size:.82rem;font-weight:800;background:${statusColor};color:${statusTextColor};min-width:110px;justify-content:center;">
                                <span style="font-size:1.1rem;">${statusIcon}</span> ${r.status}
                              </span>
                              <span style="display:inline-block;padding:4px 14px;background:#2563eb;color:#fff;border-radius:16px;font-size:.90rem;font-weight:800;letter-spacing:.02em;box-shadow:0 1px 4px rgba(37,99,235,0.10);">${r.days_requested} day${r.days_requested > 1 ? 's' : ''}</span>
                            </div>
                          </div>
                          ${r.reason ? `<div style=\"padding:0 24px 12px 72px;color:#334155;font-size:.93rem;\"><span style=\"font-weight:700;color:#64748b;\">Reason:</span> ${r.reason}</div>` : ''}
                          ${r.status === 'Pending' ? `<div style=\"padding:0 24px 18px 72px;\"><button onclick=\"cancelLeave(${r.leave_request_id})\" style=\"padding:7px 22px;border:1.5px solid #fecaca;background:#fef2f2;color:#7f1d1d;border-radius:8px;font-size:.85rem;font-weight:800;cursor:pointer;transition:all .2s;\">Cancel Request</button></div>` : ''}
                        </div>
                      `;
                    }).join('');
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