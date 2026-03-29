<?php
// ============================================================
//  LGU ENRILE HRMS — Statement of Assets, Liabilities & Networth
//  File: Employee/saln.php
//  Accessible to all employment types
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
            case 'get_saln':
                $stmt = $pdo->prepare("
                    SELECT saln_id, employee_id, saln_year, real_property, personal_property,
                           liabilities, income_info, networth, updated_at
                    FROM statements_assets_liabilities
                    WHERE employee_id = ? AND saln_year = ?
                    LIMIT 1
                ");
                $stmt->execute([$employeeId, date('Y')]);
                $saln = $stmt->fetch();
                if ($saln) {
                    $saln['real_property'] = json_decode($saln['real_property'], true) ?? [];
                    $saln['personal_property'] = json_decode($saln['personal_property'], true) ?? [];
                    $saln['liabilities'] = json_decode($saln['liabilities'], true) ?? [];
                    $saln['income_info'] = json_decode($saln['income_info'], true) ?? [];
                }
                echo json_encode(['success' => true, 'data' => $saln, 'year' => date('Y')]);
                break;

            case 'save_saln':
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                
                $stmt = $pdo->prepare("
                    SELECT saln_id FROM statements_assets_liabilities
                    WHERE employee_id = ? AND saln_year = ?
                ");
                $stmt->execute([$employeeId, date('Y')]);
                $existing = $stmt->fetch();
                
                $realProp = json_encode($data['real_property'] ?? []);
                $persProp = json_encode($data['personal_property'] ?? []);
                $liab = json_encode($data['liabilities'] ?? []);
                $income = json_encode($data['income_info'] ?? []);
                
                if ($existing) {
                    $stmt = $pdo->prepare("
                        UPDATE statements_assets_liabilities
                        SET real_property = ?, personal_property = ?, liabilities = ?,
                            income_info = ?, updated_at = NOW()
                        WHERE employee_id = ? AND saln_year = ?
                    ");
                    $stmt->execute([$realProp, $persProp, $liab, $income, $employeeId, date('Y')]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO statements_assets_liabilities
                        (employee_id, saln_year, real_property, personal_property, liabilities, income_info, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$employeeId, date('Y'), $realProp, $persProp, $liab, $income]);
                }
                echo json_encode(['success' => true, 'message' => 'SALN saved successfully.']);
                break;

            case 'render':
                ob_clean();
                header('Content-Type: text/html');
                ?>
                <div class="hero" style="background:linear-gradient(130deg,#7c3aed 0%,#6d28d9 40%,#5b21b6 70%,#c084fc 100%);border-radius:14px;padding:26px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;animation:fadeUp .4s ease both;">
                  <div>
                    <h1 style="font-family:'Playfair Display',serif;font-size:1.4rem;color:#fff;margin:0;">💰 Statement of Assets, Liabilities & Networth</h1>
                    <p style="font-size:.85rem;color:rgba(255,255,255,.75);margin-top:8px;">Declare your assets and liabilities</p>
                  </div>
                  <div style="font-size:2.2rem;">📊</div>
                </div>

                <form id="salnForm" style="display:grid;gap:18px;">
                  <!-- Real Property -->
                  <div class="card">
                    <div class="card-header"><div class="card-title">🏠 Real Property</div></div>
                    <div style="padding:20px;display:grid;gap:10px;">
                      <div id="realPropRepeater" style="display:grid;gap:10px;"></div>
                      <button type="button" onclick="addRealProperty()" style="padding:10px 14px;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">+ Add Property</button>
                    </div>
                  </div>

                  <!-- Personal Property -->
                  <div class="card">
                    <div class="card-header"><div class="card-title">🚗 Personal Property</div></div>
                    <div style="padding:20px;display:grid;gap:10px;">
                      <div id="persPropRepeater" style="display:grid;gap:10px;"></div>
                      <button type="button" onclick="addPersonalProperty()" style="padding:10px 14px;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">+ Add Item</button>
                    </div>
                  </div>

                  <!-- Liabilities -->
                  <div class="card">
                    <div class="card-header"><div class="card-title">📋 Liabilities</div></div>
                    <div style="padding:20px;display:grid;gap:10px;">
                      <div id="liabRepeater" style="display:grid;gap:10px;"></div>
                      <button type="button" onclick="addLiability()" style="padding:10px 14px;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">+ Add Liability</button>
                    </div>
                  </div>

                  <!-- Income -->
                  <div class="card">
                    <div class="card-header"><div class="card-title">💵 Income Information</div></div>
                    <div style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                      <div>
                        <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Salary/Compensation</label>
                        <input type="number" id="inc_salary" step="0.01" style="width:100%;padding:10px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:4px;font-family:'Nunito',sans-serif;">
                      </div>
                      <div>
                        <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Business Income</label>
                        <input type="number" id="inc_business" step="0.01" style="width:100%;padding:10px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:4px;font-family:'Nunito',sans-serif;">
                      </div>
                      <div>
                        <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Other Income</label>
                        <input type="number" id="inc_other" step="0.01" style="width:100%;padding:10px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:4px;font-family:'Nunito',sans-serif;">
                      </div>
                      <div>
                        <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Total Income</label>
                        <input type="number" id="inc_total" readonly step="0.01" style="width:100%;padding:10px 12px;border:1.5px solid var(--g200);border-radius:8px;font-size:.9rem;margin-top:4px;font-family:'Nunito',sans-serif;background:var(--g100);font-weight:700;">
                      </div>
                    </div>
                  </div>

                  <!-- Summary -->
                  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="card" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);border:none;padding:14px;text-align:center;">
                      <div style="font-size:.7rem;color:#1e40af;font-weight:700;text-transform:uppercase;">Total Assets</div>
                      <div style="font-size:1.8rem;font-weight:800;color:#1e3a8a;line-height:1;margin:6px 0;" id="totalAssets">0</div>
                    </div>
                    <div class="card" style="background:linear-gradient(135deg,#fee2e2,#fca5a5);border:none;padding:14px;text-align:center;">
                      <div style="font-size:.7rem;color:#991b1b;font-weight:700;text-transform:uppercase;">Total Liabilities</div>
                      <div style="font-size:1.8rem;font-weight:800;color:#7f1d1d;line-height:1;margin:6px 0;" id="totalLiab">0</div>
                    </div>
                    <div class="card" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);border:none;padding:14px;text-align:center;">
                      <div style="font-size:.7rem;color:#166534;font-weight:700;text-transform:uppercase;">Networth</div>
                      <div style="font-size:1.8rem;font-weight:800;color:#15803d;line-height:1;margin:6px 0;" id="totalNet">0</div>
                    </div>
                  </div>

                  <!-- Submit -->
                  <button type="submit" style="padding:11px 14px;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">💾 Save SALN</button>
                </form>

                <script>
                  let salnData = {
                    real_property: [],
                    personal_property: [],
                    liabilities: [],
                    income_info: {}
                  };

                  (async function() {
                    const res = await fetch('saln.php?action=get_saln').then(r => r.json());
                    if (res.success && res.data) {
                      salnData = {
                        real_property: res.data.real_property || [],
                        personal_property: res.data.personal_property || [],
                        liabilities: res.data.liabilities || [],
                        income_info: res.data.income_info || {}
                      };
                      loadSALNForm();
                    }
                    document.getElementById('salnForm').addEventListener('submit', saveSALN);
                    document.getElementById('inc_salary').addEventListener('change', updateTotalIncome);
                    document.getElementById('inc_business').addEventListener('change', updateTotalIncome);
                    document.getElementById('inc_other').addEventListener('change', updateTotalIncome);
                  })();

                  function loadSALNForm() {
                    document.getElementById('inc_salary').value = salnData.income_info.salary || 0;
                    document.getElementById('inc_business').value = salnData.income_info.business || 0;
                    document.getElementById('inc_other').value = salnData.income_info.other || 0;
                    renderAllAssets();
                  }

                  function renderAllAssets() {
                    renderRealProperty();
                    renderPersonalProperty();
                    renderLiabilities();
                    calculateNW();
                  }

                  function renderRealProperty() {
                    const container = document.getElementById('realPropRepeater');
                    container.innerHTML = (salnData.real_property || []).map((prop, idx) => `
                      <div style="border:1.5px solid var(--g200);padding:12px;border-radius:8px;display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;">
                        <input type="text" value="${prop.description || ''}" onchange="salnData.real_property[${idx}].description = this.value" placeholder="Description" style="padding:8px 10px;border:1px solid var(--g300);border-radius:6px;font-size:.8rem;">
                        <input type="number" value="${prop.value || 0}" onchange="salnData.real_property[${idx}].value = parseFloat(this.value); calculateNW();" placeholder="Value" style="padding:8px 10px;border:1px solid var(--g300);border-radius:6px;font-size:.8rem;">
                        <button type="button" onclick="salnData.real_property.splice(${idx}, 1); renderAllAssets();" style="padding:6px 10px;background:#fee2e2;color:#991b1b;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;">Remove</button>
                      </div>
                    `).join('');
                  }

                  function renderPersonalProperty() {
                    const container = document.getElementById('persPropRepeater');
                    container.innerHTML = (salnData.personal_property || []).map((prop, idx) => `
                      <div style="border:1.5px solid var(--g200);padding:12px;border-radius:8px;display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;">
                        <input type="text" value="${prop.description || ''}" onchange="salnData.personal_property[${idx}].description = this.value" placeholder="Description" style="padding:8px 10px;border:1px solid var(--g300);border-radius:6px;font-size:.8rem;">
                        <input type="number" value="${prop.value || 0}" onchange="salnData.personal_property[${idx}].value = parseFloat(this.value); calculateNW();" placeholder="Value" style="padding:8px 10px;border:1px solid var(--g300);border-radius:6px;font-size:.8rem;">
                        <button type="button" onclick="salnData.personal_property.splice(${idx}, 1); renderAllAssets();" style="padding:6px 10px;background:#fee2e2;color:#991b1b;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;">Remove</button>
                      </div>
                    `).join('');
                  }

                  function renderLiabilities() {
                    const container = document.getElementById('liabRepeater');
                    container.innerHTML = (salnData.liabilities || []).map((liab, idx) => `
                      <div style="border:1.5px solid var(--g200);padding:12px;border-radius:8px;display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;">
                        <input type="text" value="${liab.description || ''}" onchange="salnData.liabilities[${idx}].description = this.value" placeholder="Description" style="padding:8px 10px;border:1px solid var(--g300);border-radius:6px;font-size:.8rem;">
                        <input type="number" value="${liab.amount || 0}" onchange="salnData.liabilities[${idx}].amount = parseFloat(this.value); calculateNW();" placeholder="Amount" style="padding:8px 10px;border:1px solid var(--g300);border-radius:6px;font-size:.8rem;">
                        <button type="button" onclick="salnData.liabilities.splice(${idx}, 1); renderAllAssets();" style="padding:6px 10px;background:#fee2e2;color:#991b1b;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;">Remove</button>
                      </div>
                    `).join('');
                  }

                  function calculateNW() {
                    const realSum = (salnData.real_property || []).reduce((sum, p) => sum + (parseFloat(p.value) || 0), 0);
                    const persSum = (salnData.personal_property || []).reduce((sum, p) => sum + (parseFloat(p.value) || 0), 0);
                    const liabSum = (salnData.liabilities || []).reduce((sum, l) => sum + (parseFloat(l.amount) || 0), 0);
                    const totalAssets = realSum + persSum;
                    const networth = totalAssets - liabSum;
                    
                    document.getElementById('totalAssets').textContent = totalAssets.toLocaleString('en-PH', {style:'currency', currency:'PHP'}).replace('₱', '₱ ');
                    document.getElementById('totalLiab').textContent = liabSum.toLocaleString('en-PH', {style:'currency', currency:'PHP'}).replace('₱', '₱ ');
                    document.getElementById('totalNet').textContent = networth.toLocaleString('en-PH', {style:'currency', currency:'PHP'}).replace('₱', '₱ ');
                  }

                  function updateTotalIncome() {
                    const sal = parseFloat(document.getElementById('inc_salary').value) || 0;
                    const bus = parseFloat(document.getElementById('inc_business').value) || 0;
                    const oth = parseFloat(document.getElementById('inc_other').value) || 0;
                    const total = sal + bus + oth;
                    document.getElementById('inc_total').value = total.toFixed(2);
                  }

                  function addRealProperty() {
                    salnData.real_property.push({description: '', value: 0});
                    renderRealProperty();
                  }

                  function addPersonalProperty() {
                    salnData.personal_property.push({description: '', value: 0});
                    renderPersonalProperty();
                  }

                  function addLiability() {
                    salnData.liabilities.push({description: '', amount: 0});
                    renderLiabilities();
                  }

                  async function saveSALN(e) {
                    e.preventDefault();
                    salnData.income_info = {
                      salary: parseFloat(document.getElementById('inc_salary').value) || 0,
                      business: parseFloat(document.getElementById('inc_business').value) || 0,
                      other: parseFloat(document.getElementById('inc_other').value) || 0
                    };
                    const res = await fetch('saln.php?action=save_saln', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify(salnData)
                    }).then(r => r.json());
                    if (res.success) {
                      alert('SALN saved successfully!');
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