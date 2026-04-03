<?php
// ============================================================
//  LGU ENRILE HRMS — Personal Data Sheet (PDS)
//  File: Employee/pds.php
//  Accessible to all employment types
//  Form: CS Form 212 (Revised 2025)
// ============================================================
require_once __DIR__ . '/_shared/auth.php';
require_once __DIR__ . '/_shared/helpers.php';

// Ensure CSRF token is initialized in session
csrf_token();

$_isAjax = !empty($_GET['action']) || !empty($_POST['action']);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    ob_start();
    header('Content-Type: application/json');
    
    // Only verify CSRF for state-changing requests (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { 
        csrf_verify(true); 
    }

    try {
        switch ($action) {
            case 'get_pds':
                // Query from pds_records table (Admin table) with all fields
                $stmt = $pdo->prepare("
                    SELECT * FROM pds_records 
                    WHERE employee_id = ? 
                    ORDER BY updated_at DESC LIMIT 1
                ");
                $stmt->execute([$employeeId]);
                $row = $stmt->fetch();
                
                // Also get employee info
                $emp = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
                $emp->execute([$employeeId]);
                $empRow = $emp->fetch();
                
                echo json_encode(['success' => true, 'data' => $row ?: null, 'employee' => $empRow ?: null]);
                break;

            case 'save_pds':
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                
                $stmt = $pdo->prepare("SELECT pds_id FROM pds_records WHERE employee_id = ?");
                $stmt->execute([$employeeId]);
                $existing = $stmt->fetch();
                
                // Serialize all JSON fields
                $personal = json_encode($data['personal_info'] ?? []);
                $family = json_encode($data['family_background'] ?? []);
                $education = json_encode($data['education'] ?? []);
                $civil_service = json_encode($data['civil_service'] ?? []);
                $work = json_encode($data['work_experience'] ?? []);
                $voluntary = json_encode($data['voluntary_work'] ?? []);
                $trainings = json_encode($data['trainings'] ?? []);
                $other = json_encode($data['other_info'] ?? []);
                $q4 = json_encode($data['q4_questions'] ?? []);
                $refs = json_encode($data['pds_references'] ?? []);
                
                if ($existing) {
                    $stmt = $pdo->prepare("
                        UPDATE pds_records
                        SET personal_info = ?, family_background = ?, education = ?,
                            civil_service = ?, work_experience = ?, voluntary_work = ?,
                            trainings = ?, other_info = ?, q4_questions = ?, pds_references = ?,
                            updated_at = NOW()
                        WHERE employee_id = ?
                    ");
                    $stmt->execute([$personal, $family, $education, $civil_service, $work, $voluntary, 
                                   $trainings, $other, $q4, $refs, $employeeId]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO pds_records
                        (employee_id, personal_info, family_background, education, civil_service, 
                         work_experience, voluntary_work, trainings, other_info, q4_questions, 
                         pds_references, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$employeeId, $personal, $family, $education, $civil_service, $work, 
                                   $voluntary, $trainings, $other, $q4, $refs]);
                }
                echo json_encode(['success' => true, 'message' => 'PDS saved successfully.']);
                break;

            case 'render':
                ob_clean();
                header('Content-Type: text/html');
                $token = csrf_token(); // Ensure token is generated
                // Debug: ensure token exists
                if (!$token) {
                    error_log('[PDS] WARNING: csrf_token() returned empty');
                    $token = bin2hex(random_bytes(32));
                    $_SESSION['csrf_token'] = $token;
                }
                ?>
                <meta name="csrf-token" content="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                <script>window.INITIAL_CSRF_TOKEN = <?php echo json_encode($token); ?>;</script>
                <div style="display:grid;gap:16px;">
                  <div class="hero" style="background:linear-gradient(130deg,#0369a1 0%,#0284c7 40%,#0ea5e9 70%,#38bdf8 100%);border-radius:14px;padding:26px;display:flex;align-items:center;justify-content:space-between;animation:fadeUp .4s ease both;">
                    <div>
                      <h1 style="font-family:'Playfair Display',serif;font-size:1.4rem;color:#fff;margin:0;">📝 Personal Data Sheet</h1>
                      <p style="font-size:.85rem;color:rgba(255,255,255,.75);margin-top:8px;">CS Form No. 212 (Revised 2025) — Official PDS</p>
                    </div>
                    <button type="button" onclick="printPDS()" style="padding:11px 22px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;">🖨️ Print</button>
                  </div>
              
                  <form id="pdsForm" style="display:grid;gap:18px;" onsubmit="savePDS(event)">
                    <!-- Personal Information Section -->
                    <div class="card">
                      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;"><div class="card-title">👤 Personal Information I</div><button type="button" onclick="resetSection(['pds_last_name','pds_first_name','pds_middle_name','pds_suffix','pds_dob','pds_pob','pds_sex','pds_civil_status','pds_height','pds_weight','pds_blood_type','pds_umid'])" style="padding:6px 12px;background:#f59e0b;color:#fff;border:none;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;">↺ Reset Section</button></div>
                      <div style="padding:20px;display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;">
                        <div><label>Surname</label><input type="text" id="pds_last_name" placeholder="SURNAME"/></div>
                        <div><label>First Name</label><input type="text" id="pds_first_name" placeholder="FIRST NAME"/></div>
                        <div><label>Middle Name</label><input type="text" id="pds_middle_name" placeholder="MIDDLE NAME"/></div>
                        <div><label>Name Ext. (Jr., Sr.)</label><input type="text" id="pds_suffix" placeholder="EXT."/></div>
                        <div><label>Date of Birth (mm/dd/yyyy)</label><input type="date" id="pds_dob"/></div>
                        <div><label>Place of Birth</label><input type="text" id="pds_pob"/></div>
                        <div><label>Sex at Birth</label><select id="pds_sex"><option value="">— Select —</option><option>Male</option><option>Female</option></select></div>
                        <div><label>Civil Status</label><select id="pds_civil_status"><option value="">— Select —</option><option>Single</option><option>Married</option><option>Widow/er</option><option>Separated</option><option>Solo Parent</option><option>Others</option></select></div>
                        <div><label>Height (m)</label><input type="text" id="pds_height" placeholder="1.65"/></div>
                        <div><label>Weight (kg)</label><input type="text" id="pds_weight" placeholder="60"/></div>
                        <div><label>Blood Type</label><select id="pds_blood_type"><option value="">— Select —</option><option>A+</option><option>A-</option><option>B+</option><option>B-</option><option>AB+</option><option>AB-</option><option>O+</option><option>O-</option></select></div>
                        <div><label>UMID ID No.</label><input type="text" id="pds_umid"/></div>
                      </div>
                    </div>

                    <!-- IDs Section -->
                    <div class="card">
                      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;"><div class="card-title">🆔 Government IDs & Identification Numbers</div><button type="button" onclick="resetSection(['pds_pagibig','pds_philhealth','pds_psn','pds_tin','pds_agency_no'])" style="padding:6px 12px;background:#f59e0b;color:#fff;border:none;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;">↺ Reset Section</button></div>
                      <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;">
                        <div><label>PAG-IBIG ID No.</label><input type="text" id="pds_pagibig"/></div>
                        <div><label>PhilHealth No.</label><input type="text" id="pds_philhealth"/></div>
                        <div><label>PhilSys Number (PSN)</label><input type="text" id="pds_psn"/></div>
                        <div><label>TIN No.</label><input type="text" id="pds_tin"/></div>
                        <div><label>Agency Employee No.</label><input type="text" id="pds_agency_no"/></div>
                        <div><label>Citizenship</label>
                          <div style="display:flex;gap:10px;margin-top:4px;">
                            <label><input type="radio" name="pds_citizenship_type" value="Filipino" checked onchange="toggleDualCit()"/> Filipino</label>
                            <label><input type="radio" name="pds_citizenship_type" value="Dual Citizenship" onchange="toggleDualCit()"/> Dual</label>
                          </div>
                          <div id="dualCitizenshipFields" style="display:none;margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <select id="pds_dual_by"><option value="">— By —</option><option>Birth</option><option>Naturalization</option></select>
                            <input type="text" id="pds_dual_country" placeholder="Country"/>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Addresses Section -->
                    <div class="card">
                      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;"><div class="card-title">📍 Residential & Permanent Address</div><button type="button" onclick="resetSection(['pds_res_housenum','pds_res_street','pds_res_subdivision','pds_res_barangay','pds_res_city','pds_res_province','pds_res_zip','pds_perm_housenum','pds_perm_street','pds_perm_subdivision','pds_perm_barangay','pds_perm_city','pds_perm_province','pds_perm_zip','pds_tel','pds_mobile','pds_email'],true)" style="padding:6px 12px;background:#f59e0b;color:#fff;border:none;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;">↺ Reset Section</button></div>
                      <div style="padding:20px;display:grid;gap:16px;">
                        <!-- Residential Address -->
                        <div>
                          <h4 style="margin:0 0 12px 0;font-size:.9rem;color:#64748b;">Residential Address</h4>
                          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
                            <input type="text" id="pds_res_housenum" placeholder="House/Block/Lot No."/>
                            <input type="text" id="pds_res_street" placeholder="Street"/>
                            <input type="text" id="pds_res_subdivision" placeholder="Subdivision/Village"/>
                            <input type="text" id="pds_res_barangay" placeholder="Barangay"/>
                            <input type="text" id="pds_res_city" placeholder="City/Municipality"/>
                            <input type="text" id="pds_res_province" placeholder="Province"/>
                            <input type="text" id="pds_res_zip" placeholder="ZIP Code"/>
                          </div>
                        </div>
                        <!-- Permanent Address -->
                        <div>
                          <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;"><input type="checkbox" id="pds_perm_same" onchange="togglePermAddress()"/> Permanent Address same as Residential</label>
                          <div id="permAddressFields" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
                            <input type="text" id="pds_perm_housenum" placeholder="House/Block/Lot No."/>
                            <input type="text" id="pds_perm_street" placeholder="Street"/>
                            <input type="text" id="pds_perm_subdivision" placeholder="Subdivision/Village"/>
                            <input type="text" id="pds_perm_barangay" placeholder="Barangay"/>
                            <input type="text" id="pds_perm_city" placeholder="City/Municipality"/>
                            <input type="text" id="pds_perm_province" placeholder="Province"/>
                            <input type="text" id="pds_perm_zip" placeholder="ZIP Code"/>
                          </div>
                        </div>
                        <!-- Contact Info -->
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">
                          <input type="tel" id="pds_tel" placeholder="Telephone No."/>
                          <input type="tel" id="pds_mobile" placeholder="Mobile No."/>
                          <input type="email" id="pds_email" placeholder="E-Mail Address"/>
                        </div>
                      </div>
                    </div>

                    <!-- Family Background-->
                    <div class="card">
                      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;"><div class="card-title">👨‍👩‍👧‍👦 Family Background II</div><button type="button" onclick="resetSection(['pds_sp_surname','pds_sp_firstname','pds_sp_ext','pds_sp_middle','pds_sp_occupation','pds_sp_employer','pds_sp_biz_addr','pds_sp_tel','pds_fa_surname','pds_fa_firstname','pds_fa_ext','pds_fa_middle','pds_mo_surname','pds_mo_firstname','pds_mo_middle'])" style="padding:6px 12px;background:#f59e0b;color:#fff;border:none;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;">↺ Reset Section</button></div>
                      <div style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                        <!-- Spouse Info -->
                        <div>
                          <h4 style="margin:0 0 12px 0;font-size:.9rem;color:#64748b;">Spouse Information</h4>
                          <input type="text" id="pds_sp_surname" placeholder="Surname" style="margin-bottom:8px;"/>
                          <input type="text" id="pds_sp_firstname" placeholder="First Name" style="margin-bottom:8px;"/>
                          <input type="text" id="pds_sp_ext" placeholder="Name Ext." style="margin-bottom:8px;"/>
                          <input type="text" id="pds_sp_middle" placeholder="Middle Name" style="margin-bottom:8px;"/>
                          <input type="text" id="pds_sp_occupation" placeholder="Occupation" style="margin-bottom:8px;"/>
                          <input type="text" id="pds_sp_employer" placeholder="Employer/Business Name" style="margin-bottom:8px;"/>
                          <input type="text" id="pds_sp_biz_addr" placeholder="Business Address" style="margin-bottom:8px;"/>
                          <input type="tel" id="pds_sp_tel" placeholder="Telephone No."/>
                        </div>
                        <!-- Father/Mother -->
                        <div>
                          <div>
                            <h4 style="margin:0 0 12px 0;font-size:.9rem;color:#64748b;">Father's Information</h4>
                            <input type="text" id="pds_fa_surname" placeholder="Surname" style="margin-bottom:8px;"/>
                            <input type="text" id="pds_fa_firstname" placeholder="First Name" style="margin-bottom:8px;"/>
                            <input type="text" id="pds_fa_ext" placeholder="Name Ext." style="margin-bottom:8px;"/>
                            <input type="text" id="pds_fa_middle" placeholder="Middle Name" style="margin-bottom:18px;"/>
                          </div>
                          <div>
                            <h4 style="margin:0 0 12px 0;font-size:.9rem;color:#64748b;">Mother's Information</h4>
                            <input type="text" id="pds_mo_surname" placeholder="Surname (Maiden)" style="margin-bottom:8px;"/>
                            <input type="text" id="pds_mo_firstname" placeholder="First Name" style="margin-bottom:8px;"/>
                            <input type="text" id="pds_mo_middle" placeholder="Middle Name"/>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Education Background -->
                    <div class="card">
                      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;"><div class="card-title">🎓 Educational Background III</div><button type="button" onclick="resetEducationRows()" style="padding:6px 12px;background:#f59e0b;color:#fff;border:none;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;">↺ Reset Section</button></div>
                      <div style="padding:20px;">
                        <div id="pds_edu_list" style="display:grid;gap:10px;"></div>
                        <button type="button" onclick="addEducationRow()" style="margin-top:12px;padding:10px 14px;background:#0ea5e9;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">+ Add Education</button>
                      </div>
                    </div>

                    <!-- Work Experience -->
                    <div class="card">
                      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;"><div class="card-title">💼 Work Experience V</div><button type="button" onclick="resetWorkRows()" style="padding:6px 12px;background:#f59e0b;color:#fff;border:none;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;">↺ Reset Section</button></div>
                      <div style="padding:20px;">
                        <div id="pds_we_list" style="display:grid;gap:10px;"></div>
                        <button type="button" onclick="addWorkRow()" style="margin-top:12px;padding:10px 14px;background:#0ea5e9;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">+ Add Work Experience</button>
                      </div>
                    </div>

                    <!-- Submit Buttons -->
                    <div style="display:flex;gap:10px;margin-bottom:20px;">
                      <button type="submit" style="flex:1;padding:12px 16px;background:#0ea5e9;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">💾 Save PDS</button>
                      <button type="button" onclick="printPDS()" style="padding:12px 16px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">🖨️ Print PDS</button>
                    </div>
                  </form>
                </div>

                <script>
if(!window.pdsData) window.pdsData = {};
let pdsRowCount = {edu: 0, we: 0};
let csrfToken = '';

// Initialize CSRF token immediately
function initCSRFToken() {
  // First priority: window.INITIAL_CSRF_TOKEN set by PHP
  if (window.INITIAL_CSRF_TOKEN) {
    csrfToken = window.INITIAL_CSRF_TOKEN;
    console.log('✓ CSRF token from window.INITIAL_CSRF_TOKEN');
    return csrfToken;
  }
  
  // Second priority: meta tag
  let token = document.querySelector('meta[name="csrf-token"]')?.content;
  if (token && token.trim()) {
    csrfToken = token;
    console.log('✓ CSRF token from meta tag');
    return csrfToken;
  }
  
  // Third priority: search all meta tags as fallback
  const allMetas = document.querySelectorAll('meta');
  for (let meta of allMetas) {
    if (meta.getAttribute('name') === 'csrf-token') {
      const content = meta.getAttribute('content');
      if (content && content.trim()) {
        csrfToken = content;
        console.log('✓ CSRF token found in meta tag array');
        return csrfToken;
      }
    }
  }
  
  console.error('❌ CSRF token NOT found - page may not be properly initialized');
  return '';
}

// Try to initialize immediately (for inline scripts)
csrfToken = initCSRFToken();

// Also initialize on DOM ready as fallback
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    if (!csrfToken) {
      console.log('Re-attempting CSRF token initialization on DOMContentLoaded');
      csrfToken = initCSRFToken();
    }
  });
} else {
  if (!csrfToken) {
    console.log('Document already loaded, initializing CSRF token');
    csrfToken = initCSRFToken();
  }
}

// Ensure we can refresh token if needed
window.refreshCSRFToken = function() {
  csrfToken = initCSRFToken();
  if (!csrfToken) {
    console.error('Failed to refresh CSRF token');
  }
  return csrfToken;
}

function pdsGet(id) {
  const el = document.getElementById(id);
  return el ? (el.type === 'checkbox' ? el.checked : el.value) : '';
}

function pdsSet(id, val) {
  const el = document.getElementById(id);
  if (el) {
    if (el.type === 'checkbox') el.checked = !!val;
    else el.value = val || '';
  }
}

function pdsRadioGet(name) {
  const el = document.querySelector(`input[name="${name}"]:checked`);
  return el ? el.value : '';
}

function toggleDualCit() {
  const isDual = pdsRadioGet('pds_citizenship_type') === 'Dual Citizenship';
  document.getElementById('dualCitizenshipFields').style.display = isDual ? 'grid' : 'none';
}

function togglePermAddress() {
  const same = document.getElementById('pds_perm_same').checked;
  ['pds_perm_housenum','pds_perm_street','pds_perm_subdivision','pds_perm_barangay','pds_perm_city','pds_perm_province','pds_perm_zip'].forEach(id => {
    document.getElementById(id).disabled = same;
    if (same) document.getElementById(id).value = document.getElementById('pds_res_' + id.split('pds_perm_')[1]).value || '';
  });
}

function collectRows(containerId, fields) {
  const rows = [];
  document.querySelectorAll(`#${containerId} .data-row`).forEach(row => {
    const data = {};
    fields.forEach(f => {
      const input = row.querySelector(`[data-field="${f}"]`);
      data[f] = input ? input.value : '';
    });
    rows.push(data);
  });
  return rows;
}

function addEducationRow() {
  const cont = document.getElementById('pds_edu_list');
  const idx = pdsRowCount.edu++;
  const row = document.createElement('div');
  row.className = 'data-row';
  row.style.cssText = 'border:1px solid #ccc;padding:10px;border-radius:6px;display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:8px;align-items:end;';
  row.innerHTML = `
    <input type="text" data-field="level" placeholder="Level" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:.85rem;">
    <input type="text" data-field="school" placeholder="School/University" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:.85rem;">
    <input type="text" data-field="degree" placeholder="Degree/Course" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:.85rem;">
    <input type="text" data-field="honors" placeholder="Honors" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:.85rem;">
    <button type="button" onclick="this.parentElement.remove()" style="padding:5px 10px;background:#ef4444;color:#fff;border:none;border-radius:4px;font-size:.75rem;cursor:pointer;">✕</button>
  `;
  cont.appendChild(row);
}

function addWorkRow() {
  const cont = document.getElementById('pds_we_list');
  const idx = pdsRowCount.we++;
  const row = document.createElement('div');
  row.className = 'data-row';
  row.style.cssText = 'border:1px solid #ccc;padding:10px;border-radius:6px;display:grid;grid-template-columns:1fr 1fr 1.2fr 1fr 1fr auto;gap:8px;align-items:end;';
  row.innerHTML = `
    <input type="date" data-field="date_from" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:.85rem;">
    <input type="date" data-field="date_to" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:.85rem;">
    <input type="text" data-field="position" placeholder="Position" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:.85rem;">
    <input type="text" data-field="dept_agency" placeholder="Dept/Agency" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:.85rem;">
    <input type="text" data-field="status" placeholder="Status" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:.85rem;">
    <button type="button" onclick="this.parentElement.remove()" style="padding:5px 10px;background:#ef4444;color:#fff;border:none;border-radius:4px;font-size:.75rem;cursor:pointer;">✕</button>
  `;
  cont.appendChild(row);
}

// Load existing data
(async function() {
  const res = await fetch('pds.php?action=get_pds').then(r => r.json());
  if (res.success && res.data) {
    const d = res.data;
    const pi = safeJson(d.personal_info) || {};
    const fb = safeJson(d.family_background) || {};
    const ed = safeJson(d.education) || [];
    const we = safeJson(d.work_experience) || [];
    
    // Fill personal info
    Object.keys(pi).forEach(k => pdsSet(`pds_${k}`, pi[k]));
    Object.keys(fb).forEach(k => pdsSet(`pds_${k}`, fb[k]));
    
    // Add edu rows
    ed.forEach(e => { addEducationRow(); });
    document.querySelectorAll('#pds_edu_list .data-row').forEach((row, i) => {
      if (ed[i]) Object.keys(ed[i]).forEach(f => {
        const inp = row.querySelector(`[data-field="${f}"]`);
        if (inp) inp.value = ed[i][f] || '';
      });
    });
    
    // Add work rows
    we.forEach(w => { addWorkRow(); });  
    document.querySelectorAll('#pds_we_list .data-row').forEach((row, i) => {
      if (we[i]) Object.keys(we[i]).forEach(f => {
        const inp = row.querySelector(`[data-field="${f}"]`);
        if (inp) inp.value = we[i][f] || '';
      });
    });
    
    toggleDualCit();
    togglePermAddress();
  }
})();

function safeJson(str) {
  try { return str ? JSON.parse(str) : null; }
  catch (e) { return null; }
}

function showToast(msg, type='success') {
  document.querySelectorAll('.toast').forEach(t => t.remove());
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.textContent = msg;
  t.style.cssText = 'position:fixed;top:20px;right:20px;padding:12px 16px;background:' + 
    (type==='success'?'#10b981':(type==='error'?'#ef4444':'#3b82f6')) + 
    ';color:#fff;border-radius:6px;z-index:9999;box-shadow:0 4px 6px rgba(0,0,0,0.1);';
  document.body.appendChild(t);
  setTimeout(() => {
    t.style.opacity = '0';
    t.style.transition = 'opacity 0.3s';
    setTimeout(() => t.remove(), 300);
  }, 3500);
}

function resetSection(fieldIds, resetRadios = false) {
  fieldIds.forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      if (el.type === 'checkbox') el.checked = false;
      else if (el.type === 'text' || el.type === 'email' || el.type === 'tel' || el.type === 'date') el.value = '';
      else if (el.tagName === 'SELECT') el.value = '';
    }
  });
  if (resetRadios) {
    document.querySelectorAll('input[type="radio"][name="pds_citizenship_type"]').forEach(r => {
      r.checked = (r.value === 'Filipino');
    });
    toggleDualCit();
    togglePermAddress();
  }
  showToast('✅ Section reset successfully!', 'success');
}

