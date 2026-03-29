<?php
// ============================================================
//  LGU ENRILE HRMS — Leave Balances
//  File: Employee/leave_balances.php
//  IMPORTANT: Visible only to Permanent employees
// ============================================================
require_once __DIR__ . '/_shared/auth.php';
require_once __DIR__ . '/_shared/helpers.php';

$_isAjax = !empty($_GET['action']) || !empty($_POST['action']);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    ob_start();
    header('Content-Type: application/json');

    try {
        switch ($action) {
            case 'balances':
                if (!isLeaveFeatureAvailable($employmentType)) {
                    echo json_encode(['success' => true, 'data' => [], 'restricted' => true]);
                    break;
                }
                $stmt = $pdo->prepare("
                    SELECT lb.*, lt.name AS type_name, lt.max_days, lt.gender_restriction
                    FROM leave_balances lb
                    JOIN leave_types lt ON lt.leave_type_id = lb.leave_type_id
                    WHERE lb.employee_id = ? AND lt.status = 'Active'
                    ORDER BY lt.name ASC
                ");
                $stmt->execute([$employeeId]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                break;

            case 'render':
                ob_clean();
                header('Content-Type: text/html');
                $isRestricted = !isLeaveFeatureAvailable($employmentType);
                ?>
                <div class="hero" style="background:linear-gradient(130deg,#dc2626 0%,#991b1b 40%,#b91c1c 70%,#fca5a5 100%);border-radius:14px;padding:26px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;animation:fadeUp .4s ease both;">
                  <div>
                    <h1 style="font-family:'Playfair Display',serif;font-size:1.4rem;color:#fff;margin:0;">⚖️ Leave Balances</h1>
                    <p style="font-size:.85rem;color:rgba(255,255,255,.75);margin-top:8px;">View your available leave days</p>
                  </div>
                  <div style="font-size:2.2rem;">📊</div>
                </div>

                <?php if ($isRestricted): ?>
                <div class="card" style="background:#fee2e2;border-left:5px solid #ef4444;padding:16px;">
                  <div style="display:flex;gap:12px;">
                    <div>ℹ️</div>
                    <div>
                      <strong style="color:#7f1d1d;">Leave Balances Not Available</strong>
                      <p style="margin:6px 0 0 0;font-size:.84rem;color:#991b1b;">Leave features are restricted to permanent employees only. Your employment type (<strong><?php echo htmlspecialchars($employmentType); ?></strong>) does not have access to leave systems.</p>
                    </div>
                  </div>
                </div>
                <?php else: ?>
                <div id="balancesContainer" style="display:grid;gap:14px;">Loading balances…</div>
                <?php endif; ?>

                <script>
                  (async function() {
                    if (!<?php echo $isRestricted ? 'true' : 'false'; ?>) {
                      const res = await fetch('leave_balances.php?action=balances').then(r => r.json());
                      if (res.success && res.data.length > 0) {
                        const container = document.getElementById('balancesContainer');
                        const total = res.data.reduce((sum, b) => sum + b.balance, 0);
                        const used = res.data.reduce((sum, b) => sum + (b.max_days - b.balance), 0);
                        
                        container.innerHTML = `
                          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:6px;">
                            <div class="card" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);border:none;padding:18px;">
                              <div style="font-size:.75rem;color:#1e40af;text-transform:uppercase;font-weight:700;margin-bottom:6px;">Total Available</div>
                              <div style="font-size:2.2rem;font-weight:800;color:#1e3a8a;line-height:1;">${total}</div>
                              <div style="font-size:.7rem;color:#3b82f6;margin-top:4px;">days</div>
                            </div>
                            <div class="card" style="background:linear-gradient(135deg,#fecaca,#fca5a5);border:none;padding:18px;">
                              <div style="font-size:.75rem;color:#991b1b;text-transform:uppercase;font-weight:700;margin-bottom:6px;">Total Used</div>
                              <div style="font-size:2.2rem;font-weight:800;color:#7f1d1d;line-height:1;">${used}</div>
                              <div style="font-size:.7rem;color:#fca5a5;margin-top:4px;">days</div>
                            </div>
                          </div>
                        ` + res.data.map((b, idx) => {
                          const pct = (b.balance / b.max_days * 100).toFixed(0);
                          return `
                            <div class="card">
                              <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;">
                                <div>
                                  <div class="card-title">${b.type_name}</div>
                                  <div class="card-sub">${b.balance} of ${b.max_days} days available</div>
                                </div>
                                <div style="font-size:1.4rem;font-weight:800;color:#2563eb;text-align:right;">${b.balance}</div>
                              </div>
                              <div style="width:100%;height:8px;background:var(--g200);border-radius:4px;overflow:hidden;">
                                <div style="width:${pct}%;height:100%;background:linear-gradient(90deg,#3b82f6,#1e40af);transition:width .3s;"></div>
                              </div>
                              <div style="font-size:.7rem;color:var(--g500);margin-top:6px;">${pct}% utilized</div>
                            </div>
                          `;
                        }).join('');
                      } else {
                        document.getElementById('balancesContainer').innerHTML = '<div class="card" style="text-align:center;padding:30px;color:var(--g400);">No leave data available</div>';
                      }
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

header('Location: index.php');
exit();
?>