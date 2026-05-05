<?php
// ============================================================
//  LGU ENRILE HRMS — Employee Portal Dashboard
//  File: Employee/dashboard.php
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
            case 'profile':
                $stmt = $pdo->prepare("
                    SELECT e.*, d.name AS department_name
                    FROM employees e
                    LEFT JOIN departments d ON d.department_id = e.department_id
                    WHERE e.user_id = ?
                ");
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if (!$row) {
                    $stmt2 = $pdo->prepare("
                        SELECT e.*, d.name AS department_name
                        FROM employees e
                        LEFT JOIN departments d ON d.department_id = e.department_id
                        JOIN user_accounts u ON u.user_id = ?
                        WHERE e.email_address = u.email
                        LIMIT 1
                    ");
                    $stmt2->execute([$userId]);
                    $row = $stmt2->fetch();
                    if ($row) {
                        $pdo->prepare("UPDATE employees SET user_id = ? WHERE employee_id = ?")
                            ->execute([$userId, $row['employee_id']]);
                    }
                }
                echo json_encode($row ? ['success' => true, 'data' => $row] : ['success' => false, 'message' => 'No employee record found.']);
                break;

            case 'recent_requests':
                $stmt = $pdo->prepare("
                    SELECT lr.*, lt.name AS type_name
                    FROM leave_requests lr
                    JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
                    WHERE lr.employee_id = ?
                    ORDER BY lr.filed_at DESC
                    LIMIT 5
                ");
                $stmt->execute([$employeeId]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                break;

            case 'announcements':
                // P2-11 — Filter announcements by audience and department
                $empInfo = $pdo->prepare("SELECT employment_type, department_id FROM employees WHERE employee_id=?");
                $empInfo->execute([$employeeId]);
                $empData = $empInfo->fetch();
                $empType  = $empData['employment_type'] ?? 'Permanent';
                $empDept  = (int)($empData['department_id'] ?? 0);

                $stmt = $pdo->prepare("
                    SELECT title, created_at FROM announcements
                    WHERE status = 'Published'
                    AND (publish_date IS NULL OR publish_date <= CURDATE())
                    AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND (
                        audience = 'All'
                        OR audience = ?
                        OR (audience = 'Department' AND (department_id IS NULL OR department_id = ?))
                    )
                    ORDER BY pinned DESC, created_at DESC
                    LIMIT 3
                ");
                $stmt->execute([$empType, $empDept]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                break;

            case 'render':
                // Return HTML for dashboard page
                ob_clean();
                header('Content-Type: text/html');
                ?>
                <div class="hero" style="background:linear-gradient(130deg,#1e3a8a 0%,#1e40af 40%,#2563eb 70%,#166534 100%);border-radius:14px;padding:26px 28px;display:flex;align-items:center;gap:20px;margin-bottom:20px;position:relative;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.1);animation:fadeUp .4s ease both;">
                  <div class="hero-av" style="width:66px;height:66px;border-radius:50%;background:rgba(255,255,255,.20);border:3px solid rgba(255,255,255,.65);display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 4px 16px rgba(0,0,0,.22);overflow:hidden;" id="heroAv">?</div>
                  <div class="hero-body">
                    <h2 style="font-family:'Playfair Display',serif;font-size:1.25rem;color:#fff;" id="heroGreet">Loading…</h2>
                    <p style="font-size:.78rem;color:rgba(255,255,255,.65);margin-top:4px;" id="heroSub"></p>
                    <div class="hero-chips" id="heroChips" style="display:flex;gap:7px;margin-top:10px;flex-wrap:wrap;"></div>
                  </div>
                  <div class="hero-date" style="margin-left:auto;text-align:right;flex-shrink:0;">
                    <div class="hero-date-num" style="font-size:2.2rem;font-weight:800;color:#fff;line-height:1;" id="heroDay"></div>
                    <div class="hero-date-str" style="font-size:.72rem;color:rgba(255,255,255,.60);margin-top:2px;" id="heroMonth"></div>
                  </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 320px;gap:18px;">
                  <div class="card">
                    <div class="card-header">
                      <div>
                        <div class="card-title">📋 My Recent Leave Requests</div>
                        <div class="card-sub">Latest applications</div>
                      </div>
                      <button class="card-link" onclick="goPage('requests',document.querySelectorAll('.sb-item')[2])">View all →</button>
                    </div>
                    <table style="width:100%;border-collapse:collapse;font-size:.81rem;">
                      <thead>
                        <tr>
                          <th style="padding:9px 14px;text-align:left;font-size:.65rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);background:var(--g50);">Type</th>
                          <th style="padding:9px 14px;text-align:left;font-size:.65rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);background:var(--g50);">Duration</th>
                          <th style="padding:9px 14px;text-align:left;font-size:.65rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);background:var(--g50);">Days</th>
                          <th style="padding:9px 14px;text-align:left;font-size:.65rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);background:var(--g50);">Status</th>
                        </tr>
                      </thead>
                      <tbody id="recentReqTbody">
                        <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--g400);">Loading…</td></tr>
                      </tbody>
                    </table>
                  </div>

                  <div style="display:flex;flex-direction:column;gap:16px;">
                    <div class="card">
                      <div class="card-header"><div class="card-title">⚡ Quick Actions</div></div>
                      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:14px 16px;">
                        <button class="qa-btn" onclick="goPage('file',document.querySelectorAll('.sb-item')[3])" style="display:flex;flex-direction:column;align-items:center;gap:7px;padding:14px 8px;border-radius:9px;border:1.5px solid var(--g200);background:var(--g50);cursor:pointer;transition:all .2s;color:var(--g800);font-family:'Nunito',sans-serif;border-style:none;">
                          <div style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;background:#dbeafe;">📝</div>
                          <span style="font-size:.72rem;font-weight:700;text-align:center;line-height:1.3;color:var(--g700);">File Leave</span>
                        </button>
                        <button class="qa-btn" onclick="goPage('balances',document.querySelectorAll('.sb-item')[4])" style="display:flex;flex-direction:column;align-items:center;gap:7px;padding:14px 8px;border-radius:9px;border:1.5px solid var(--g200);background:var(--g50);cursor:pointer;transition:all .2s;color:var(--g800);font-family:'Nunito',sans-serif;border-style:none;">
                          <div style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;background:#dcfce7;">⚖️</div>
                          <span style="font-size:.72rem;font-weight:700;text-align:center;line-height:1.3;color:var(--g700);">Leave Balances</span>
                        </button>
                        <button class="qa-btn" onclick="goPage('profile',document.querySelectorAll('.sb-item')[1])" style="display:flex;flex-direction:column;align-items:center;gap:7px;padding:14px 8px;border-radius:9px;border:1.5px solid var(--g200);background:var(--g50);cursor:pointer;transition:all .2s;color:var(--g800);font-family:'Nunito',sans-serif;border-style:none;">
                          <div style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;background:#f3e8ff;">👤</div>
                          <span style="font-size:.72rem;font-weight:700;text-align:center;line-height:1.3;color:var(--g700);">My Profile</span>
                        </button>
                        <button class="qa-btn" onclick="goPage('documents',document.querySelectorAll('.sb-item')[5])" style="display:flex;flex-direction:column;align-items:center;gap:7px;padding:14px 8px;border-radius:9px;border:1.5px solid var(--g200);background:var(--g50);cursor:pointer;transition:all .2s;color:var(--g800);font-family:'Nunito',sans-serif;border-style:none;">
                          <div style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;background:#fef3c7;">📄</div>
                          <span style="font-size:.72rem;font-weight:700;text-align:center;line-height:1.3;color:var(--g700);">My Documents</span>
                        </button>
                      </div>
                    </div>
                    <div class="card">
                      <div class="card-header"><div class="card-title">📢 Announcements</div></div>
                      <div id="annList" style="padding:18px;color:var(--g400);font-size:.82rem;">Loading…</div>
                    </div>
                  </div>
                </div>

                <script>
                  (async function() {
                    // Load profile
                    const prof = await fetch('dashboard.php?action=profile').then(r => r.json());
                    if (prof.success) {
                      const emp = prof.data;
                      const fullName = emp.first_name + ' ' + emp.last_name;
                      const ini = (fullName).split(' ').map(w => w[0] || '').join('').substring(0, 2).toUpperCase();
                      const now = new Date();
                      const greet = now.getHours() < 12 ? 'morning' : now.getHours() < 17 ? 'afternoon' : 'evening';
                      document.getElementById('heroGreet').textContent = `Good ${greet}, ${emp.first_name || ''}! 👋`;
                      document.getElementById('heroSub').textContent = emp.position_title || '';
                      document.getElementById('heroDay').textContent = now.getDate();
                      document.getElementById('heroMonth').textContent = now.toLocaleDateString('en-PH', {month:'long', year:'numeric'});
                      document.getElementById('heroAv').textContent = ini;
                      const chips = ['💼 ' + (emp.position_title || ''), '🏢 ' + (emp.department_name || '')];
                      document.getElementById('heroChips').innerHTML = chips.map(c => `<div style="padding:3px 12px;border-radius:20px;font-size:.68rem;font-weight:700;background:rgba(255,255,255,.18);color:#fff;">${c}</div>`).join('');
                    }

                    // Load recent requests
                    const reqs = await fetch('dashboard.php?action=recent_requests').then(r => r.json());
                    if (reqs.success && reqs.data.length > 0) {
                      const tbody = document.getElementById('recentReqTbody');
                      tbody.innerHTML = reqs.data.map(r => `
                        <tr onclick="goPage('requests',document.querySelectorAll('.sb-item')[2])">
                          <td style="padding:11px 14px;border-bottom:1px solid var(--g100);vertical-align:middle;"><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.70rem;font-weight:700;white-space:nowrap;background:#dbeafe;color:#1e40af;">${r.type_name}</span></td>
                          <td style="padding:11px 14px;border-bottom:1px solid var(--g100);vertical-align:middle;font-size:.77rem;color:var(--g600);">${fmt(r.date_from)}${r.date_from !== r.date_to ? ' – ' + fmt(r.date_to) : ''}</td>
                          <td style="padding:11px 14px;border-bottom:1px solid var(--g100);vertical-align:middle;font-weight:800;">${r.days_requested}</td>
                          <td style="padding:11px 14px;border-bottom:1px solid var(--g100);vertical-align:middle;"><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.70rem;font-weight:700;white-space:nowrap;background:#dcfce7;color:#166534;">${r.status === 'Approved' ? '✅' : r.status === 'Denied' ? '✗' : r.status === 'Cancelled' ? '—' : '⏳'} ${r.status}</span></td>
                        </tr>
                      `).join('');
                    } else {
                      document.getElementById('recentReqTbody').innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:var(--g400);">No recent requests</td></tr>';
                    }

                    // Load announcements
                    const anns = await fetch('dashboard.php?action=announcements').then(r => r.json());
                    if (anns.success && anns.data.length > 0) {
                      document.getElementById('annList').innerHTML = anns.data.map(a => `
                        <div style="display:flex;gap:11px;padding:11px 0;border-bottom:1px solid var(--g100);">
                          <div style="width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px;background:#2563eb;"></div>
                          <div>
                            <strong style="font-size:.81rem;font-weight:700;color:var(--g800);">${a.title}</strong>
                            <small style="font-size:.64rem;color:var(--g400);margin-top:3px;display:block;">${empTimeAgo(a.created_at)}</small>
                          </div>
                        </div>
                      `).join('');
                    } else {
                      document.getElementById('annList').innerHTML = '<div style="text-align:center;padding:20px;color:var(--g400);">No announcements</div>';
                    }
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