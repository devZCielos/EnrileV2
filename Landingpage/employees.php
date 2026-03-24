<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Employees | LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/admin.css" />
  <link rel="stylesheet" href="../css/employees.css" />
</head>
<body>

  <!-- ══ SIDEBAR ══ -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <img src="../images/Enrile.png" alt="LGU Logo" class="brand-logo" onerror="this.style.display='none'"/>
      <div class="brand-text"><h2>LGU Enrile</h2><span>HR Management System</span></div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-label">Main</div>
      <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div>Dashboard</a>
      <div class="nav-label">Employee</div>
      <a class="nav-item active" href="employees.php"><div class="nav-icon">👥</div>Employees</a>
      <a class="nav-item" href="departments.php"><div class="nav-icon">🏢</div>Departments</a>
      <div class="nav-label">Leave</div>
      <a class="nav-item" href="leave_requests.php"><div class="nav-icon">📋</div>Leave Requests<span class="nav-badge">5</span></a>
      <a class="nav-item" href="leave_types.php"><div class="nav-icon">📁</div>Leave Types</a>
      <a class="nav-item" href="leave_balances.php"><div class="nav-icon">⚖️</div>Leave Balances</a>
      <div class="nav-label">Documents</div>
      <a class="nav-item" href="documents.php"><div class="nav-icon">📄</div>HR Documents</a>
      <a class="nav-item" href="contracts.php"><div class="nav-icon">📝</div>Contracts</a>
      <div class="nav-label">System</div>
      <a class="nav-item" href="reports.php"><div class="nav-icon">📊</div>Reports</a>
      <a class="nav-item" href="announcements.php"><div class="nav-icon">📢</div>Announcements</a>
      <a class="nav-item" href="user_accounts.php"><div class="nav-icon">🔐</div>User Accounts</a>
      <a class="nav-item" href="settings.php"><div class="nav-icon">⚙️</div>Settings</a>
    </nav>
    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="user-avatar">AD</div>
        <div class="user-info"><strong>Administrator</strong><span>Super Admin</span></div>
        <a href="logout.php" style="color:rgba(255,255,255,0.45);font-size:1.05rem;text-decoration:none;margin-left:auto;" title="Logout">🚪</a>
      </div>
    </div>
  </aside>
  <div class="overlay-mob" id="overlayMob" onclick="toggleSidebar()"></div>

  <!-- ══ MAIN ══ -->
  <div class="main">

    <div class="topbar">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <div class="topbar-title">
        <h1>Employees</h1>
        <p>Manage all LGU Enrile employee records</p>
      </div>
      <div class="topbar-actions">
        <button class="topbar-btn">🔔<span class="notif-badge">5</span></button>
        <button class="topbar-btn">💬</button>
        <button class="topbar-btn">👤</button>
      </div>
    </div>

    <div class="content">

      <!-- STAT CHIPS -->
      <div class="stats-row" id="statsRow">
        <div class="stat-chip" data-filter-all><div class="dot" style="background:#2563eb;"></div>Total: &nbsp;<strong id="sc-total">0</strong></div>
        <div class="stat-chip" data-status="Active"><div class="dot" style="background:#22c55e;"></div>Active: &nbsp;<strong id="sc-active">0</strong></div>
        <div class="stat-chip" data-status="On Leave"><div class="dot" style="background:#f59e0b;"></div>On Leave: &nbsp;<strong id="sc-onleave">0</strong></div>
        <div class="stat-chip" data-status="Inactive"><div class="dot" style="background:#ef4444;"></div>Inactive: &nbsp;<strong id="sc-inactive">0</strong></div>
        <div class="stat-chip" data-type="Permanent"><div class="dot" style="background:#6366f1;"></div>Permanent: &nbsp;<strong id="sc-perm">0</strong></div>
        <div class="stat-chip" data-type="Contractual"><div class="dot" style="background:#06b6d4;"></div>Contractual: &nbsp;<strong id="sc-cont">0</strong></div>
      </div>

      <!-- TOOLBAR -->
      <div class="toolbar">
        <div class="search-box">
          <span style="color:var(--gray-400);font-size:1rem;">🔍</span>
          <input type="text" id="searchInput" placeholder="Search name, ID, position…" oninput="applyFilters()"/>
        </div>
        <select class="filter-select" id="fDeptFilter" onchange="applyFilters()">
          <option value="">All Departments</option>
          <option>Finance</option><option>Engineering</option><option>Admin Office</option>
          <option>Health Office</option><option>MSWD</option><option>MPDO</option><option>Agriculture</option>
        </select>
        <select class="filter-select" id="fStatusFilter" onchange="applyFilters()">
          <option value="">All Status</option>
          <option>Active</option><option>On Leave</option><option>Inactive</option>
        </select>
        <select class="filter-select" id="fTypeFilter" onchange="applyFilters()">
          <option value="">All Types</option>
          <option>Permanent</option><option>Contractual</option><option>Casual</option>
        </select>
        <button class="add-btn" onclick="openAdd()">➕ Add Employee</button>
      </div>

      <!-- TABLE PANEL -->
      <div class="panel">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th onclick="sortBy('name')">Employee <span class="sort-icon" id="sn">↕</span></th>
                <th onclick="sortBy('dept')">Department <span class="sort-icon" id="sd">↕</span></th>
                <th>Position</th>
                <th onclick="sortBy('type')">Type <span class="sort-icon" id="st">↕</span></th>
                <th>Contact</th>
                <th onclick="sortBy('hired')">Date Hired <span class="sort-icon" id="sh">↕</span></th>
                <th onclick="sortBy('status')">Status <span class="sort-icon" id="ss">↕</span></th>
                <th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody id="empTbody"></tbody>
          </table>
          <div class="empty-state" id="emptyState" style="display:none;">
            <div class="empty-icon">🔍</div>
            <p>No employees found matching your search.</p>
          </div>
        </div>
        <div class="pagination">
          <span id="pageInfo" style="color:var(--gray-400);font-weight:600;"></span>
          <div class="page-btns" id="pageBtns"></div>
        </div>
      </div>

    </div>
  </div>

  <!-- ══ ADD / EDIT MODAL ══ -->
  <div class="modal-overlay" id="modalForm">
    <div class="modal">
      <div class="modal-header">
        <div><h3 id="formTitle">Add New Employee</h3><p id="formSub">Fill in the details below</p></div>
        <button class="modal-close" onclick="closeModal('modalForm')">✕</button>
      </div>
      <div class="modal-body">

        <div class="form-section">👤 Personal Information</div>
        <div class="form-grid">
          <div class="form-group">
            <label>First Name <span class="req">*</span></label>
            <input type="text" id="fFN" placeholder="e.g. Maria"/>
          </div>
          <div class="form-group">
            <label>Last Name <span class="req">*</span></label>
            <input type="text" id="fLN" placeholder="e.g. Reyes"/>
          </div>
          <div class="form-group">
            <label>Middle Name</label>
            <input type="text" id="fMN" placeholder="e.g. Santos"/>
          </div>
          <div class="form-group">
            <label>Gender</label>
            <select id="fGender">
              <option value="">Select</option>
              <option>Male</option><option>Female</option>
            </select>
          </div>
          <div class="form-group">
            <label>Date of Birth</label>
            <input type="date" id="fDob"/>
          </div>
          <div class="form-group">
            <label>Civil Status</label>
            <select id="fCivil">
              <option value="">Select</option>
              <option>Single</option><option>Married</option><option>Widowed</option><option>Separated</option>
            </select>
          </div>
          <div class="form-group">
            <label>Contact Number</label>
            <input type="text" id="fContact" placeholder="09XX-XXX-XXXX"/>
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" id="fEmail" placeholder="email@example.com"/>
          </div>
          <div class="form-group form-full">
            <label>Home Address</label>
            <input type="text" id="fAddress" placeholder="Barangay, Municipality, Province"/>
          </div>
        </div>

        <div class="form-section">💼 Employment Details</div>
        <div class="form-grid">
          <div class="form-group">
            <label>Employee ID <span class="req">*</span></label>
            <input type="text" id="fEmpId" placeholder="e.g. EMP-013"/>
          </div>
          <div class="form-group">
            <label>Department <span class="req">*</span></label>
            <select id="fDept">
              <option value="">Select department</option>
              <option>Finance</option><option>Engineering</option><option>Admin Office</option>
              <option>Health Office</option><option>MSWD</option><option>MPDO</option><option>Agriculture</option>
            </select>
          </div>
          <div class="form-group form-full">
            <label>Position / Designation <span class="req">*</span></label>
            <input type="text" id="fPosition" placeholder="e.g. Administrative Aide VI"/>
          </div>
          <div class="form-group">
            <label>Employment Type <span class="req">*</span></label>
            <select id="fType">
              <option value="">Select type</option>
              <option>Permanent</option><option>Contractual</option><option>Casual</option>
            </select>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select id="fStatus">
              <option>Active</option><option>On Leave</option><option>Inactive</option>
            </select>
          </div>
          <div class="form-group">
            <label>Date Hired</label>
            <input type="date" id="fHired"/>
          </div>
          <div class="form-group">
            <label>Salary Grade</label>
            <input type="text" id="fSG" placeholder="e.g. SG-10"/>
          </div>
          <div class="form-group">
            <label>GSIS / SSS No.</label>
            <input type="text" id="fGsis" placeholder="Government ID"/>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalForm')">Cancel</button>
        <button class="btn btn-primary" onclick="saveEmployee()">💾 Save Employee</button>
      </div>
    </div>
  </div>

  <!-- ══ VIEW PROFILE MODAL ══ -->
  <div class="modal-overlay" id="modalView">
    <div class="modal">
      <div class="profile-hero">
        <div class="profile-avatar-lg" id="vAvatar"></div>
        <div class="profile-info">
          <h3 id="vName"></h3>
          <p  id="vPos"></p>
          <div class="profile-tags" id="vTags"></div>
        </div>
        <button class="profile-close-btn" onclick="closeModal('modalView')">✕</button>
      </div>
      <div class="profile-body">
        <div class="profile-section-title">👤 Personal Information</div>
        <div class="profile-grid" id="vPersonal"></div>
        <div class="profile-section-title" style="margin-top:18px;">💼 Employment Details</div>
        <div class="profile-grid" id="vEmployment"></div>
        <div class="profile-section-title" style="margin-top:18px;">📅 Leave Balances</div>
        <div class="lb-row">
          <div class="lb-chip"><div class="lb-num">15</div><div class="lb-label">Vacation</div></div>
          <div class="lb-chip"><div class="lb-num">15</div><div class="lb-label">Sick</div></div>
          <div class="lb-chip"><div class="lb-num">3</div><div class="lb-label">Emergency</div></div>
          <div class="lb-chip" style="border-color:#fee2e2;"><div class="lb-num" style="color:#ef4444;">0</div><div class="lb-label">Used</div></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalView')">Close</button>
        <button class="btn btn-primary" onclick="editFromView()">✏️ Edit Employee</button>
      </div>
    </div>
  </div>

  <!-- ══ DELETE CONFIRM MODAL ══ -->
  <div class="modal-overlay" id="modalDelete">
    <div class="modal sm">
      <div class="modal-header red">
        <div><h3>Delete Employee</h3><p>This cannot be undone</p></div>
        <button class="modal-close" onclick="closeModal('modalDelete')">✕</button>
      </div>
      <div class="delete-body">
        <div class="del-icon">⚠️</div>
        <h4>Are you sure?</h4>
        <p id="delConfirmText"></p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalDelete')">Cancel</button>
        <button class="btn btn-danger"  onclick="confirmDelete()">🗑️ Yes, Delete</button>
      </div>
    </div>
  </div>

  <script>
    /* ── DATA ── */
    const COLORS = ['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#ec4899','#0ea5e9','#84cc16','#f97316','#6366f1','#14b8a6'];
    let employees = [
      {id:1,  empId:'EMP-001', fn:'Maria',   ln:'Reyes',      mn:'Santos',   dept:'Finance',      pos:'Accountant I',             type:'Permanent',   status:'Active',   contact:'09171234567', email:'mreyes@lgu.gov.ph',     dob:'1988-04-12', gender:'Female', civil:'Married',  addr:'Brgy. Centro, Enrile',     hired:'2015-06-01', sg:'SG-14', gsis:'1234567890'},
      {id:2,  empId:'EMP-002', fn:'Juan',    ln:'Dela Cruz',  mn:'Marcos',   dept:'Engineering',  pos:'Engineer II',              type:'Permanent',   status:'Active',   contact:'09181234567', email:'jdelacruz@lgu.gov.ph',  dob:'1985-07-22', gender:'Male',   civil:'Married',  addr:'Brgy. Mabini, Enrile',    hired:'2012-03-15', sg:'SG-16', gsis:'2345678901'},
      {id:3,  empId:'EMP-003', fn:'Ana',     ln:'Lim',        mn:'Cruz',     dept:'Admin Office', pos:'Administrative Aide VI',   type:'Permanent',   status:'On Leave', contact:'09191234567', email:'alim@lgu.gov.ph',       dob:'1992-01-30', gender:'Female', civil:'Single',   addr:'Brgy. Rizal, Enrile',     hired:'2018-09-01', sg:'SG-9',  gsis:'3456789012'},
      {id:4,  empId:'EMP-004', fn:'Roberto', ln:'Santos',     mn:'Garcia',   dept:'Health Office',pos:'Nurse I',                  type:'Permanent',   status:'Active',   contact:'09201234567', email:'rsantos@lgu.gov.ph',    dob:'1990-09-14', gender:'Male',   civil:'Single',   addr:'Brgy. Gomez, Enrile',     hired:'2019-02-01', sg:'SG-11', gsis:'4567890123'},
      {id:5,  empId:'EMP-005', fn:'Luisa',   ln:'Cruz',       mn:'Bautista', dept:'MSWD',         pos:'Social Worker I',          type:'Permanent',   status:'On Leave', contact:'09211234567', email:'lcruz@lgu.gov.ph',      dob:'1987-03-05', gender:'Female', civil:'Married',  addr:'Brgy. Bonifacio, Enrile', hired:'2014-11-01', sg:'SG-11', gsis:'5678901234'},
      {id:6,  empId:'EMP-006', fn:'Carlo',   ln:'Mendoza',    mn:'Reyes',    dept:'Engineering',  pos:'Engineering Aide',         type:'Contractual', status:'Active',   contact:'09221234567', email:'cmendoza@lgu.gov.ph',   dob:'1995-12-18', gender:'Male',   civil:'Single',   addr:'Brgy. Luna, Enrile',      hired:'2023-01-02', sg:'SG-6',  gsis:'6789012345'},
      {id:7,  empId:'EMP-007', fn:'Tina',    ln:'Flores',     mn:'Dela Vega',dept:'Finance',      pos:'Bookkeeper',               type:'Permanent',   status:'Active',   contact:'09231234567', email:'tflores@lgu.gov.ph',    dob:'1991-06-25', gender:'Female', civil:'Single',   addr:'Brgy. Calamagui, Enrile', hired:'2017-08-01', sg:'SG-8',  gsis:'7890123456'},
      {id:8,  empId:'EMP-008', fn:'Pedro',   ln:'Gomez',      mn:'Navarro',  dept:'MPDO',         pos:'Planning Officer I',       type:'Permanent',   status:'Active',   contact:'09241234567', email:'pgomez@lgu.gov.ph',     dob:'1983-11-10', gender:'Male',   civil:'Married',  addr:'Brgy. Divisoria, Enrile', hired:'2010-05-01', sg:'SG-15', gsis:'8901234567'},
      {id:9,  empId:'EMP-009', fn:'Rosario', ln:'Villanueva', mn:'Tan',      dept:'Health Office',pos:'Midwife I',                type:'Permanent',   status:'Active',   contact:'09251234567', email:'rvillanueva@lgu.gov.ph', dob:'1989-02-14', gender:'Female', civil:'Married',  addr:'Brgy. Sta. Ana, Enrile',  hired:'2016-04-01', sg:'SG-10', gsis:'9012345678'},
      {id:10, empId:'EMP-010', fn:'Dante',   ln:'Aguilar',    mn:'Pascual',  dept:'Agriculture',  pos:'Agricultural Technician',  type:'Casual',      status:'Active',   contact:'09261234567', email:'daguilar@lgu.gov.ph',   dob:'1993-08-08', gender:'Male',   civil:'Single',   addr:'Brgy. Maddarulug, Enrile',hired:'2022-06-01', sg:'SG-8',  gsis:'0123456789'},
      {id:11, empId:'EMP-011', fn:'Liza',    ln:'Bautista',   mn:'Domingo',  dept:'MSWD',         pos:'Day Care Worker',          type:'Contractual', status:'Active',   contact:'09271234567', email:'lbautista@lgu.gov.ph',  dob:'1996-04-21', gender:'Female', civil:'Single',   addr:'Brgy. Cabatacan, Enrile', hired:'2023-07-01', sg:'SG-6',  gsis:'1122334455'},
      {id:12, empId:'EMP-012', fn:'Marco',   ln:'Reyes',      mn:'Abad',     dept:'Admin Office', pos:'Driver',                   type:'Permanent',   status:'Inactive', contact:'09281234567', email:'mreyes2@lgu.gov.ph',    dob:'1980-09-03', gender:'Male',   civil:'Married',  addr:'Brgy. Lemu, Enrile',      hired:'2008-01-15', sg:'SG-5',  gsis:'2233445566'},
    ];

    const PAGE_SIZE = 8;
    let currentPage = 1;
    let sortKey = '';
    let sortAsc  = true;
    let viewTarget = null;
    let deleteTarget = null;
    let editTarget   = null;

    /* helpers */
    const ini = e => (e.fn[0] + e.ln[0]).toUpperCase();
    const color = e => COLORS[employees.indexOf(e) % COLORS.length];
    const fmt = d => d ? new Date(d).toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'}) : '—';

    function statusBadge(s) {
      const map = {Active:'badge-active', 'On Leave':'badge-onleave', Inactive:'badge-inactive'};
      const ico = {Active:'🟢', 'On Leave':'🟡', Inactive:'🔴'};
      return `<span class="badge ${map[s]||'badge-active'}">${ico[s]||''} ${s}</span>`;
    }
    function typeBadge(t) {
      const map = {Permanent:'badge-perm', Contractual:'badge-cont', Casual:'badge-cas'};
      return `<span class="badge ${map[t]||'badge-perm'}">${t}</span>`;
    }

    /* ── FILTER & SORT ── */
    function getFiltered() {
      const q = document.getElementById('searchInput').value.toLowerCase();
      const d = document.getElementById('fDeptFilter').value;
      const s = document.getElementById('fStatusFilter').value;
      const t = document.getElementById('fTypeFilter').value;
      let list = employees.filter(e => {
        const hay = `${e.fn} ${e.ln} ${e.empId} ${e.pos} ${e.dept}`.toLowerCase();
        return (!q||hay.includes(q)) && (!d||e.dept===d) && (!s||e.status===s) && (!t||e.type===t);
      });
      if (sortKey) {
        const k = {name:e=>`${e.ln}${e.fn}`, dept:e=>e.dept, type:e=>e.type, status:e=>e.status, hired:e=>e.hired}[sortKey];
        list.sort((a,b) => sortAsc ? k(a).localeCompare(k(b)) : k(b).localeCompare(k(a)));
      }
      return list;
    }

    function applyFilters() { currentPage = 1; render(); }

    /* ── RENDER ── */
    function render() {
      const all   = getFiltered();
      const start = (currentPage-1)*PAGE_SIZE;
      const page  = all.slice(start, start+PAGE_SIZE);
      const tbody = document.getElementById('empTbody');
      const empty = document.getElementById('emptyState');

      if (!all.length) {
        tbody.innerHTML = '';
        empty.style.display = 'block';
      } else {
        empty.style.display = 'none';
        tbody.innerHTML = page.map(e => `
          <tr onclick="viewEmp(${e.id})">
            <td>
              <div class="emp-cell">
                <div class="emp-avatar" style="background:${color(e)};">${ini(e)}</div>
                <div>
                  <div class="emp-name">${e.fn} ${e.ln}</div>
                  <div class="emp-id">${e.empId}</div>
                </div>
              </div>
            </td>
            <td><span class="badge badge-dept">${e.dept}</span></td>
            <td style="color:var(--gray-600);font-size:0.82rem;">${e.pos}</td>
            <td>${typeBadge(e.type)}</td>
            <td style="color:var(--gray-600);font-size:0.82rem;">${e.contact}</td>
            <td style="color:var(--gray-600);font-size:0.82rem;">${fmt(e.hired)}</td>
            <td>${statusBadge(e.status)}</td>
            <td onclick="event.stopPropagation()" style="text-align:center;">
              <div class="row-actions" style="justify-content:center;">
                <button class="icon-btn view"   onclick="viewEmp(${e.id})"   title="View Profile">👁️</button>
                <button class="icon-btn edit"   onclick="editEmp(${e.id})"   title="Edit">✏️</button>
                <button class="icon-btn delete" onclick="deleteEmp(${e.id})" title="Delete">🗑️</button>
              </div>
            </td>
          </tr>`).join('');
      }

      // pagination
      const total  = all.length;
      const pages  = Math.ceil(total / PAGE_SIZE);
      const from   = total ? start+1 : 0;
      const to     = Math.min(start+PAGE_SIZE, total);
      document.getElementById('pageInfo').textContent = `Showing ${from}–${to} of ${total} employee${total!==1?'s':''}`;

      const pb = document.getElementById('pageBtns');
      pb.innerHTML = '';
      const prevBtn = Object.assign(document.createElement('button'),{className:'page-btn',textContent:'‹',disabled:currentPage===1,onclick:()=>{currentPage--;render();}});
      pb.appendChild(prevBtn);
      for (let i=1;i<=pages;i++) {
        const b = document.createElement('button');
        b.className = 'page-btn' + (i===currentPage?' active':'');
        b.textContent = i;
        b.onclick = ()=>{ currentPage=i; render(); };
        pb.appendChild(b);
      }
      const nextBtn = Object.assign(document.createElement('button'),{className:'page-btn',textContent:'›',disabled:currentPage>=pages,onclick:()=>{currentPage++;render();}});
      pb.appendChild(nextBtn);

      // stat chips
      document.getElementById('sc-total').textContent   = employees.length;
      document.getElementById('sc-active').textContent  = employees.filter(e=>e.status==='Active').length;
      document.getElementById('sc-onleave').textContent = employees.filter(e=>e.status==='On Leave').length;
      document.getElementById('sc-inactive').textContent= employees.filter(e=>e.status==='Inactive').length;
      document.getElementById('sc-perm').textContent    = employees.filter(e=>e.type==='Permanent').length;
      document.getElementById('sc-cont').textContent    = employees.filter(e=>e.type==='Contractual').length;

      // highlight selected chip and wire up clicks (only do once if possible)
      document.querySelectorAll('.stat-chip').forEach(chip => {
        chip.onclick = () => {
          if (chip.hasAttribute('data-filter-all')) {
            document.getElementById('fStatusFilter').value = '';
            document.getElementById('fTypeFilter').value = '';
          } else if (chip.dataset.status) {
            document.getElementById('fStatusFilter').value = chip.dataset.status;
          } else if (chip.dataset.type) {
            document.getElementById('fTypeFilter').value = chip.dataset.type;
          }
          applyFilters();
        };
      });
      updateChipHighlights();
    }

    /* ── SORT ── */
    function updateChipHighlights() {
      const statusFilt = document.getElementById('fStatusFilter').value;
      const typeFilt   = document.getElementById('fTypeFilter').value;
      document.querySelectorAll('.stat-chip').forEach(chip => {
        chip.classList.remove('chip-active');
        if (chip.hasAttribute('data-filter-all') && !statusFilt && !typeFilt) {
          chip.classList.add('chip-active');
        }
        if (chip.dataset.status && chip.dataset.status === statusFilt) {
          chip.classList.add('chip-active');
        }
        if (chip.dataset.type && chip.dataset.type === typeFilt) {
          chip.classList.add('chip-active');
        }
      });
    }

    function sortBy(key) {
      if (sortKey===key) sortAsc=!sortAsc; else { sortKey=key; sortAsc=true; }
      ['name','dept','type','hired','status'].forEach(k => {
        const el = document.getElementById('s'+k[0]);
        if (el) el.textContent = sortKey===k ? (sortAsc?'↑':'↓') : '↕';
      });
      render();
    }

    /* ── VIEW PROFILE ── */
    function viewEmp(id) {
      const e = employees.find(x=>x.id===id);
      viewTarget = id;
      const c = color(e);
      document.getElementById('vAvatar').textContent = ini(e);
      document.getElementById('vAvatar').style.background = c;
      document.getElementById('vName').textContent = `${e.fn} ${e.mn ? e.mn+' ' : ''}${e.ln}`;
      document.getElementById('vPos').textContent  = `${e.pos} · ${e.dept}`;
      document.getElementById('vTags').innerHTML   = `<span class="profile-tag">${e.empId}</span><span class="profile-tag">${e.type}</span><span class="profile-tag">${e.status}</span>`;
      document.getElementById('vPersonal').innerHTML = [
        ['Gender',e.gender],['Date of Birth',fmt(e.dob)],
        ['Civil Status',e.civil],['Contact No.',e.contact],
        ['Email',e.email],['Address',e.addr],
      ].map(([l,v])=>`<div class="profile-field"><label>${l}</label><span>${v||'—'}</span></div>`).join('');
      document.getElementById('vEmployment').innerHTML = [
        ['Employee ID',e.empId],['Department',e.dept],
        ['Position',e.pos],['Employment Type',e.type],
        ['Date Hired',fmt(e.hired)],['Salary Grade',e.sg],
        ['GSIS / SSS No.',e.gsis],['Status',e.status],
      ].map(([l,v])=>`<div class="profile-field"><label>${l}</label><span>${v||'—'}</span></div>`).join('');
      openModal('modalView');
    }

    function editFromView() { closeModal('modalView'); editEmp(viewTarget); }

    /* ── ADD / EDIT ── */
    const FIELDS = ['FN','LN','MN','Gender','Dob','Civil','Contact','Email','Address','EmpId','Dept','Position','Type','Status','Hired','SG','Gsis'];
    const MAP    = { FN:'fn',LN:'ln',MN:'mn',Gender:'gender',Dob:'dob',Civil:'civil',Contact:'contact',Email:'email',Address:'addr',EmpId:'empId',Dept:'dept',Position:'pos',Type:'type',Status:'status',Hired:'hired',SG:'sg',Gsis:'gsis' };

    function clearForm() {
      FIELDS.forEach(f => { const el=document.getElementById('f'+f); if(el){el.value=''; el.classList.remove('err');} });
      document.getElementById('fStatus').value='Active';
    }

    function openAdd() {
      editTarget = null;
      clearForm();
      document.getElementById('formTitle').textContent = 'Add New Employee';
      document.getElementById('formSub').textContent   = 'Fill in the details below';
      openModal('modalForm');
    }

    function editEmp(id) {
      const e = employees.find(x=>x.id===id);
      editTarget = id;
      clearForm();
      document.getElementById('formTitle').textContent = 'Edit Employee';
      document.getElementById('formSub').textContent   = `Editing: ${e.fn} ${e.ln}`;
      FIELDS.forEach(f => { const el=document.getElementById('f'+f); if(el) el.value=e[MAP[f]]||''; });
      openModal('modalForm');
    }

    function saveEmployee() {
      const req = ['fFN','fLN','fEmpId','fDept','fPosition','fType'];
      let valid = true;
      req.forEach(id => {
        const el = document.getElementById(id);
        if (!el.value.trim()) { el.classList.add('err'); valid=false; }
        else el.classList.remove('err');
      });
      if (!valid) { showToast('⚠️ Please fill in all required fields.','info'); return; }

      const data = {};
      FIELDS.forEach(f => { data[MAP[f]] = document.getElementById('f'+f).value; });

      if (editTarget) {
        const idx = employees.findIndex(x=>x.id===editTarget);
        employees[idx] = { ...employees[idx], ...data };
        showToast(`✅ ${data.fn} ${data.ln} updated successfully.`,'success');
      } else {
        employees.push({ id: Date.now(), ...data });
        showToast(`✅ ${data.fn} ${data.ln} added successfully.`,'success');
      }
      closeModal('modalForm');
      render();
    }

    /* ── DELETE ── */
    function deleteEmp(id) {
      const e = employees.find(x=>x.id===id);
      deleteTarget = id;
      document.getElementById('delConfirmText').textContent =
        `You are about to permanently delete the record of ${e.fn} ${e.ln} (${e.empId}). This action cannot be undone.`;
      openModal('modalDelete');
    }

    function confirmDelete() {
      const e = employees.find(x=>x.id===deleteTarget);
      employees = employees.filter(x=>x.id!==deleteTarget);
      closeModal('modalDelete');
      render();
      showToast(`🗑️ ${e.fn} ${e.ln}'s record has been deleted.`,'error');
    }

    /* ── MODALS ── */
    function openModal(id)  { document.getElementById(id).classList.add('open'); }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }
    document.querySelectorAll('.modal-overlay').forEach(m => {
      m.addEventListener('click', e => { if(e.target===m) m.classList.remove('open'); });
    });

    /* ── TOAST ── */
    function showToast(msg, type='success') {
      document.querySelectorAll('.toast').forEach(t=>t.remove());
      const t = document.createElement('div');
      t.className = `toast ${type}`;
      t.innerHTML = msg;
      document.body.appendChild(t);
      setTimeout(() => { t.style.opacity='0'; t.style.transition='opacity 0.3s'; setTimeout(()=>t.remove(),300); }, 3200);
    }

    /* ── SIDEBAR ── */
    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('open');
      document.getElementById('overlayMob').classList.toggle('show');
    }

    /* ── INIT ── */
    render();
  </script>
</body>
</html>