function resetEducationRows() {
  const cont = document.getElementById('pds_edu_list');
  cont.innerHTML = '';
  pdsRowCount.edu = 0;
  showToast('✅ Education section reset!', 'success');
}

function resetWorkRows() {
  const cont = document.getElementById('pds_we_list');
  cont.innerHTML = '';
  pdsRowCount.we = 0;
  showToast('✅ Work Experience section reset!', 'success');
}

async function savePDS(e) {
  e.preventDefault();
  
  // Ensure we have a CSRF token
  if (!csrfToken) {
    csrfToken = initCSRFToken();
    if (!csrfToken) {
      console.error('CSRF token missing, attempting to refresh from page');
      csrfToken = window.INITIAL_CSRF_TOKEN || '';
      if (!csrfToken) {
        showToast('🔒 Security error: No CSRF token available. Please refresh the page.', 'error');
        return;
      }
    }
  }
  
  console.log('Saving PDS with token:', csrfToken.substring(0, 8) + '...');
  
  const pi = {};
  const fb = {};
  ['last_name','first_name','middle_name','suffix','dob','pob','sex','civil_status','height','weight','blood_type','umid','pagibig','philhealth','psn','tin','agency_no','dual_by','dual_country','res_housenum','res_street','res_subdivision','res_barangay','res_city','res_province','res_zip','perm_housenum','perm_street','perm_subdivision','perm_barangay','perm_city','perm_province','perm_zip','perm_same','tel','mobile','email','gov_id_type','gov_id_no'].forEach(k => pi[k] = pdsGet(`pds_${k}`));
  ['sp_surname','sp_firstname','sp_ext','sp_middle','sp_occupation','sp_employer','sp_biz_addr','sp_tel','fa_surname','fa_firstname','fa_ext','fa_middle','mo_surname','mo_firstname','mo_middle'].forEach(k => fb[k] = pdsGet(`pds_${k}`));
  
  const data = {
    personal_info: pi,
    family_background: fb,
    education: collectRows('pds_edu_list', ['level','school','degree','honors','from','to','units','year_grad']),
    civil_service: [],
    work_experience: collectRows('pds_we_list', ['date_from','date_to','position','dept_agency','salary_grade','status']),
    voluntary_work: [],
    trainings: [],
    other_info: {}, 
    q4_questions: {},
    pds_references: []
  };
  
  try {
    console.log('Sending save request with CSRF header');
    const res = await fetch('pds.php?action=save_pds', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken || ''
      },
      body: JSON.stringify(data)
    }).then(r => r.json());
    
    if (res.success) {
      showToast('✅ PDS saved successfully!', 'success');
    } else {
      console.error('Save failed:', res);
      showToast('❌ Error: ' + (res.message || 'Unknown error'), 'error');
      // If CSRF error, refresh token and suggest retry
      if (res.message && (res.message.includes('CSRF') || res.message.includes('token'))) {
        console.log('Detected CSRF error, refreshing token...');
        window.refreshCSRFToken();
      }
    }
  } catch (err) {
    showToast('❌ Network error: ' + err.message, 'error');
  }
}

