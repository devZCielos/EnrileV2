<?php
// ============================================================
//  LGU ENRILE HRMS — Documents
//  File: Employee/documents.php
//  Accessible to all employment types (not restricted)
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
            case 'my_documents':
                $stmt = $pdo->prepare("
                    SELECT doc_id, filename, file_url, doc_type, uploaded_at, file_size
                    FROM documents
                    WHERE employee_id = ?
                    ORDER BY uploaded_at DESC
                ");
                $stmt->execute([$employeeId]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                break;

            case 'upload_document':
                if (!isset($_FILES['doc']) || $_FILES['doc']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
                    break;
                }
                $file = $_FILES['doc'];
                if ($file['size'] > 52428800) { // 50 MB
                    echo json_encode(['success' => false, 'message' => 'File size exceeds 50 MB.']);
                    break;
                }

                // ── MIME validation (server-side, not client filename) ────────────
                // We detect the actual file content via finfo — the client-supplied
                // name/extension is completely ignored for security decisions.
                $allowedDocMimes = [
                    'application/pdf'                                                        => 'pdf',
                    'application/msword'                                                     => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=> 'docx',
                    'application/vnd.ms-excel'                                               => 'xls',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'      => 'xlsx',
                    'image/jpeg'                                                             => 'jpg',
                    'image/png'                                                              => 'png',
                ];
                $finfo   = finfo_open(FILEINFO_MIME_TYPE);
                $detectedMime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!array_key_exists($detectedMime, $allowedDocMimes)) {
                    echo json_encode(['success' => false, 'message' => 'File type not allowed. Accepted: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG.']);
                    break;
                }
                // Use server-determined extension — never trust the client filename
                $safeExt  = $allowedDocMimes[$detectedMime];
                $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));

                $docType = sanitize($_POST['doc_type'] ?? 'Other');
                $updir = dirname(__DIR__) . '/uploads/documents';
                if (!is_dir($updir)) mkdir($updir, 0755, true);

                $unique  = time() . '_' . bin2hex(random_bytes(6)) . '.' . $safeExt;
                $newfile = $updir . '/' . $unique;
                if (move_uploaded_file($file['tmp_name'], $newfile)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO documents (employee_id, filename, file_url, doc_type, file_size, uploaded_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$employeeId, $safeName, basename($newfile), $docType, $file['size']]);
                    echo json_encode(['success' => true, 'message' => 'Document uploaded.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Upload failed.']);
                }
                break;

            case 'delete_document':
                $docId = (int)($_POST['doc_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT file_url FROM documents WHERE doc_id = ? AND employee_id = ?");
                $stmt->execute([$docId, $employeeId]);
                $doc = $stmt->fetch();
                if (!$doc) {
                    echo json_encode(['success' => false, 'message' => 'Document not found.']);
                    break;
                }
                $fpath = dirname(__DIR__) . '/uploads/documents/' . $doc['file_url'];
                if (file_exists($fpath)) @unlink($fpath);
                $pdo->prepare("DELETE FROM documents WHERE doc_id = ?")->execute([$docId]);
                echo json_encode(['success' => true, 'message' => 'Document deleted.']);
                break;

            case 'render':
                ob_clean();
                header('Content-Type: text/html');
                ?>
                <div class="hero" style="background:linear-gradient(130deg,#eab308 0%,#ca8a04 40%,#a16207 70%,#facc15 100%);border-radius:14px;padding:26px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;animation:fadeUp .4s ease both;">
                  <div>
                    <h1 style="font-family:'Playfair Display',serif;font-size:1.4rem;color:#fff;margin:0;">📄 My Documents</h1>
                    <p style="font-size:.85rem;color:rgba(255,255,255,.75);margin-top:8px;">Upload and manage your files</p>
                  </div>
                  <div style="font-size:2.2rem;">📁</div>
                </div>

                <div class="card">
                  <div class="card-header">
                    <div class="card-title">Upload New Document</div>
                  </div>
                  <form id="uploadForm" style="padding:20px;display:grid;gap:14px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                      <div>
                        <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">Document Type</label>
                        <select id="docTypeSelect" name="doc_type" style="width:100%;padding:11px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:6px;font-family:'Nunito',sans-serif;">
                          <option value="Certificate">Certificate</option>
                          <option value="Training">Training/Seminar</option>
                          <option value="License">License</option>
                          <option value="Other">Other</option>
                        </select>
                      </div>
                      <div>
                        <label style="font-size:.73rem;font-weight:700;color:var(--g600);text-transform:uppercase;">File</label>
                        <input type="file" id="docInput" required style="width:100%;padding:11px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:.9rem;margin-top:6px;font-family:'Nunito',sans-serif;">
                      </div>
                    </div>
                    <button type="submit" style="padding:11px 14px;background:#eab308;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;font-weight:800;">📤 Upload</button>
                  </form>
                </div>

                <div class="card">
                  <div class="card-header">
                    <div class="card-title">Your Documents</div>
                  </div>
                  <div id="docsContainer" style="padding:20px;min-height:100px;">Loading documents…</div>
                </div>

                <script>
                  (async function() {
                    loadDocuments();
                    document.getElementById('uploadForm').addEventListener('submit', uploadDocument);
                  })();

                  async function loadDocuments() {
                    const res = await fetch('documents.php?action=my_documents').then(r => r.json());
                    const container = document.getElementById('docsContainer');
                    if (res.success && res.data.length > 0) {
                      container.innerHTML = res.data.map(d => `
                        <div style="padding:14px;border:1.5px solid var(--g200);border-radius:8px;display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:10px;">
                          <div>
                            <div style="font-weight:700;font-size:.9rem;color:var(--g800);">${d.filename}</div>
                            <div style="font-size:.75rem;color:var(--g500);margin-top:3px;">
                              <span style="display:inline-block;background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:4px;margin-right:8px;font-weight:600;">${d.doc_type}</span>
                              ${formatFileSize(d.file_size)} • ${empTimeAgo(d.uploaded_at)}
                            </div>
                          </div>
                          <div style="display:flex;gap:8px;">
                            <a href="../Admin/serve_document.php?id=${d.doc_id}&inline=0" download style="padding:7px 12px;background:#dbeafe;color:#1e40af;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;text-decoration:none;">📥 Download</a>
                            <button onclick="deleteDoc(${d.doc_id})" style="padding:7px 12px;background:#fee2e2;color:#991b1b;border:none;border-radius:6px;font-size:.75rem;font-weight:700;cursor:pointer;">🗑️ Delete</button>
                          </div>
                        </div>
                      `).join('');
                    } else {
                      container.innerHTML = '<div style="text-align:center;padding:30px;color:var(--g400);">No documents uploaded yet</div>';
                    }
                  }

                  async function uploadDocument(e) {
                    e.preventDefault();
                    const formData = new FormData(document.getElementById('uploadForm'));
                    formData.append('action', 'upload_document');
                    const res = await fetch('documents.php', {method: 'POST', body: formData}).then(r => r.json());
                    if (res.success) {
                      alert('Document uploaded!');
                      document.getElementById('uploadForm').reset();
                      loadDocuments();
                    } else {
                      alert('Error: ' + res.message);
                    }
                  }

                  async function deleteDoc(docId) {
                    if (!confirm('Delete this document?')) return;
                    const res = await fetch('documents.php', {
                      method: 'POST',
                      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                      body: `action=delete_document&doc_id=${docId}`
                    }).then(r => r.json());
                    if (res.success) {
                      loadDocuments();
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