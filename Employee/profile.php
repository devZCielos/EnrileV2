<?php
// ============================================================
//  LGU ENRILE HRMS — Employee Portal Profile
//  File: Employee/profile.php
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
            case 'profile':
                $stmt = $pdo->prepare("
                    SELECT e.*, d.name AS department_name
                    FROM employees e
                    LEFT JOIN departments d ON d.department_id = e.department_id
                    WHERE e.user_id = ?
                ");
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                echo json_encode($row ? ['success' => true, 'data' => $row] : ['success' => false, 'message' => 'No profile found.']);
                break;

            case 'upload_photo':
                if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
                    break;
                }
                $file = $_FILES['photo'];
                $mime = mime_content_type($file['tmp_name']);
                if (!preg_match('~^image/(png|jpeg|gif|webp)$~', $mime)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid image format.']);
                    break;
                }
                if ($file['size'] > 5242880) {
                    echo json_encode(['success' => false, 'message' => 'File size exceeds 5 MB.']);
                    break;
                }
                $updir = dirname(__DIR__) . '/uploads/profile_photos';
                if (!is_dir($updir)) mkdir($updir, 0755, true);
                $newfile = $updir . '/' . $employeeId . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
                if (move_uploaded_file($file['tmp_name'], $newfile)) {
                    $pdo->prepare("UPDATE employees SET profile_photo = ? WHERE employee_id = ?")
                        ->execute([$employeeId . '.' . pathinfo($file['name'], PATHINFO_EXTENSION), $employeeId]);
                    echo json_encode(['success' => true, 'message' => 'Photo updated.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Upload failed.']);
                }
                break;

            case 'update_personal':
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                $allowed = ['first_name', 'last_name', 'middle_name', 'gender', 'mobile_number', 'email_address', 'address'];
                $updates = [];
                foreach ($allowed as $k) {
                    if (array_key_exists($k, $data)) {
                        $updates[$k] = sanitize($data[$k]);
                    }
                }
                if (empty($updates)) {
                    echo json_encode(['success' => false, 'message' => 'No data to update.']);
                    break;
                }
                $setClauses = array_map(fn($k) => "$k = ?", array_keys($updates));
                $setSQL = implode(', ', $setClauses);
                $vals = array_values($updates);
                $vals[] = $employeeId;
                $stmt = $pdo->prepare("UPDATE employees SET $setSQL WHERE employee_id = ?");
                $stmt->execute($vals);
                echo json_encode(['success' => true, 'message' => 'Profile updated.']);
                break;

            case 'render':
                // Return HTML for profile page
                ob_clean();
                header('Content-Type: text/html');
                ?>
                <div class="hero" style="background:linear-gradient(130deg,#7c3aed 0%,#a855f7 40%,#c084fc 70%,#e879f9 100%);border-radius:14px;padding:30px;display:flex;align-items:center;gap:25px;margin-bottom:22px;position:relative;overflow:hidden;animation:fadeUp .4s ease both;">
                  <div style="position:relative;">
                    <div id="heroAvatar" style="width:88px;height:88px;border-radius:50%;background:rgba(255,255,255,.25);border:4px solid rgba(255,255,255,.60);display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 6px 20px rgba(0,0,0,.2);overflow:hidden;background-size:cover;background-position:center;"></div>
                    <button class="profile-edit-av" onclick="document.getElementById('photoInput').click()" style="position:absolute;bottom:0;right:0;width:32px;height:32px;border-radius:50%;background:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:0 2px 8px rgba(0,0,0,.15);transition:all .2s;">📷</button>
                  </div>
                  <div class="hero-body">
                    <h2 style="font-family:'Playfair Display',serif;font-size:1.45rem;color:#fff;margin:0;" id="heroName">Loading…</h2>
                    <p style="font-size:.85rem;color:rgba(255,255,255,.75);margin:6px 0 0 0;" id="heroJob"></p>
                    <div class="hero-chips" id="heroChips" style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;"></div>
                  </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 320px;gap:18px;">
                  <div class="card">
                    <div class="card-header">
                      <div>
                        <div class="card-title">👤 Personal Information</div>
                        <div class="card-sub">Edit your profile details</div>
                      </div>
                      <button id="editToggleBtn" onclick="toggleEditProfile()" style="border:none;background:none;cursor:pointer;font-size:1rem;padding:6px 12px;">✏️ Edit</button>
                    </div>
                    <div id="profileForm" style="padding:20px;display:none;">
                      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                        <div>
                          <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">First Name</label>
                          <input type="text" id="firstNameInput" style="width:100%;padding:10px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:4px;font-family:'Nunito',sans-serif;">
                        </div>
                        <div>
                          <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Last Name</label>
                          <input type="text" id="lastNameInput" style="width:100%;padding:10px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:4px;font-family:'Nunito',sans-serif;">
                        </div>
                        <div>
                          <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Middle Name</label>
                          <input type="text" id="midNameInput" style="width:100%;padding:10px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:4px;font-family:'Nunito',sans-serif;">
                        </div>
                        <div>
                          <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Gender</label>
                          <select id="genderInput" style="width:100%;padding:10px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:4px;font-family:'Nunito',sans-serif;">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                          </select>
                        </div>
                        <div style="grid-column:1/-1;">
                          <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Mobile Number</label>
                          <input type="tel" id="mobileInput" style="width:100%;padding:10px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:4px;font-family:'Nunito',sans-serif;">
                        </div>
                        <div style="grid-column:1/-1;">
                          <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Email Address</label>
                          <input type="email" id="emailInput" style="width:100%;padding:10px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:4px;font-family:'Nunito',sans-serif;">
                        </div>
                        <div style="grid-column:1/-1;">
                          <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Address</label>
                          <textarea id="addressInput" style="width:100%;padding:10px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:4px;font-family:'Nunito',sans-serif;resize:vertical;min-height:80px;"></textarea>
                        </div>
                      </div>
                      <div style="display:flex;gap:10px;">
                        <button onclick="saveProfileChanges()" style="flex:1;padding:11px 14px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">Save Changes</button>
                        <button onclick="toggleEditProfile()" style="flex:1;padding:11px 14px;background:var(--g200);color:var(--g700);border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;">Cancel</button>
                      </div>
                    </div>
                    <div id="profileView" style="padding:20px;">
                      <table style="width:100%;border-collapse:collapse;">
                        <tr>
                          <td style="padding:12px 0;border-bottom:1px solid var(--g200);font-size:.82rem;color:var(--g600);font-weight:700;">Full Name</td>
                          <td style="padding:12px 0;border-bottom:1px solid var(--g200);font-size:.9rem;text-align:right;font-weight:600;" id="pFieldName"></td>
                        </tr>
                        <tr>
                          <td style="padding:12px 0;border-bottom:1px solid var(--g200);font-size:.82rem;color:var(--g600);font-weight:700;">Email</td>
                          <td style="padding:12px 0;border-bottom:1px solid var(--g200);font-size:.9rem;text-align:right;" id="pFieldEmail"></td>
                        </tr>
                        <tr>
                          <td style="padding:12px 0;border-bottom:1px solid var(--g200);font-size:.82rem;color:var(--g600);font-weight:700;">Mobile</td>
                          <td style="padding:12px 0;border-bottom:1px solid var(--g200);font-size:.9rem;text-align:right;" id="pFieldMobile"></td>
                        </tr>
                        <tr>
                          <td style="padding:12px 0;border-bottom:1px solid var(--g200);font-size:.82rem;color:var(--g600);font-weight:700;">Gender</td>
                          <td style="padding:12px 0;border-bottom:1px solid var(--g200);font-size:.9rem;text-align:right;" id="pFieldGender"></td>
                        </tr>
                        <tr>
                          <td style="padding:12px 0;font-size:.82rem;color:var(--g600);font-weight:700;">Address</td>
                          <td style="padding:12px 0;font-size:.9rem;text-align:right;" id="pFieldAddress"></td>
                        </tr>
                      </table>
                    </div>
                  </div>

                  <div style="display:flex;flex-direction:column;gap:16px;">
                    <div class="card">
                      <div class="card-header"><div class="card-title">🏢 Employment</div></div>
                      <table style="width:100%;border-collapse:collapse;font-size:.8rem;padding:16px;">
                        <tr>
                          <td style="padding:10px 16px;border-bottom:1px solid var(--g200);color:var(--g600);font-weight:700;">Position</td>
                          <td style="padding:10px 16px;border-bottom:1px solid var(--g200);text-align:right;color:var(--g800);" id="empFieldPosition"></td>
                        </tr>
                        <tr>
                          <td style="padding:10px 16px;border-bottom:1px solid var(--g200);color:var(--g600);font-weight:700;">Department</td>
                          <td style="padding:10px 16px;border-bottom:1px solid var(--g200);text-align:right;color:var(--g800);" id="empFieldDept"></td>
                        </tr>
                        <tr>
                          <td style="padding:10px 16px;border-bottom:1px solid var(--g200);color:var(--g600);font-weight:700;">Status</td>
                          <td style="padding:10px 16px;border-bottom:1px solid var(--g200);text-align:right;color:var(--g800);" id="empFieldStatus"></td>
                        </tr>
                        <tr>
                          <td style="padding:10px 16px;color:var(--g600);font-weight:700;">Date Hired</td>
                          <td style="padding:10px 16px;text-align:right;color:var(--g800);" id="empFieldHired"></td>
                        </tr>
                      </table>
                    </div>
                  </div>
                </div>

                <input type="file" id="photoInput" accept="image/*" style="display:none;" onchange="uploadProfilePhoto()">

                <script>
                  let currentProfile = null;

                  (async function() {
                    const prof = await fetch('profile.php?action=profile').then(r => r.json());
                    if (prof.success) {
                      currentProfile = prof.data;
                      const fullName = prof.data.first_name + ' ' + prof.data.last_name;
                      const ini = fullName.split(' ').map(w => w[0] || '').join('').substring(0, 2).toUpperCase();
                      
                      document.getElementById('heroName').textContent = fullName;
                      document.getElementById('heroJob').textContent = prof.data.position_title || '';
                      
                      const avatar = document.getElementById('heroAvatar');
                      avatar.textContent = ini;
                      if (prof.data.profile_photo) {
                        avatar.style.backgroundImage = `url('../uploads/profile_photos/${prof.data.profile_photo}')`;
                        avatar.textContent = '';
                      }

                      // Fill view
                      document.getElementById('pFieldName').textContent = fullName;
                      document.getElementById('pFieldEmail').textContent = prof.data.email_address;
                      document.getElementById('pFieldMobile').textContent = prof.data.mobile_number || 'N/A';
                      document.getElementById('pFieldGender').textContent = prof.data.gender || 'N/A';
                      document.getElementById('pFieldAddress').textContent = prof.data.address || 'N/A';

                      // Fill employment
                      document.getElementById('empFieldPosition').textContent = prof.data.position_title || 'N/A';
                      document.getElementById('empFieldDept').textContent = prof.data.department_name || 'N/A';
                      document.getElementById('empFieldStatus').textContent = prof.data.employment_status || 'N/A';
                      document.getElementById('empFieldHired').textContent = prof.data.date_hired ? fmt(prof.data.date_hired) : 'N/A';

                      // Fill form
                      document.getElementById('firstNameInput').value = prof.data.first_name || '';
                      document.getElementById('lastNameInput').value = prof.data.last_name || '';
                      document.getElementById('midNameInput').value = prof.data.middle_name || '';
                      document.getElementById('genderInput').value = prof.data.gender || '';
                      document.getElementById('mobileInput').value = prof.data.mobile_number || '';
                      document.getElementById('emailInput').value = prof.data.email_address || '';
                      document.getElementById('addressInput').value = prof.data.address || '';
                    }
                  })();

                  function toggleEditProfile() {
                    const view = document.getElementById('profileView');
                    const form = document.getElementById('profileForm');
                    const btn = document.getElementById('editToggleBtn');
                    if (form.style.display === 'none') {
                      view.style.display = 'none';
                      form.style.display = 'block';
                      btn.textContent = 'Cancel';
                    } else {
                      view.style.display = 'block';
                      form.style.display = 'none';
                      btn.textContent = '✏️ Edit';
                    }
                  }

                  async function saveProfileChanges() {
                    const updates = {
                      first_name: document.getElementById('firstNameInput').value,
                      last_name: document.getElementById('lastNameInput').value,
                      middle_name: document.getElementById('midNameInput').value,
                      gender: document.getElementById('genderInput').value,
                      mobile_number: document.getElementById('mobileInput').value,
                      email_address: document.getElementById('emailInput').value,
                      address: document.getElementById('addressInput').value
                    };
                    const res = await fetch('profile.php?action=update_personal', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/json'},
                      body: JSON.stringify(updates)
                    }).then(r => r.json());
                    if (res.success) {
                      alert('Profile updated successfully!');
                      location.reload();
                    } else {
                      alert('Error: ' + res.message);
                    }
                  }

                  async function uploadProfilePhoto() {
                    const input = document.getElementById('photoInput');
                    if (!input.files[0]) return;
                    const formData = new FormData();
                    formData.append('photo', input.files[0]);
                    formData.append('action', 'upload_photo');
                    const res = await fetch('profile.php', {method: 'POST', body: formData}).then(r => r.json());
                    if (res.success) {
                      alert('Photo updated successfully!');
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