function printPDS() {
  // Open the dedicated print page which uses the exact CS Form 212 government layout
  window.open('pds_print.php', '_blank', 'width=1050,height=1200');
}

function buildPDSPrintHTML(d, empRow) {
  const pi = safeJson(d.personal_info) || {};
  const fb = safeJson(d.family_background) || {};
  const ed = safeJson(d.education) || [];
  const we = safeJson(d.work_experience) || [];
  const cs = safeJson(d.civil_service) || [];
  const vw = safeJson(d.voluntary_work) || [];
  const tr = safeJson(d.trainings) || [];
  const oi = safeJson(d.other_info) || {};
  const q4 = safeJson(d.q4_questions) || {};
  const refs = safeJson(d.pds_references) || [];
  
  const v = x => (x===null||x===undefined||x===''||x==='null'||x==='undefined') ? '' : String(x);
  const fmtD = dt => {
    if(!dt||dt==='0000-00-00') return '';
    try { const x=new Date(dt); return isNaN(x)?dt:(x.getMonth()+1).toString().padStart(2,'0')+'/'+x.getDate().toString().padStart(2,'0')+'/'+x.getFullYear(); }
    catch(e){return dt||'';}
  };
  
  const cb = val => (val===true||val==='1'||val===1||val==='Yes'||val==='true') ? '☑' : '☐';
  const pad = (arr,n) => { const a=[...(arr||[])]; while(a.length<n) a.push({}); return a; };
  
  const edPad = pad(ed, 5);
  const wePad = pad(we, 9);
  const csPad = pad(cs, 2);
  const vwPad = pad(vw, 2);
  const trPad = pad(tr, 3);
  
  const css = `
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Arial Narrow',Arial,sans-serif;font-size:6.5pt;color:#000;background:#fff;}
    @page{size:A4 portrait;margin:4mm 5mm;}
    @media print{body{margin:0;padding:0;}html{margin:0;padding:0;}}
    table{border-collapse:collapse;width:100%;}
    td,th{border:0.5pt solid #000;padding:1.5pt 2pt;vertical-align:top;font-size:6pt;}
    .lbl{font-size:4.5pt;font-weight:700;text-transform:uppercase;color:#333;display:block;line-height:1.0;margin-bottom:1pt;}
    .val{font-size:6.5pt;min-height:9pt;display:block;padding:1pt 0;word-break:break-word;line-height:1.1;}
    .sec{background:#000;color:#fff;font-size:5.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:1.5pt 3pt;}
    .hdr{background:#e0e0e0;font-size:5pt;font-weight:700;text-align:center;padding:1.5pt 2pt;}
    .yn{display:inline-block;border:0.5pt solid #000;width:6pt;height:6pt;text-align:center;font-size:5pt;line-height:6pt;margin:0 2pt 0 0;}
    .pg-num{font-size:4.8pt;font-style:italic;text-align:right;padding:2pt 3pt;margin-top:2pt;page-break-inside:avoid;}
    .page{page-break-after:always;padding:0;page-break-inside:avoid;}
    .page:last-child{page-break-after:avoid;}
    .page-wrapper{display:flex;flex-direction:column;height:100%;page-break-after:always;}
    .page-content{flex:1;}
  `;
  
  const pageHdr = `
  <div style="margin-bottom:2pt;">
  <table style="border-collapse:collapse;width:100%;"><tr>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;width:15%;vertical-align:center;font-size:8pt;font-weight:bold;">
      CS Form No. 212<br><span style="font-size:6pt;">Revised 2025</span>
    </td>
    <td style="border:0.5pt solid #000;border-left:none;padding:3pt 5pt;text-align:center;vertical-align:middle;flex:1;">
      <div style="font-size:13pt;font-weight:900;text-transform:uppercase;letter-spacing:.02em;">Personal Data Sheet</div>
    </td>
  </tr></table>
  <table style="margin-top:0;border-collapse:collapse;width:100%;"><tr>
    <td style="border:0.5pt solid #000;padding:2pt 3pt;font-size:4.5pt;line-height:1.3;" colspan="8">
      <strong><em>WARNING:</em></strong><em> Any misrepresentation made in the Personal Data Sheet shall cause the filing of administrative/criminal case/s against the person concerned. Print legibly. Tick appropriate boxes. Use separate sheet if necessary. Indicate N/A if not applicable.</em>
    </td>
  </tr></table>
  </div>`;

  // PAGE 1: Personal Information (I)
  const page1 = `<div class="page">
  <div class="page-content">
  ${pageHdr}
  <table><tr><td colspan="8" class="sec">I. PERSONAL INFORMATION</td></tr></table>
  <table>
    <tr>
      <td style="width:3%;"><span class="lbl">1.</span></td>
      <td style="width:24%;"><span class="lbl">Surname</span><span class="val">${v(pi.last_name)}</span></td>
      <td style="width:3%;"><span class="lbl">2.</span></td>
      <td style="width:24%;"><span class="lbl">First Name</span><span class="val">${v(pi.first_name)}</span></td>
      <td style="width:18%;"><span class="lbl">Middle Name</span><span class="val">${v(pi.middle_name)}</span></td>
      <td style="width:14%;"><span class="lbl">Name Ext. (Jr., Sr.)</span><span class="val">${v(pi.suffix)}</span></td>
      <td style="width:14%;"><span class="lbl">Citizenship</span><div style="margin-top:1.5pt;font-size:5pt;"><span class="yn">${pi.citizenship_type==='Filipino'||!pi.citizenship_type?'✓':''}</span> Filipino <span class="yn" style="margin-left:4pt;">${pi.citizenship_type==='Dual'?'✓':''}</span> Dual</div></td>
    </tr>
  </table>
  <table style="margin-top:1pt;">
    <tr>
      <td style="width:3%;"><span class="lbl">3.</span></td>
      <td style="width:20%;"><span class="lbl">Date of Birth (mm/dd/yyyy)</span><span class="val">${fmtD(pi.dob)}</span></td>
      <td style="width:3%;"><span class="lbl">4.</span></td>
      <td style="width:20%;"><span class="lbl">Place of Birth</span><span class="val">${v(pi.pob)}</span></td>
      <td style="width:14%;"><span class="lbl">Sex</span><div style="margin-top:1.5pt;font-size:5pt;"><span class="yn">${pi.sex==='Male'?'✓':''}</span> M <span class="yn" style="margin-left:8pt;">${pi.sex==='Female'?'✓':''}</span> F</div></td>
      <td style="width:20%;"><span class="lbl">Civil Status</span><div style="margin-top:1.5pt;font-size:4.5pt;line-height:1.2;"><span class="yn">${pi.civil_status==='Single'?'✓':''}</span>S <span class="yn" style="margin-left:2pt;">${pi.civil_status==='Married'?'✓':''}</span>M <span class="yn" style="margin-left:2pt;">${pi.civil_status==='Widow/er'?'✓':''}</span>W</div></td>
      <td style="width:20%;"><span class="lbl">Blood Type</span><span class="val">${v(pi.blood_type)}</span></td>
    </tr>
  </table>
  <table style="margin-top:1pt;">
    <tr>
      <td style="width:3%;"><span class="lbl">5.</span></td>
      <td style="width:20%;"><span class="lbl">Height (m)</span><span class="val">${v(pi.height)}</span></td>
      <td style="width:3%;"><span class="lbl">6.</span></td>
      <td style="width:20%;"><span class="lbl">Weight (kg)</span><span class="val">${v(pi.weight)}</span></td>
      <td style="width:54%;"><span class="lbl">7. UMID / PAG-IBIG / PhilHealth / PSN / TIN / Agency Employee No.</span><span class="val" style="font-size:6pt;">${[v(pi.umid),v(pi.pagibig),v(pi.philhealth),v(pi.psn),v(pi.tin),v(pi.agency_no)].filter(Boolean).join(' | ')}</span></td>
    </tr>
  </table>
  <table style="margin-top:1pt;"><tr><td colspan="8" class="sec">RESIDENCE & CONTACT INFORMATION</td></tr></table>
  <table>
    <tr>
      <td style="width:50%;"><span class="lbl">Residential Address (House/Block/Lot No., Street, Subdivision/Village, Barangay, City/Municipality, Province, ZIP Code)</span><span class="val">${[v(pi.res_housenum),v(pi.res_street),v(pi.res_subdivision),v(pi.res_barangay),v(pi.res_city),v(pi.res_province),v(pi.res_zip)].filter(Boolean).join(', ')}</span></td>
      <td style="width:50%;"><span class="lbl">Permanent Address (House/Block/Lot No., Street, Subdivision/Village, Barangay, City/Municipality, Province, ZIP Code)</span><span class="val">${[v(pi.perm_housenum),v(pi.perm_street),v(pi.perm_subdivision),v(pi.perm_barangay),v(pi.perm_city),v(pi.perm_province),v(pi.perm_zip)].filter(Boolean).join(', ')}</span></td>
    </tr>
  </table>
  <table style="margin-top:1pt;">
    <tr>
      <td style="width:3%;"><span class="lbl">8.</span></td>
      <td style="width:32%;"><span class="lbl">Telephone No.</span><span class="val">${v(pi.tel)}</span></td>
      <td style="width:3%;"><span class="lbl">9.</span></td>
      <td style="width:32%;"><span class="lbl">Mobile No.</span><span class="val">${v(pi.mobile)}</span></td>
      <td style="width:30%;"><span class="lbl">10. Email Address</span><span class="val">${v(pi.email)}</span></td>
    </tr>
  </table>
  </div>
  <div class="pg-num">CS FORM 212 (Revised 2025), Page 1 of 4</div>
  </div>`;

  // PAGE 2: Family Background (II) & Education (III)
  const page2 = `<div class="page">
  <div class="page-content">
  ${pageHdr}
  <table><tr><td colspan="8" class="sec">II. FAMILY BACKGROUND</td></tr></table>
  <table>
    <tr>
      <td style="width:3%;"><span class="lbl">11.</span></td>
      <td style="width:47%;"><span class="lbl">Spouse's Surname, First Name, Middle Name, Name Ext.</span><span class="val">${[v(fb.sp_surname),v(fb.sp_firstname),v(fb.sp_middle),v(fb.sp_ext)].filter(Boolean).join(' ')}</span><span class="lbl" style="margin-top:3pt;">Occupation:</span><span class="val">${v(fb.sp_occupation)}</span></td>
      <td style="width:3%;"><span class="lbl">12.</span></td>
      <td style="width:47%;"><span class="lbl">Father's Surname, First Name, Middle Name</span><span class="val">${[v(fb.fa_surname),v(fb.fa_firstname),v(fb.fa_middle)].filter(Boolean).join(' ')}</span></td>
    </tr>
  </table>
  <table style="margin-top:1pt;">
    <tr>
      <td style="width:3%;"><span class="lbl">13.</span></td>
      <td style="width:97%;"><span class="lbl">Mother's Surname/Maiden Name, First Name, Middle Name</span><span class="val">${[v(fb.mo_surname),v(fb.mo_firstname),v(fb.mo_middle)].filter(Boolean).join(' ')}</span></td>
    </tr>
  </table>
  <table style="margin-top:1pt;"><tr><td colspan="8" class="sec">III. EDUCATIONAL BACKGROUND</td></tr></table>
  <table>
    <thead><tr>
      <td class="hdr" style="width:3%;">14.</td>
      <td class="hdr" style="width:12%;">LEVEL</td>
      <td class="hdr" style="width:30%;">SCHOOL/UNIVERSITY</td>
      <td class="hdr" style="width:22%;">DEGREE/COURSE/CREDENTIAL</td>
      <td class="hdr" style="width:11%;">PERIOD From</td>
      <td class="hdr" style="width:11%;">To</td>
      <td class="hdr" style="width:11%;">UNITS EARNED/ YEAR GRADUATED</td>
    </tr></thead>
    <tbody>
      <tr><td></td><td style="text-align:center;font-size:5.5pt;font-weight:600;">Elementary</td><td style="font-size:5.5pt;">${v((edPad[0]||{}).school)}</td><td style="font-size:5.5pt;">${v((edPad[0]||{}).degree)}</td><td style="text-align:center;font-size:5.5pt;"></td><td style="text-align:center;font-size:5.5pt;"></td><td style="text-align:center;font-size:5.5pt;">${v((edPad[0]||{}).honors)}</td></tr>
      <tr><td></td><td style="text-align:center;font-size:5.5pt;font-weight:600;">Secondary</td><td style="font-size:5.5pt;">${v((edPad[1]||{}).school)}</td><td style="font-size:5.5pt;">${v((edPad[1]||{}).degree)}</td><td style="text-align:center;font-size:5.5pt;"></td><td style="text-align:center;font-size:5.5pt;"></td><td style="text-align:center;font-size:5.5pt;">${v((edPad[1]||{}).honors)}</td></tr>
      <tr><td></td><td style="text-align:center;font-size:5.5pt;font-weight:600;">Vocational</td><td style="font-size:5.5pt;">${v((edPad[2]||{}).school)}</td><td style="font-size:5.5pt;">${v((edPad[2]||{}).degree)}</td><td style="text-align:center;font-size:5.5pt;"></td><td style="text-align:center;font-size:5.5pt;"></td><td style="text-align:center;font-size:5.5pt;">${v((edPad[2]||{}).honors)}</td></tr>
      <tr><td></td><td style="text-align:center;font-size:5.5pt;font-weight:600;">College</td><td style="font-size:5.5pt;">${v((edPad[3]||{}).school)}</td><td style="font-size:5.5pt;">${v((edPad[3]||{}).degree)}</td><td style="text-align:center;font-size:5.5pt;"></td><td style="text-align:center;font-size:5.5pt;"></td><td style="text-align:center;font-size:5.5pt;">${v((edPad[3]||{}).honors)}</td></tr>
      <tr><td></td><td style="text-align:center;font-size:5.5pt;font-weight:600;">Graduate</td><td style="font-size:5.5pt;">${v((edPad[4]||{}).school)}</td><td style="font-size:5.5pt;">${v((edPad[4]||{}).degree)}</td><td style="text-align:center;font-size:5.5pt;"></td><td style="text-align:center;font-size:5.5pt;"></td><td style="text-align:center;font-size:5.5pt;">${v((edPad[4]||{}).honors)}</td></tr>
    </tbody>
  </table>
  </div>
  <div class="pg-num">CS FORM 212 (Revised 2025), Page 2 of 4</div>
  </div>`;

  // PAGE 3: Civil Service (IV), Work Experience (V), Voluntary Work (VI)
  const page3 = `<div class="page">
  <div class="page-content">
  ${pageHdr}
  <table><tr><td colspan="8" class="sec">IV. CIVIL SERVICE ELIGIBILITY</td></tr></table>
  <table>
    <thead><tr>
      <td class="hdr" style="width:3%;">15.</td>
      <td class="hdr" style="width:25%;">ELIGIBILITY</td>
      <td class="hdr" style="width:20%;">RATING</td>
      <td class="hdr" style="width:20%;">DATE OF EXAMINATION/CONFERMENT</td>
      <td class="hdr" style="width:32%;">PLACE OF EXAMINATION/CONFERMENT</td>
    </tr></thead>
    <tbody>
      ${csPad.slice(0,2).map(e=>`<tr><td></td><td style="font-size:5.5pt;">${v(e.eligibility)}</td><td style="font-size:5.5pt;text-align:center;">${v(e.rating)}</td><td style="font-size:5.5pt;text-align:center;">${fmtD(e.date_exam)}</td><td style="font-size:5.5pt;">${v(e.place)}</td></tr>`).join('')}
      <tr><td colspan="5" style="height:15pt;"></td></tr>
    </tbody>
  </table>
  <table><tr><td colspan="8" class="sec">V. WORK EXPERIENCE</td></tr></table>
  <table>
    <thead><tr>
      <td class="hdr" style="width:3%;">16.</td>
      <td class="hdr" style="width:12%;">PERIOD From</td>
      <td class="hdr" style="width:12%;">To</td>
      <td class="hdr" style="width:22%;">POSITION TITLE</td>
      <td class="hdr" style="width:19%;">DEPARTMENT/AGENCY</td>
      <td class="hdr" style="width:16%;">MONTHLY SALARY</td>
      <td class="hdr" style="width:16%;">SALARY/JOB STATUS</td>
    </tr></thead>
    <tbody>
      ${wePad.slice(0,5).map(e=>`<tr><td></td><td style="font-size:5.5pt;text-align:center;">${fmtD(e.date_from)}</td><td style="font-size:5.5pt;text-align:center;">${fmtD(e.date_to)}</td><td style="font-size:5.5pt;">${v(e.position)}</td><td style="font-size:5.5pt;">${v(e.dept_agency)}</td><td style="font-size:5.5pt;text-align:right;">${v(e.salary_grade)}</td><td style="font-size:5.5pt;">${v(e.status)}</td></tr>`).join('')}
      <tr><td colspan="7" style="height:8pt;"></td></tr>
    </tbody>
  </table>
  </div>
  <div class="pg-num">CS FORM 212 (Revised 2025), Page 3 of 4</div>
  </div>`;

  // PAGE 4: More Work Experience, Trainings, Other Info, Q&A, References
  const page4 = `<div class="page">
  <div class="page-content">
  ${pageHdr}
  <table style="margin-bottom:1pt;"><tr><td colspan="8" class="sec">V. WORK EXPERIENCE (CONTINUED)</td></tr></table>
  <table>
    <tbody>
      ${wePad.slice(5,9).map(e=>`<tr><td style="width:3%;"></td><td style="width:12%;text-align:center;font-size:5.5pt;">${fmtD(e.date_from)}</td><td style="width:12%;text-align:center;font-size:5.5pt;">${fmtD(e.date_to)}</td><td style="width:22%;font-size:5.5pt;">${v(e.position)}</td><td style="width:19%;font-size:5.5pt;">${v(e.dept_agency)}</td><td style="width:16%;text-align:right;font-size:5.5pt;">${v(e.salary_grade)}</td><td style="width:16%;font-size:5.5pt;">${v(e.status)}</td></tr>`).join('')}
      <tr><td colspan="7" style="height:8pt;"></td></tr>
    </tbody>
  </table>
  <table><tr><td colspan="8" class="sec">VI. VOLUNTARY WORK / COMMUNITY INVOLVEMENT</td></tr></table>
  <table>
    <thead><tr>
      <td class="hdr" style="width:3%;">17.</td>
      <td class="hdr" style="width:28%;">ORGANIZATION NAME</td>
      <td class="hdr" style="width:24%;">NATURE OF WORK</td>
      <td class="hdr" style="width:15%;">DATE From To</td>
      <td class="hdr" style="width:30%;">NUMBER OF HOURS</td>
    </tr></thead>
    <tbody>
      ${vwPad.map(e=>`<tr><td></td><td style="font-size:5.5pt;">${v(e.org_name)}</td><td style="font-size:5.5pt;">${v(e.nature)}</td><td style="font-size:5.5pt;">${v(e.period)}</td><td style="font-size:5.5pt;text-align:right;">${v(e.hours)}</td></tr>`).join('')}
      <tr><td colspan="5" style="height:12pt;"></td></tr>
    </tbody>
  </table>
  <table><tr><td colspan="8" class="sec">VII. TRAINING PROGRAMS ATTENDED / SEMINARS / WORKSHOPS</td></tr></table>
  <table>
    <thead><tr>
      <td class="hdr" style="width:3%;">18.</td>
      <td class="hdr" style="width:30%;">PROGRAM/COURSE TITLE</td>
      <td class="hdr" style="width:22%;">CONDUCTED/SPONSORED BY</td>
      <td class="hdr" style="width:22%;">DURATION</td>
      <td class="hdr" style="width:23%;">DATE ATTENDED</td>
    </tr></thead>
    <tbody>
      ${trPad.map(e=>`<tr><td></td><td style="font-size:5.5pt;">${v(e.program)}</td><td style="font-size:5.5pt;">${v(e.sponsor)}</td><td style="font-size:5.5pt;text-align:center;">${v(e.duration)}</td><td style="font-size:5.5pt;text-align:center;">${fmtD(e.date)}</td></tr>`).join('')}
    </tbody>
  </table>
  <table style="margin-top:1pt;"><tr><td colspan="8" class="sec">VIII. OTHER INFORMATION</td></tr></table>
  <table>
    <tr>
      <td style="width:50%;"><span class="lbl">19. Skills (Special Abilities, Hobbies, Interests)</span><span class="val">${v(oi.skills)}</span></td>
      <td style="width:50%;"><span class="lbl">20. Recognitions (Awards, Honors, Commendations)</span><span class="val">${v(oi.recognitions)}</span></td>
    </tr>
  </table>
  <table style="margin-top:1pt;"><tr><td colspan="8" class="sec">IX. PERSONAL REFERENCES</td></tr></table>
  <table>
    <thead><tr>
      <td class="hdr" style="width:3%;">21.</td>
      <td class="hdr" style="width:35%;">NAME & ADDRESS</td>
      <td class="hdr" style="width:20%;">TELEPHONE NO.</td>
      <td class="hdr" style="width:42%;">POSITION/PROFESSION</td>
    </tr></thead>
    <tbody>
      ${pad(refs,3).map(r=>`<tr><td></td><td style="font-size:5.5pt;">${v(r.name)}</td><td style="font-size:5.5pt;">${v(r.tel)}</td><td style="font-size:5.5pt;">${v(r.position)}</td></tr>`).join('')}
    </tbody>
  </table>
  </div>
  <div class="pg-num">CS FORM 212 (Revised 2025), Page 4 of 4</div>
  </div>`;

  return `<!DOCTYPE html><html><head>
    <meta charset="UTF-8"/>
    <title>PDS — CS Form 212 (Revised 2025)</title>
    <style>${css}</style>
  </head><body>
    ${page1}${page2}${page3}${page4}
  </body></html>`;
}
                  /* Toast styling */
                  .toast {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    padding: 12px 16px;
                    color: white;
                    border-radius: 6px;
                    z-index: 9999;
                    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                  }
                  .toast.success { background: #10b981; }
                  .toast.error { background: #ef4444; }
                  .toast.info { background: #3b82f6; }
                </style>
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