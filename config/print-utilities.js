/**
 * ============================================================
 *  LGU ENRILE HRMS — Shared Print Utilities
 *  File: config/print-utilities.js
 *  Shared by Admin & Employee portals for consistent printing
 * ============================================================
 */

/**
 * Helper: Safe JSON parse with type validation
 */
function safeJson(s) {
  try {
    const result = s ? JSON.parse(s) : null;
    return result;
  } catch (e) {
    return null;
  }
}

/**
 * Helper: Format value
 */
function formatValue(v) {
  return (v === null || v === undefined || v === '' || v === 'null' || v === 'undefined') ? '' : String(v);
}

/**
 * Helper: Format date (mm/dd/yyyy)
 */
function formatDate(dt) {
  if (!dt || dt === '0000-00-00') return '';
  try {
    const x = new Date(dt);
    return isNaN(x) ? dt : (x.getMonth() + 1).toString().padStart(2, '0') + '/' + x.getDate().toString().padStart(2, '0') + '/' + x.getFullYear();
  } catch (e) {
    return dt || '';
  }
}

/**
 * Helper: Format money
 */
function formatMoney(x) {
  if (x === '' || x === null || x === undefined) return '';
  const n = parseFloat(x);
  return isNaN(n) ? '' : '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2 });
}

/**
 * Helper: Checkbox symbol
 */
function checkboxSymbol(val) {
  return (val === true || val === '1' || val === 1 || val === 'Yes' || val === 'true') ? '☑' : '☐';
}

/**
 * Helper: Pad array to minimum length
 */
function padArray(arr, minLength) {
  const a = [...(arr || [])];
  while (a.length < minLength) a.push({});
  return a;
}

/**
 * Build PDS Print HTML — CS Form 212 (Revised 2025)
 */
// ============================================================
//  buildPDSPrintHTML — CS Form 212 (Revised 2025)
//  Pixel-faithful 4-page HTML replica of the official Excel
//  Matches the official CSC Excel form layout exactly
// ============================================================
function buildPDSPrintHTML(d, empRow) {
  'use strict';

  // ── helpers ────────────────────────────────────────────────
  const sj  = s => { try { return s ? JSON.parse(s) : {}; } catch(e) { return {}; } };
  const v   = x => (x === null || x === undefined || x === 'null' || x === 'undefined') ? '' : String(x);
  const esc = s => v(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const fmtD = dt => {
    if (!dt || dt === '0000-00-00') return '';
    try {
      const x = new Date(dt);
      if (isNaN(x)) return v(dt);
      return (x.getMonth()+1).toString().padStart(2,'0') + '/' + x.getDate().toString().padStart(2,'0') + '/' + x.getFullYear();
    } catch(e) { return v(dt); }
  };
  const cb  = val => (val === true || val === 'Yes' || val === '1' || val === 1 || val === 'true') ? '☑' : '☐';
  const ynBox = (val, label) => `<span style="display:inline-flex;align-items:center;gap:3pt;font-size:7pt;">`
    + `<span style="display:inline-block;border:0.5pt solid #000;width:8pt;height:8pt;text-align:center;line-height:8pt;font-size:7pt;">${cb(val)}</span>`
    + `<span>${esc(label)}</span></span>`;
  const optBox = (checked, label) => `<span style="display:inline-flex;align-items:center;gap:5pt;font-size:5.8pt;line-height:1;">`
    + `<span style="display:inline-block;border:0.5pt solid #000;width:9pt;height:9pt;text-align:center;line-height:8pt;font-size:6.5pt;">${checked ? '&#10003;' : '&nbsp;'}</span>`
    + `<span>${esc(label)}</span></span>`;
  const ul  = (val, w) => `<span style="display:inline-block;min-width:${w||'60pt'};border-bottom:0.5pt solid #000;font-size:7pt;font-weight:700;padding:0 1pt;">${esc(v(val))}&nbsp;</span>`;
  const pad = (arr, n) => { 
    let a = [];
    if (Array.isArray(arr)) { 
      a = [...arr]; 
    } else if (arr && typeof arr === 'object') { 
      a = Object.keys(arr).map(k => arr[k]); 
    }
    while(a.length < n) a.push({}); 
    return a; 
  };

  // ── parse PDS data ─────────────────────────────────────────
  // Ensure all array fields are actually arrays, not objects
  const ensureArray = v => Array.isArray(v) ? v : (v && typeof v === 'object' ? Object.values(v) : []);
  
  const pi  = sj(d.personal_info)     || {};
  const fb  = sj(d.family_background) || {};
  const ed  = ensureArray(sj(d.education)         || []);
  const cs  = ensureArray(sj(d.civil_service)     || []);
  const we  = ensureArray(sj(d.work_experience)   || []);
  const vw  = ensureArray(sj(d.voluntary_work)    || []);
  const tr  = ensureArray(sj(d.trainings)         || []);
  const oi  = sj(d.other_info)        || {};
  const q4  = sj(d.q4_questions)      || {};
  const refs     = ensureArray(sj(d.pds_references || d['references']) || []);
  const charRefs = ensureArray(sj(d.pds_character_references) || []);

  // education by level
  const eduMap = {};
  (Array.isArray(ed) ? ed : []).forEach(e => { if (e.level) eduMap[e.level] = e; });
  const edu = lvl => eduMap[lvl] || {};

  // derived address strings
  const resAddr  = [pi.res_housenum,pi.res_street,pi.res_subdivision,pi.res_barangay,pi.res_city,pi.res_province].filter(Boolean).join(', ');
  const permAddr = pi.perm_same ? resAddr : [pi.perm_housenum,pi.perm_street,pi.perm_subdivision,pi.perm_barangay,pi.perm_city,pi.perm_province].filter(Boolean).join(', ');

  // skills arrays
  const skills      = (oi.skills_arr || (oi.skills ? oi.skills.split('\n') : [])).filter(Boolean);
  const distinctions = (oi.distinctions_arr || (oi.distinctions ? oi.distinctions.split('\n') : [])).filter(Boolean);
  const memberships  = (oi.memberships_arr  || (oi.memberships  ? oi.memberships.split('\n')  : [])).filter(Boolean);

  // ── shared CSS ─────────────────────────────────────────────
  const CSS = `
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:"Arial Narrow",Arial,sans-serif;font-size:7.5pt;color:#000;background:#fff;}
    @page{size:A4 portrait;margin:0.9in 0.3in 0.3in 0.4in;}
    @media print{body{margin:0;padding:0;}.pg{page-break-after:always;page-break-inside:avoid;}.pg:last-child{page-break-after:avoid;}}
    table{border-collapse:collapse;table-layout:fixed;width:100%;}
    td,th{overflow:hidden;font-size:7pt;vertical-align:middle;padding:1pt 2pt;}
    .all{border:0.5pt solid #000;}
    .hdr{background:#c0c0c0;font-size:6pt;font-weight:700;text-align:center;border:0.5pt solid #000;padding:2pt 1pt;vertical-align:middle;}
    .sec{background:#595959;color:#fff;font-size:7.5pt;font-weight:700;font-style:italic;border:0.5pt solid #000;padding:2pt 4pt;}
    .lbl{font-size:5.5pt;color:#333;display:block;line-height:1.1;}
    .val{font-size:7pt;font-weight:700;display:block;min-height:7pt;word-break:break-word;}
    .yn{display:inline-block;border:0.5pt solid #000;width:7pt;height:7pt;text-align:center;line-height:7pt;font-size:6.5pt;margin-right:2pt;}
    .sig{height:22pt;border-bottom:0.5pt solid #000;}
    .pg{padding:0;margin:0;}
    .warn{font-size:6pt;font-weight:700;font-style:italic;}
    .note{font-size:5.5pt;}
    .pg-foot{font-size:5.5pt;text-align:right;margin-top:1pt;margin-bottom:0;font-style:italic;}
    .cont{font-size:6.5pt;font-style:italic;color:#c00;text-align:center;font-weight:700;}
    .itl{font-style:italic;}
  `;

  // ── PAGE 1 — Personal Info + Family + Education ────────────
  const PAGE1 = () => {
    const perm = pi.perm_same
      ? { housenum:pi.res_housenum, street:pi.res_street, subdivision:pi.res_subdivision,
          barangay:pi.res_barangay, city:pi.res_city, province:pi.res_province, zip:pi.res_zip }
      : { housenum:pi.perm_housenum, street:pi.perm_street, subdivision:pi.perm_subdivision,
          barangay:pi.perm_barangay, city:pi.perm_city, province:pi.perm_province, zip:pi.perm_zip };

    const EDU_LEVELS = ['Elementary','Secondary','Vocational/Trade Course','College','Graduate Studies','Graduate Studies'];
    const eduRows = EDU_LEVELS.map((lvl, idx) => {
      const e = idx === 5 ? (eduMap['Graduate Studies 2'] || {}) : edu(lvl);
      const displayLvl = lvl === 'Graduate Studies' ? `GRADUATE<br>STUDIES` :
                         lvl === 'Vocational/Trade Course' ? `VOCATIONAL /<br>TRADE COURSE` :
                         lvl.toUpperCase();
      return `<tr style="height:14pt;">
        <td class="all" style="font-size:5.5pt;font-weight:700;vertical-align:middle;padding:0 1pt;text-align:center;">${displayLvl}</td>
        <td class="all" style="font-size:6.5pt;vertical-align:middle;padding:0 1pt;">${esc(e.school)}&nbsp;</td>
        <td class="all" style="font-size:6.5pt;vertical-align:middle;padding:0 1pt;">${esc(e.degree)}&nbsp;</td>
        <td class="all" style="width:6%;text-align:center;font-size:6.5pt;padding:0 1pt;">${esc(e.from)}&nbsp;</td>
        <td class="all" style="width:6%;text-align:center;font-size:6.5pt;padding:0 1pt;">${esc(e.to)}&nbsp;</td>
        <td class="all" style="width:9%;text-align:center;font-size:6.5pt;padding:0 1pt;">${esc(e.units)}&nbsp;</td>
        <td class="all" style="width:7%;text-align:center;font-size:6.5pt;padding:0 1pt;">${esc(e.year_grad)}&nbsp;</td>
        <td class="all" style="width:14%;font-size:6.5pt;vertical-align:middle;padding:0 1pt;">${esc(e.honors)}&nbsp;</td>
      </tr>`;
    }).join('');

    // Children: 8 rows to save vertical space
    const children = pad(fb.children || [], 8);
    const childRows = children.map(ch => `<tr style="height:12pt;">
      <td class="all" style="font-size:6.5pt;padding:0 1pt;">${esc(ch.name)}&nbsp;</td>
      <td class="all" style="text-align:center;font-size:6.5pt;padding:0 1pt;width:38%;">${esc(ch.dob ? fmtD(ch.dob) : '')}&nbsp;</td>
    </tr>`).join('');

    return `<div class="pg">
<!-- ── HEADER ── -->
<table style="margin-bottom:0;border-collapse:collapse;">
  <tr>
    <td style="width:17%;border:0.5pt solid #000;border-right:none;border-bottom:none;padding:2pt 4pt;vertical-align:top;line-height:1.4;">
      <div style="font-size:7.5pt;font-weight:700;">CS Form No. 212</div>
      <div style="font-size:7pt;font-style:italic;">Revised 2025</div>
    </td>
    <td style="border:0.5pt solid #000;border-left:none;border-bottom:none;text-align:center;padding:6pt 0 4pt;vertical-align:middle;">
      <div style="font-size:22pt;font-weight:900;font-family:Arial,sans-serif;">PERSONAL DATA SHEET</div>
    </td>
  </tr>
</table>
<table style="margin-bottom:0;">
  <tr>
    <td style="border:0.5pt solid #000;border-top:none;padding:3pt 4pt 2pt;">
      <div style="font-size:6.5pt;font-weight:700;font-style:italic;line-height:1.5;margin-bottom:1pt;"><em>WARNING: Any misrepresentation made in the Personal Data Sheet and the Work Experience Sheet shall cause the filing of administrative/criminal case/s against the person concerned.</em></div>
      <div style="font-size:6.5pt;font-weight:700;font-style:italic;line-height:1.5;margin-bottom:2pt;"><em>READ THE ATTACHED GUIDE TO FILLING OUT THE PERSONAL DATA SHEET (PDS) BEFORE ACCOMPLISHING THE PDS FORM.</em></div>
      <div style="font-size:6pt;font-style:italic;line-height:1.4;">Print legibly if accomplished through own handwriting. Tick appropriate boxes (&#9744;) and use separate sheet if necessary. Indicate N/A if not applicable. <strong>DO NOT ABBREVIATE.</strong></div>
    </td>
  </tr>
</table>

<!-- ── SECTION I ── -->
<table style="margin-bottom:0;"><tr><td class="sec" style="padding:1pt 4pt;">I. PERSONAL INFORMATION</td></tr></table>

<!-- Name rows -->
<table style="margin-bottom:0;">
  <colgroup>
    <col style="width:20%;"/>
    <col style="width:57%;"/>
    <col style="width:23%;"/>
  </colgroup>
  <tr style="height:22pt;">
    <td style="border-top:0.5pt solid #000;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:none;background:#e0e0e0;font-size:6.5pt;color:#000;vertical-align:middle;padding:2pt 4pt;">1.&nbsp; SURNAME</td>
    <td colspan="2" style="border-top:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:0.5pt solid #000;border-left:none;vertical-align:bottom;padding:1pt 4pt;"><span style="font-size:8pt;font-weight:700;">${esc(pi.last_name)}</span></td>
  </tr>
  <tr style="height:22pt;">
    <td style="border-top:none;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:none;background:#e0e0e0;font-size:6.5pt;color:#000;vertical-align:middle;padding:2pt 4pt;">2.&nbsp; FIRST NAME</td>
    <td style="border-top:0.5pt solid #000;border-right:0.5pt solid #000;border-left:none;border-bottom:0.5pt solid #000;vertical-align:bottom;padding:1pt 4pt;"><span style="font-size:8pt;font-weight:700;">${esc(pi.first_name)}</span></td>
    <td style="border-top:0.5pt solid #000;border-right:0.5pt solid #000;border-left:0.5pt solid #000;border-bottom:0.5pt solid #000;background:#f0f0f0;vertical-align:top;padding:2pt 4pt;"><span style="font-size:6pt;">NAME EXTENSION (JR., SR)</span></td>
  </tr>
  <tr style="height:22pt;">
    <td style="border-top:none;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:0.5pt solid #000;background:#e0e0e0;font-size:6.5pt;color:#000;vertical-align:middle;padding:2pt 12pt;">&nbsp;&nbsp;MIDDLE NAME</td>
    <td colspan="2" style="border-top:none;border-right:0.5pt solid #000;border-left:none;border-bottom:0.5pt solid #000;vertical-align:bottom;padding:1pt 4pt;"><span style="font-size:8pt;font-weight:700;">${esc(pi.middle_name)}</span></td>
  </tr>
</table>

<!-- 4-column section: num(3%) | data(19%) | citizenship(26%) | address(52%) -->
<!-- Citizenship spans rows 3-6 (rowspan=4); Address spans rows 3-12 (rowspan=10) -->
<!-- Items 7-12: use colspan=2 in cols 2-3 (citizenship ended) -->
<table style="margin-bottom:0;">
  <colgroup>
    <col style="width:3%;"/>
    <col style="width:17%;"/>
    <col style="width:20%;"/>
    <col style="width:60%;"/>
  </colgroup>
  <!-- Item 3: DATE OF BIRTH -->
  <tr style="height:21pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:top;padding:2pt 4pt 1pt 6pt;line-height:1.15;"><div>3.&nbsp; DATE OF BIRTH</div><div style="font-style:italic;padding-left:10pt;">(dd/mm/yyyy)</div></td>
    <td class="all" style="font-size:7pt;font-weight:700;vertical-align:bottom;padding:1pt 4pt;text-align:center;">${pi.dob ? fmtD(pi.dob) : ''}</td>
    <td class="all" rowspan="3" style="padding:0;vertical-align:top;">
      <table style="width:100%;height:100%;border-collapse:collapse;table-layout:fixed;">
        <tr>
          <td style="width:39%;background:#e0e0e0;border-right:0.5pt solid #000;vertical-align:top;padding:3pt 4pt 2pt;">
            <div style="font-size:5.8pt;line-height:1.1;">16. CITIZENSHIP</div>
            <div style="font-size:5.4pt;line-height:1.8;text-align:center;margin-top:20pt;">If holder of dual citizenship,<br>please indicate the details.</div>
          </td>
          <td style="vertical-align:top;padding:3pt 6pt 0 6pt;">
            <div style="font-size:5.8pt;white-space:nowrap;">${optBox(pi.citizenship==='Filipino'||!pi.citizenship,'Filipino')}&nbsp;&nbsp;&nbsp;&nbsp;${optBox(pi.citizenship==='Dual Citizenship','Dual Citizenship')}</div>
            <div style="font-size:5.8pt;white-space:nowrap;margin-top:7pt;padding-left:20pt;">${optBox(pi.dual_by==='Birth','by birth')}&nbsp;&nbsp;&nbsp;${optBox(pi.dual_by==='Naturalization','by naturalization')}</div>
            <div style="font-size:6pt;color:#b45f06;text-align:center;margin-top:9pt;">Pls. indicate country:</div>
            <table style="width:100%;border-collapse:collapse;table-layout:fixed;margin-top:7pt;">
              <tr>
                <td style="font-size:7pt;padding:1pt 4pt;border-top:0.5pt solid #000;border-bottom:0.5pt solid #000;vertical-align:middle;">${esc(pi.dual_country)}&nbsp;</td>
                <td style="width:13pt;border:0.5pt solid #000;border-left:none;vertical-align:middle;text-align:center;padding:0;">
                  <span style="display:inline-block;width:0;height:0;border-left:4.5pt solid transparent;border-right:4.5pt solid transparent;border-top:4.5pt solid #000;"></span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <!-- Item 4: PLACE OF BIRTH -->
  <tr style="height:14pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">4.&nbsp; PLACE OF BIRTH</td>
    <td class="all" style="font-size:7pt;font-weight:700;vertical-align:bottom;padding:1pt 4pt;text-align:center;">${esc(pi.pob)}</td>
  </tr>
  <!-- Item 5: SEX AT BIRTH -->
  <tr style="height:14pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">5.&nbsp; SEX AT BIRTH</td>
    <td class="all" style="vertical-align:middle;padding:0;font-size:5.8pt;">
      <table style="border-collapse:collapse;width:100%;table-layout:fixed;">
        <tr style="height:14pt;"><td style="width:50%;padding:1pt 4pt;">${optBox(pi.sex==='Male','Male')}</td><td style="width:50%;padding:1pt 4pt;">${optBox(pi.sex==='Female','Female')}</td></tr>
      </table>
    </td>
  </tr>
  <!-- Item 6: CIVIL STATUS + Item 17 RESIDENTIAL ADDRESS starts here -->
  <tr style="height:30pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:top;padding:2pt 4pt 1pt 6pt;">6.&nbsp; CIVIL STATUS</td>
    <td class="all" style="vertical-align:top;padding:0;font-size:5.8pt;">
      <table style="border-collapse:collapse;width:100%;table-layout:fixed;">
        <tr style="height:10pt;"><td style="width:50%;padding:1pt 4pt;">${optBox(pi.civil_status==='Single','Single')}</td><td style="width:50%;padding:1pt 4pt;">${optBox(pi.civil_status==='Married','Married')}</td></tr>
        <tr style="height:10pt;"><td style="padding:1pt 4pt;">${optBox(pi.civil_status==='Widowed'||pi.civil_status==='Widow/er','Widowed')}</td><td style="padding:1pt 4pt;">${optBox(pi.civil_status==='Separated','Separated')}</td></tr>
        <tr style="height:10pt;"><td colspan="2" style="padding:1pt 4pt;">${optBox(pi.civil_status==='Others'||pi.civil_status==='Other/s','Other/s:')}</td></tr>
      </table>
    </td>
    <td class="all" rowspan="10" style="vertical-align:top;padding:0;font-size:5pt;">
      <table style="width:100%;height:100%;border-collapse:collapse;table-layout:fixed;">
        <tr>
          <td style="width:30%;background:#e0e0e0;border-right:0.5pt solid #000;vertical-align:top;padding:3pt 4pt;">
            <div style="font-size:5.8pt;font-weight:700;line-height:1.1;">17. RESIDENTIAL ADDRESS</div>
          </td>
          <td style="vertical-align:top;padding:0;border-bottom:0.5pt solid #000;">
            <table style="border-collapse:collapse;width:100%;table-layout:fixed;">
              <tr style="height:9pt;"><td style="width:50%;font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(pi.res_housenum)}&nbsp;</td><td style="width:50%;font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(pi.res_street)}&nbsp;</td></tr>
              <tr style="height:8pt;"><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;border-bottom:0.4pt solid #000;">House/Block/Lot No.</td><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;border-bottom:0.4pt solid #000;">Street</td></tr>
              <tr style="height:9pt;"><td style="font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(pi.res_subdivision)}&nbsp;</td><td style="font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(pi.res_barangay)}&nbsp;</td></tr>
              <tr style="height:8pt;"><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;border-bottom:0.4pt solid #000;">Subdivision/Village</td><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;border-bottom:0.4pt solid #000;">Barangay</td></tr>
              <tr style="height:9pt;"><td style="font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(pi.res_city)}&nbsp;</td><td style="font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(pi.res_province)}&nbsp;</td></tr>
              <tr style="height:8pt;"><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;">City/Municipality</td><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;">Province</td></tr>
            </table>
          </td>
        </tr>
        <tr style="height:14pt;">
          <td style="background:#e0e0e0;border-right:0.5pt solid #000;border-bottom:0.5pt solid #000;vertical-align:middle;padding:2pt 4pt;">
            <div style="font-size:5.8pt;font-weight:700;">ZIP CODE</div>
          </td>
          <td style="border-bottom:0.5pt solid #000;vertical-align:middle;padding:1pt 4pt;">
            <span style="font-weight:700;font-size:6pt;text-align:center;display:block;">${esc(pi.res_zip)}&nbsp;</span>
          </td>
        </tr>
        <tr>
          <td style="background:#e0e0e0;border-right:0.5pt solid #000;vertical-align:top;padding:3pt 4pt;">
            <div style="font-size:5.8pt;font-weight:700;line-height:1.1;">18. PERMANENT ADDRESS</div>
          </td>
          <td style="vertical-align:top;padding:0;border-bottom:0.5pt solid #000;">
            <table style="border-collapse:collapse;width:100%;table-layout:fixed;">
              <tr style="height:9pt;"><td style="width:50%;font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(perm.housenum)}&nbsp;</td><td style="width:50%;font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(perm.street)}&nbsp;</td></tr>
              <tr style="height:8pt;"><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;border-bottom:0.4pt solid #000;">House/Block/Lot No.</td><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;border-bottom:0.4pt solid #000;">Street</td></tr>
              <tr style="height:9pt;"><td style="font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(perm.subdivision)}&nbsp;</td><td style="font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(perm.barangay)}&nbsp;</td></tr>
              <tr style="height:8pt;"><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;border-bottom:0.4pt solid #000;">Subdivision/Village</td><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;border-bottom:0.4pt solid #000;">Barangay</td></tr>
              <tr style="height:9pt;"><td style="font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(perm.city)}&nbsp;</td><td style="font-weight:700;font-size:7pt;text-align:center;padding:0 3pt;">${esc(perm.province)}&nbsp;</td></tr>
              <tr style="height:8pt;"><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;">City/Municipality</td><td style="font-size:5pt;font-style:italic;text-align:center;padding:0 3pt;border-top:0.4pt solid #000;">Province</td></tr>
            </table>
          </td>
        </tr>
        <tr style="height:14pt;">
          <td style="background:#e0e0e0;border-right:0.5pt solid #000;border-bottom:0.5pt solid #000;vertical-align:middle;padding:2pt 4pt;">
            <div style="font-size:5.8pt;font-weight:700;">ZIP CODE</div>
          </td>
          <td style="border-bottom:0.5pt solid #000;vertical-align:middle;padding:1pt 4pt;">
            <span style="font-weight:700;font-size:6pt;text-align:center;display:block;">${esc(perm.zip)}&nbsp;</span>
          </td>
        </tr>
        <tr style="height:13pt;">
          <td colspan="2" style="border-bottom:0.5pt solid #000;vertical-align:middle;padding:0;">
            <table style="border-collapse:collapse;width:100%;table-layout:fixed;"><tr>
              <td style="width:30%;background:#e0e0e0;border-right:0.5pt solid #000;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt;">19. TELEPHONE NO.</td>
              <td style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;text-align:center;">${esc(pi.tel)}</td>
            </tr></table>
          </td>
        </tr>
        <tr style="height:13pt;">
          <td colspan="2" style="border-bottom:0.5pt solid #000;vertical-align:middle;padding:0;">
            <table style="border-collapse:collapse;width:100%;table-layout:fixed;"><tr>
              <td style="width:30%;background:#e0e0e0;border-right:0.5pt solid #000;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt;">20. MOBILE NO.</td>
              <td style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;text-align:center;">${esc(pi.mobile)}</td>
            </tr></table>
          </td>
        </tr>
        <tr style="height:13pt;">
          <td colspan="2" style="vertical-align:middle;padding:0;">
            <table style="border-collapse:collapse;width:100%;table-layout:fixed;"><tr>
              <td style="width:30%;background:#e0e0e0;border-right:0.5pt solid #000;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt;">21. E-MAIL ADDRESS (if any)</td>
              <td style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;text-align:center;">${esc(pi.email)}</td>
            </tr></table>
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <!-- Items 7-12 -->
  <tr style="height:13pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">7.&nbsp; HEIGHT (m)</td>
    <td class="all" style="font-size:7pt;font-weight:700;vertical-align:bottom;padding:1pt 4pt;text-align:center;">${esc(pi.height)}</td>
  </tr>
  <tr style="height:13pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">8.&nbsp; WEIGHT (kg)</td>
    <td class="all" style="font-size:7pt;font-weight:700;vertical-align:bottom;padding:1pt 4pt;text-align:center;">${esc(pi.weight)}</td>
  </tr>
  <tr style="height:13pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">9.&nbsp; BLOOD TYPE</td>
    <td class="all" style="font-size:7pt;font-weight:700;vertical-align:bottom;padding:1pt 4pt;text-align:center;">${esc(pi.blood_type)}</td>
  </tr>
  <tr style="height:13pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">10.&nbsp; UMID ID NO.</td>
    <td class="all" style="font-size:7pt;font-weight:700;vertical-align:bottom;padding:1pt 4pt;text-align:center;">${esc(pi.umid)}</td>
  </tr>
  <tr style="height:13pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">11.&nbsp; PAG-IBIG ID NO.</td>
    <td class="all" style="font-size:7pt;font-weight:700;vertical-align:bottom;padding:1pt 4pt;text-align:center;">${esc(pi.pagibig)}</td>
  </tr>
  <tr style="height:13pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">12.&nbsp; PHILHEALTH NO.</td>
    <td class="all" style="font-size:7pt;font-weight:700;vertical-align:bottom;padding:1pt 4pt;text-align:center;">${esc(pi.philhealth)}</td>
  </tr>
  <tr style="height:13pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">13.&nbsp; PhilSys Number (PSN):</td>
    <td class="all" style="font-size:7pt;font-weight:700;vertical-align:bottom;padding:1pt 4pt;text-align:center;">${esc(pi.psn)}</td>
  </tr>
  <tr style="height:13pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">14.&nbsp; TIN NO.</td>
    <td class="all" style="font-size:7pt;font-weight:700;vertical-align:bottom;padding:1pt 4pt;text-align:center;">${esc(pi.tin)}</td>
  </tr>
  <tr style="height:13pt;">
    <td class="all" colspan="2" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">15.&nbsp; AGENCY EMPLOYEE NO.</td>
    <td class="all" style="font-size:7pt;font-weight:700;vertical-align:bottom;padding:1pt 4pt;text-align:center;">${esc(pi.agency_no)}</td>
  </tr>
</table>

<!-- ── SECTION II FAMILY BACKGROUND ── -->
<table style="margin-bottom:0;"><tr><td class="sec" style="padding:1pt 4pt;">II. FAMILY BACKGROUND</td></tr></table>

<!-- Spouse (left 50%) + Children (right 50%) -->
<table style="margin-bottom:0;table-layout:fixed;">
  <colgroup>
    <col style="width:50%;"/>
    <col style="width:50%;"/>
  </colgroup>
  <tr>
    <td style="vertical-align:top;padding:0;border:none;">
      <table style="width:100%;border-collapse:collapse;table-layout:fixed;">
        <colgroup><col style="width:34%;"/><col style="width:34%;"/><col style="width:32%;"/></colgroup>
        <tr style="height:13pt;">
          <td style="border-top:0.5pt solid #000;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:none;background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">22.&nbsp; SPOUSE'S SURNAME</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;">${esc(fb.sp_surname)}</td>
        </tr>
        <tr style="height:13pt;">
          <td style="border-top:none;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:none;background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 14pt;">FIRST NAME</td>
          <td class="all" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;">${esc(fb.sp_firstname)}</td>
          <td class="all" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt;">NAME EXTENSION (JR., SR)</td>
        </tr>
        <tr style="height:13pt;">
          <td style="border-top:none;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:0.5pt solid #000;background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 14pt;">MIDDLE NAME</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;">${esc(fb.sp_middle)}</td>
        </tr>
        <tr style="height:13pt;">
          <td class="all" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 14pt;">OCCUPATION</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;text-align:center;">${esc(fb.sp_occupation)}</td>
        </tr>
        <tr style="height:13pt;">
          <td class="all" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 14pt;">EMPLOYER/BUSINESS NAME</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;text-align:center;">${esc(fb.sp_employer)}</td>
        </tr>
        <tr style="height:13pt;">
          <td class="all" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 14pt;">BUSINESS ADDRESS</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;text-align:center;">${esc(fb.sp_biz_addr)}</td>
        </tr>
        <tr style="height:13pt;">
          <td class="all" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 14pt;">TELEPHONE NO.</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;text-align:center;">${esc(fb.sp_tel)}</td>
        </tr>
        <tr style="height:13pt;">
          <td style="border-top:0.5pt solid #000;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:none;background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">24.&nbsp; FATHER'S SURNAME</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;">${esc(fb.fa_surname)}</td>
        </tr>
        <tr style="height:13pt;">
          <td style="border-top:none;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:none;background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 14pt;">FIRST NAME</td>
          <td class="all" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;">${esc(fb.fa_firstname)}</td>
          <td class="all" style="background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt;">NAME EXTENSION (JR., SR)</td>
        </tr>
        <tr style="height:13pt;">
          <td style="border-top:none;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:0.5pt solid #000;background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 14pt;">MIDDLE NAME</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;">${esc(fb.fa_middle)}</td>
        </tr>
        <tr style="height:13pt;">
          <td style="border-top:0.5pt solid #000;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:none;background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 6pt;">25.&nbsp; MOTHER'S MAIDEN NAME</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;"></td>
        </tr>
        <tr style="height:13pt;">
          <td style="border-top:none;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:none;background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 14pt;">SURNAME</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;">${esc(fb.mo_surname)}</td>
        </tr>
        <tr style="height:13pt;">
          <td style="border-top:none;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:none;background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 14pt;">FIRST NAME</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;">${esc(fb.mo_firstname)}</td>
        </tr>
        <tr style="height:13pt;">
          <td style="border-top:none;border-left:0.5pt solid #000;border-right:0.5pt solid #000;border-bottom:0.5pt solid #000;background:#e0e0e0;font-size:5.8pt;vertical-align:middle;padding:1pt 4pt 1pt 14pt;">MIDDLE NAME</td>
          <td class="all" colspan="2" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;">${esc(fb.mo_middle)}</td>
        </tr>
      </table>
    </td>
    <td style="vertical-align:top;padding:0;border:none;">
      <table style="width:100%;border-collapse:collapse;table-layout:fixed;">
        <colgroup><col style="width:60%;"/><col style="width:40%;"/></colgroup>
        <tr>
          <td class="all" style="background:#c0c0c0;font-size:5.8pt;vertical-align:top;padding:2pt 4pt;line-height:1.3;">23. NAME of CHILDREN&nbsp;&nbsp;(Write full name and list all)</td>
          <td class="all" style="background:#c0c0c0;font-size:5.8pt;vertical-align:top;padding:2pt 4pt;line-height:1.3;">DATE OF BIRTH (dd/mm/yyyy)</td>
        </tr>
        ${(()=>{
          const ch = Array.isArray(fb.children) ? fb.children : [];
          const total = Math.max(ch.length, 12);
          let rows = '';
          for(let i=0;i<total;i++){
            const c = ch[i]||{};
            rows += '<tr style="height:13pt;">';
            rows += '<td class="all" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;text-align:center;">' + esc(c.name) + '</td>';
            rows += '<td class="all" style="font-size:7pt;font-weight:700;vertical-align:middle;padding:1pt 4pt;text-align:center;">' + (c.dob ? fmtD(c.dob) : '') + '</td>';
            rows += '</tr>';
          }
          return rows;
        })()}
        <tr style="height:13pt;">
          <td class="all" colspan="2" style="text-align:center;vertical-align:middle;"><span class="cont">(Continue on separate sheet if necessary)</span></td>
        </tr>
      </table>
    </td>
  </tr>
</table>



<!-- ── SECTION III EDUCATIONAL BACKGROUND ── -->
<table style="margin-bottom:0;"><tr><td class="sec" style="padding:1pt 4pt;">III. EDUCATIONAL BACKGROUND</td></tr></table>
<table style="margin-bottom:0;">
  <thead>
    <tr style="height:20pt;">
      <th class="hdr" rowspan="2" style="width:10%;position:relative;vertical-align:middle;text-align:center;padding:2pt 4pt;"><span style="position:absolute;top:2pt;left:3pt;font-weight:400;font-size:7.5pt;">26.</span><br>LEVEL</th>
      <th class="hdr" rowspan="2" style="width:20%;">NAME OF SCHOOL<br><span style="font-weight:400;">(Write in full)</span></th>
      <th class="hdr" rowspan="2" style="width:18%;">BASIC EDUCATION/<br>DEGREE/COURSE<br><span style="font-weight:400;">(Write in full)</span></th>
      <th class="hdr" colspan="2" style="width:12%;">PERIOD OF<br>ATTENDANCE</th>
      <th class="hdr" rowspan="2" style="width:10%;">HIGHEST LEVEL/<br>UNITS EARNED<br><span style="font-weight:400;">(if not graduated)</span></th>
      <th class="hdr" rowspan="2" style="width:8%;">YEAR<br>GRADUATED</th>
      <th class="hdr" rowspan="2" style="width:22%;">SCHOLARSHIP/<br>ACADEMIC HONORS<br>RECEIVED</th>
    </tr>
    <tr>
      <th class="hdr" style="width:6%;">From</th>
      <th class="hdr" style="width:6%;">To</th>
    </tr>
  </thead>
  <tbody>${eduRows}</tbody>
</table>
<table style="margin-bottom:0;"><tr><td class="all" style="text-align:center;" colspan="99"><span class="cont">(Continue on separate sheet if necessary)</span></td></tr></table>

<!-- ── SIGNATURE ── -->
<table style="margin-top:1pt;">
  <tr>
    <td class="all" style="width:15%;text-align:center;padding:4pt 2pt;font-size:7pt;font-weight:700;">SIGNATURE</td>
    <td class="all" style="width:35%;">&nbsp;</td>
    <td class="all" style="width:15%;text-align:center;padding:4pt 2pt;font-size:7pt;font-weight:700;">DATE</td>
    <td class="all" style="width:35%;">&nbsp;</td>
  </tr>
</table>
<div class="pg-foot">CS FORM 212 (Revised 2025), Page 1 of 4</div>
</div>`;
  };

  // ── PAGE 2 — Civil Service Eligibility + Work Experience ───
  const PAGE2 = () => {
    const csPad = pad(cs, 8);
    // Excel Page 2: Work Experience has NO monthly salary / salary grade columns
    // Columns: From | To | Position Title | Dept/Agency | Status of Appointment | Gov't Service Y/N
    const wePad = pad(we, 28);

    const csRows = csPad.map(e => `<tr style="height:18pt;">
      <td class="all" style="font-size:6.5pt;padding:1pt 2pt;">${esc(e.eligibility)}&nbsp;</td>
      <td class="all" style="width:12%;text-align:center;font-size:6.5pt;">${esc(e.rating)}&nbsp;</td>
      <td class="all" style="width:13%;text-align:center;font-size:6.5pt;">${e.exam_date ? fmtD(e.exam_date) : ''}&nbsp;</td>
      <td class="all" style="width:22%;font-size:6.5pt;">${esc(e.exam_place)}&nbsp;</td>
      <td class="all" style="width:10%;text-align:center;font-size:6.5pt;">${esc(e.license_no)}&nbsp;</td>
      <td class="all" style="width:10%;text-align:center;font-size:6.5pt;">${e.license_validity ? fmtD(e.license_validity) : ''}&nbsp;</td>
    </tr>`).join('');

    const weRows = wePad.map(e => `<tr style="height:16pt;">
      <td class="all" style="width:9%;text-align:center;font-size:6.5pt;">${e.date_from ? fmtD(e.date_from) : ''}&nbsp;</td>
      <td class="all" style="width:9%;text-align:center;font-size:6.5pt;">${e.date_to ? fmtD(e.date_to) : ''}&nbsp;</td>
      <td class="all" style="width:28%;font-size:6.5pt;">${esc(e.position)}&nbsp;</td>
      <td class="all" style="width:32%;font-size:6.5pt;">${esc(e.dept_agency)}&nbsp;</td>
      <td class="all" style="width:14%;font-size:6.5pt;">${esc(e.status)}&nbsp;</td>
      <td class="all" style="width:8%;text-align:center;font-size:6.5pt;">${esc(e.govt_service)}&nbsp;</td>
    </tr>`).join('');

    return `<div class="pg">
<table style="margin-bottom:0;"><tr><td class="sec" style="padding:1pt 4pt;font-style:italic;">IV. CIVIL SERVICE ELIGIBILITY</td></tr></table>
<table style="margin-bottom:0;">
  <thead>
    <tr>
      <th class="hdr" rowspan="2" style="width:33%;position:relative;vertical-align:middle;text-align:center;padding:2pt 4pt;"><span style="position:absolute;top:2pt;left:3pt;font-weight:400;font-size:7.5pt;">27.</span>CES/CSEE/CAREER SERVICE/RA 1080 (BOARD/<br>BAR)/UNDER SPECIAL LAWS/CATEGORY II/ IV ELIGIBILITY<br>and ELIGIBILITIES FOR UNIFORMED PERSONNEL</th>
      <th class="hdr" rowspan="2" style="width:10%;vertical-align:middle;text-align:center;">RATING<br><span style="font-weight:400;">(If Applicable)</span></th>
      <th class="hdr" rowspan="2" style="width:12%;vertical-align:middle;text-align:center;">DATE OF<br>EXAMINATION /<br>CONFERMENT</th>
      <th class="hdr" rowspan="2" style="width:20%;vertical-align:middle;text-align:center;">PLACE OF EXAMINATION /<br>CONFERMENT</th>
      <th class="hdr" colspan="2" style="width:25%;text-align:center;">LICENSE (if applicable)</th>
    </tr>
    <tr>
      <th class="hdr" style="width:13%;text-align:center;">NUMBER</th>
      <th class="hdr" style="width:12%;text-align:center;">Valid Until</th>
    </tr>
  </thead>
  <tbody>${csRows}</tbody>
</table>
<table style="margin-bottom:0;"><tr><td class="all" style="text-align:center;" colspan="99"><span class="cont" style="color:red;font-style:italic;">(Continue on separate sheet if necessary)</span></td></tr></table>

<table style="margin-bottom:0;margin-top:1pt;"><tr><td class="sec" style="padding:1pt 4pt;font-style:italic;border-bottom:none;">V. WORK EXPERIENCE</td></tr><tr>
  <td class="sec" style="font-size:7pt;font-style:italic;padding:2pt 4pt;font-weight:700;border-top:none;">
    (Include private employment.&nbsp; Start from your recent work.) Description of duties should be indicated in the attached Work Experience Sheet.
  </td>
</tr></table>
<table style="margin-bottom:0;">
  <thead>
    <tr>
      <th class="hdr" colspan="2" style="width:18%;position:relative;vertical-align:middle;text-align:center;padding:2pt 4pt;"><span style="position:absolute;top:2pt;left:3pt;font-weight:400;font-size:7.5pt;">28.</span>INCLUSIVE DATES<br>(dd/mm/yyyy)</th>
      <th class="hdr" rowspan="2" style="width:28%;vertical-align:middle;text-align:center;">POSITION TITLE<br><span style="font-weight:400;">(Write in full/Do not abbreviate)</span></th>
      <th class="hdr" rowspan="2" style="width:32%;vertical-align:middle;text-align:center;">DEPARTMENT / AGENCY / OFFICE / COMPANY<br><span style="font-weight:400;">(Write in full/Do not abbreviate)</span></th>
      <th class="hdr" rowspan="2" style="width:14%;vertical-align:middle;text-align:center;">STATUS OF<br>APPOINTMENT</th>
      <th class="hdr" rowspan="2" style="width:12%;vertical-align:middle;text-align:center;white-space:nowrap;">GOV'T&nbsp;SERVICE<br>(Y/&nbsp;N)</th>
    </tr>
    <tr>
      <th class="hdr" style="width:9%;">From</th>
      <th class="hdr" style="width:9%;">To</th>
    </tr>
  </thead>
  <tbody>${weRows}</tbody>
</table>
<table style="margin-bottom:0;"><tr><td class="all" style="text-align:center;" colspan="99"><span class="cont" style="color:red;font-style:italic;">(Continue on separate sheet if necessary)</span></td></tr></table>

<table style="margin-top:2pt;">
  <tr>
    <td class="all" style="width:15%;text-align:center;padding:4pt 2pt;font-size:7pt;font-weight:700;">SIGNATURE</td>
    <td class="all" style="width:35%;">&nbsp;</td>
    <td class="all" style="width:15%;text-align:center;padding:4pt 2pt;font-size:7pt;font-weight:700;">DATE</td>
    <td class="all" style="width:35%;">&nbsp;</td>
  </tr>
</table>
<div class="pg-foot">CS FORM 212 (Revised 2025), Page 2 of 4</div>
</div>`;
  };

  // ── PAGE 3 — Voluntary Work + L&D + Other Info ────────────
  const PAGE3 = () => {
    const vwPad = pad(vw, 10);
    const trPad = pad(tr, 20);

    const vwRows = vwPad.map(e => `<tr style="height:16pt;">
      <td class="all" style="width:38%;font-size:6.5pt;">${esc(e.org_name)}&nbsp;</td>
      <td class="all" style="width:13%;text-align:center;font-size:6.5pt;">${e.date_from ? fmtD(e.date_from) : ''}&nbsp;</td>
      <td class="all" style="width:13%;text-align:center;font-size:6.5pt;">${e.date_to ? fmtD(e.date_to) : ''}&nbsp;</td>
      <td class="all" style="width:12%;text-align:center;font-size:6.5pt;">${esc(e.no_hours)}&nbsp;</td>
      <td class="all" style="width:24%;font-size:6.5pt;">${esc(e.position)}&nbsp;</td>
    </tr>`).join('');

    const trRows = trPad.map(e => `<tr style="height:16pt;">
      <td class="all" style="width:32%;font-size:6.5pt;">${esc(e.title)}&nbsp;</td>
      <td class="all" style="width:11%;text-align:center;font-size:6.5pt;">${e.date_from ? fmtD(e.date_from) : ''}&nbsp;</td>
      <td class="all" style="width:11%;text-align:center;font-size:6.5pt;">${e.date_to ? fmtD(e.date_to) : ''}&nbsp;</td>
      <td class="all" style="width:10%;text-align:center;font-size:6.5pt;">${esc(e.no_hours)}&nbsp;</td>
      <td class="all" style="width:14%;font-size:6.5pt;">${esc(e.ld_type)}&nbsp;</td>
      <td class="all" style="width:22%;font-size:6.5pt;">${esc(e.conducted_by)}&nbsp;</td>
    </tr>`).join('');

    const SKILL_ROWS = 7;
    const skillsPad    = pad(skills.map(s=>({v:s})), SKILL_ROWS);
    const distinctPad  = pad(distinctions.map(s=>({v:s})), SKILL_ROWS);
    const memberPad    = pad(memberships.map(s=>({v:s})), SKILL_ROWS);
    const oiRows = Array.from({length: SKILL_ROWS}, (_,i) => `<tr style="height:16pt;">
      <td class="all" style="font-size:6.5pt;">${esc(skillsPad[i] ? skillsPad[i].v : '')}&nbsp;</td>
      <td class="all" style="font-size:6.5pt;">${esc(distinctPad[i] ? distinctPad[i].v : '')}&nbsp;</td>
      <td class="all" style="font-size:6.5pt;">${esc(memberPad[i] ? memberPad[i].v : '')}&nbsp;</td>
    </tr>`).join('');

    return `<div class="pg">
<table style="margin-bottom:0;"><tr><td class="sec">VI. VOLUNTARY WORK OR INVOLVEMENT IN CIVIC / NON-GOVERNMENT / PEOPLE / VOLUNTARY ORGANIZATION/S</td></tr></table>
<table style="margin-bottom:0;">
  <thead>
    <tr style="height:24pt;">
      <th class="hdr" rowspan="2" style="width:38%;position:relative;vertical-align:middle;text-align:center;padding:2pt 4pt;"><span style="position:absolute;top:2pt;left:3pt;font-weight:400;font-size:7.5pt;">29.</span>NAME &amp; ADDRESS OF ORGANIZATION<br><span style="font-weight:400;">(Write in full)</span></th>
      <th class="hdr" colspan="2" style="width:26%;">INCLUSIVE DATES<br>(dd/mm/yyyy)</th>
      <th class="hdr" rowspan="2" style="width:12%;">NUMBER OF HOURS</th>
      <th class="hdr" rowspan="2" style="width:24%;">POSITION / NATURE OF WORK</th>
    </tr>
    <tr>
      <th class="hdr" style="width:13%;">From</th>
      <th class="hdr" style="width:13%;">To</th>
    </tr>
  </thead>
  <tbody>${vwRows}</tbody>
</table>
<table style="margin-bottom:0;"><tr><td class="all" style="text-align:center;" colspan="99"><span class="cont">(Continue on separate sheet if necessary)</span></td></tr></table>

<table style="margin-bottom:0;margin-top:1pt;"><tr><td class="sec">VII. LEARNING AND DEVELOPMENT (L&amp;D) INTERVENTIONS/TRAINING PROGRAMS ATTENDED</td></tr></table>
<table style="margin-bottom:0;">
  <thead>
    <tr style="height:28pt;">
      <th class="hdr" rowspan="2" style="width:32%;position:relative;vertical-align:middle;text-align:center;padding:2pt 4pt;"><span style="position:absolute;top:2pt;left:3pt;font-weight:400;font-size:7.5pt;">30.</span>TITLE OF LEARNING AND DEVELOPMENT INTERVENTIONS/TRAINING PROGRAMS<br><span style="font-weight:400;">(Write in full)</span></th>
      <th class="hdr" colspan="2" style="width:22%;">INCLUSIVE DATES OF<br>ATTENDANCE<br>(dd/mm/yyyy)</th>
      <th class="hdr" rowspan="2" style="width:10%;">NUMBER OF<br>HOURS</th>
      <th class="hdr" rowspan="2" style="width:14%;">Type of L&amp;D<br><span style="font-weight:400;">(Managerial/<br>Supervisory/<br>Technical/etc)</span></th>
      <th class="hdr" rowspan="2" style="width:22%;">CONDUCTED/ SPONSORED BY<br><span style="font-weight:400;">(Write in full)</span></th>
    </tr>
    <tr>
      <th class="hdr" style="width:11%;">From</th>
      <th class="hdr" style="width:11%;">To</th>
    </tr>
  </thead>
  <tbody>${trRows}</tbody>
</table>
<table style="margin-bottom:0;"><tr><td class="all" style="text-align:center;" colspan="99"><span class="cont">(Continue on separate sheet if necessary)</span></td></tr></table>

<table style="margin-bottom:0;margin-top:1pt;"><tr><td class="sec">VIII. OTHER INFORMATION</td></tr></table>
<table style="margin-bottom:0;">
  <thead>
    <tr>
      <th class="hdr" style="width:33%;position:relative;vertical-align:middle;text-align:center;padding:2pt 4pt;"><span style="position:absolute;top:2pt;left:3pt;font-weight:400;font-size:7.5pt;">31.</span>SPECIAL SKILLS and HOBBIES</th>
      <th class="hdr" style="width:34%;position:relative;vertical-align:middle;text-align:center;padding:2pt 4pt;"><span style="position:absolute;top:2pt;left:3pt;font-weight:400;font-size:7.5pt;">32.</span>NON-ACADEMIC DISTINCTIONS / RECOGNITION<br><span style="font-weight:400;">(Write in full)</span></th>
      <th class="hdr" style="width:33%;position:relative;vertical-align:middle;text-align:center;padding:2pt 4pt;"><span style="position:absolute;top:2pt;left:3pt;font-weight:400;font-size:7.5pt;">33.</span>MEMBERSHIP IN<br>ASSOCIATION/ORGANIZATION<br><span style="font-weight:400;">(Write in full)</span></th>
    </tr>
  </thead>
  <tbody>${oiRows}</tbody>
</table>
<table style="margin-bottom:0;"><tr><td class="all" style="text-align:center;" colspan="99"><span class="cont">(Continue on separate sheet if necessary)</span></td></tr></table>

<table style="margin-top:2pt;">
  <tr>
    <td class="all" style="width:15%;text-align:center;padding:4pt 2pt;font-size:7pt;font-weight:700;">SIGNATURE</td>
    <td class="all" style="width:35%;">&nbsp;</td>
    <td class="all" style="width:15%;text-align:center;padding:4pt 2pt;font-size:7pt;font-weight:700;">DATE</td>
    <td class="all" style="width:35%;">&nbsp;</td>
  </tr>
</table>
<div class="pg-foot">CS FORM 212 (Revised 2025), Page 3 of 4</div>
</div>`;
  };

  // ── PAGE 4 — Q34-40 + References + Declaration ────────────
  const PAGE4 = () => {
    const charRefsPad = pad(charRefs.length ? charRefs : refs, 3);

    return `<div class="pg">
<!-- ── QUESTIONS 34-40 ── -->
<table style="margin-bottom:0;">
  <tbody>
    <tr>
      <td class="all" style="width:55%;font-size:6.5pt;line-height:1.5;padding:3pt 4pt;vertical-align:top;">
        34. Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau or office or to the person who has immediate supervision over you in the Office, Bureau or Department where you will be appointed,<br>
        a. within the third degree?<br>
        b. within the fourth degree (for Local Government Unit - Career Employees)?
      </td>
      <td class="all" style="width:45%;vertical-align:top;padding:3pt 4pt;font-size:6pt;">
        <div style="margin-bottom:3pt;"><strong style="font-size:5.5pt;">a.</strong>&nbsp;<span class="yn">${cb(q4.q34a==='Yes'||q4.q34a===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q34a==='Yes'||q4.q34a===true))}</span> NO</div>
        <div style="margin-bottom:3pt;"><strong style="font-size:5.5pt;">b.</strong>&nbsp;<span class="yn">${cb(q4.q34b==='Yes'||q4.q34b===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q34b==='Yes'||q4.q34b===true))}</span> NO</div>
        <div style="font-size:6pt;color:#c00;">If YES, give details:</div>
        <div style="border-bottom:0.5pt solid #000;min-height:9pt;font-size:6.5pt;font-weight:700;">${esc(q4.q34_details)}&nbsp;</div>
      </td>
    </tr>
    <tr>
      <td class="all" style="font-size:6.5pt;line-height:1.5;padding:3pt 4pt;vertical-align:top;">
        35. a. Have you ever been found guilty of any administrative offense?
      </td>
      <td class="all" style="vertical-align:top;padding:3pt 4pt;font-size:6pt;">
        <div style="margin-bottom:2pt;"><span class="yn">${cb(q4.q35a==='Yes'||q4.q35a===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q35a==='Yes'||q4.q35a===true))}</span> NO</div>
        <div style="font-size:6pt;color:#c00;">If YES, give details:</div>
        <div style="border-bottom:0.5pt solid #000;min-height:9pt;font-size:6.5pt;font-weight:700;">${esc(q4.q35a_details)}&nbsp;</div>
      </td>
    </tr>
    <tr>
      <td class="all" style="font-size:6.5pt;line-height:1.5;padding:3pt 4pt;vertical-align:top;">
        &nbsp;&nbsp;&nbsp;&nbsp;b. Have you been criminally charged before any court?
      </td>
      <td class="all" style="vertical-align:top;padding:3pt 4pt;font-size:6pt;">
        <div style="margin-bottom:2pt;"><span class="yn">${cb(q4.q35b==='Yes'||q4.q35b===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q35b==='Yes'||q4.q35b===true))}</span> NO</div>
        <div style="font-size:6pt;color:#c00;">If YES, give details:</div>
        <div style="border-bottom:0.5pt solid #000;min-height:9pt;font-size:6.5pt;font-weight:700;">${esc(q4.q35b_details)}&nbsp;</div>
        <div style="font-size:6pt;margin-top:2pt;">Date Filed: <span style="border-bottom:0.5pt solid #000;display:inline-block;min-width:80pt;font-size:6.5pt;font-weight:700;">${q4.q35b_date ? fmtD(q4.q35b_date) : ''}&nbsp;</span></div>
        <div style="font-size:6pt;">Status of Case/s: <span style="border-bottom:0.5pt solid #000;display:inline-block;min-width:70pt;font-size:6.5pt;font-weight:700;">${esc(q4.q35b_status)}&nbsp;</span></div>
      </td>
    </tr>
    <tr>
      <td class="all" style="font-size:6.5pt;line-height:1.5;padding:3pt 4pt;vertical-align:top;">
        36. Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?
      </td>
      <td class="all" style="vertical-align:top;padding:3pt 4pt;font-size:6pt;">
        <div style="margin-bottom:2pt;"><span class="yn">${cb(q4.q36==='Yes'||q4.q36===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q36==='Yes'||q4.q36===true))}</span> NO</div>
        <div style="font-size:6pt;color:#c00;">If YES, give details:</div>
        <div style="border-bottom:0.5pt solid #000;min-height:9pt;font-size:6.5pt;font-weight:700;">${esc(q4.q36_details)}&nbsp;</div>
      </td>
    </tr>
    <tr>
      <td class="all" style="font-size:6.5pt;line-height:1.5;padding:3pt 4pt;vertical-align:top;">
        37. Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?
      </td>
      <td class="all" style="vertical-align:top;padding:3pt 4pt;font-size:6pt;">
        <div style="margin-bottom:2pt;"><span class="yn">${cb(q4.q37==='Yes'||q4.q37===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q37==='Yes'||q4.q37===true))}</span> NO</div>
        <div style="font-size:6pt;color:#c00;">If YES, give details:</div>
        <div style="border-bottom:0.5pt solid #000;min-height:9pt;font-size:6.5pt;font-weight:700;">${esc(q4.q37_details)}&nbsp;</div>
      </td>
    </tr>
    <tr>
      <td class="all" style="font-size:6.5pt;line-height:1.5;padding:3pt 4pt;vertical-align:top;">
        38. a. Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?
      </td>
      <td class="all" style="vertical-align:top;padding:3pt 4pt;font-size:6pt;">
        <div style="margin-bottom:2pt;"><span class="yn">${cb(q4.q38a==='Yes'||q4.q38a===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q38a==='Yes'||q4.q38a===true))}</span> NO</div>
        <div style="font-size:6pt;color:#c00;">If YES, give details:</div>
        <div style="border-bottom:0.5pt solid #000;min-height:9pt;font-size:6.5pt;font-weight:700;">${esc(q4.q38a_details)}&nbsp;</div>
      </td>
    </tr>
    <tr>
      <td class="all" style="font-size:6.5pt;line-height:1.5;padding:3pt 4pt;vertical-align:top;">
        &nbsp;&nbsp;&nbsp;&nbsp;b. Have you resigned from the government service during the three (3)-month period before the last election to promote/actively campaign for a national or local candidate?
      </td>
      <td class="all" style="vertical-align:top;padding:3pt 4pt;font-size:6pt;">
        <div style="margin-bottom:2pt;"><span class="yn">${cb(q4.q38b==='Yes'||q4.q38b===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q38b==='Yes'||q4.q38b===true))}</span> NO</div>
        <div style="font-size:6pt;color:#c00;">If YES, give details:</div>
        <div style="border-bottom:0.5pt solid #000;min-height:9pt;font-size:6.5pt;font-weight:700;">${esc(q4.q38b_details)}&nbsp;</div>
      </td>
    </tr>
    <tr>
      <td class="all" style="font-size:6.5pt;line-height:1.5;padding:3pt 4pt;vertical-align:top;">
        39. Have you acquired the status of an immigrant or permanent resident of another country?
      </td>
      <td class="all" style="vertical-align:top;padding:3pt 4pt;font-size:6pt;">
        <div style="margin-bottom:2pt;"><span class="yn">${cb(q4.q39==='Yes'||q4.q39===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q39==='Yes'||q4.q39===true))}</span> NO</div>
        <div style="font-size:6pt;color:#c00;">If YES, give details (country):</div>
        <div style="border-bottom:0.5pt solid #000;min-height:9pt;font-size:6.5pt;font-weight:700;">${esc(q4.q39_details)}&nbsp;</div>
      </td>
    </tr>
    <tr>
      <td class="all" colspan="2" style="padding:0;">
        <table style="width:100%;border-collapse:collapse;">
          <tr>
            <td style="width:55%;font-size:6.5pt;padding:3pt 4pt 2pt 4pt;vertical-align:top;border-right:0.5pt solid #000;border-bottom:none;">
              <span style="font-size:7pt;">40.</span> Pursuant to: (a) Indigenous People&rsquo;s Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277, as amended); and (c) Expanded Solo Parents Welfare Act (RA 11861), please answer the following items:
            </td>
            <td style="width:45%;border-bottom:none;" rowspan="1">&nbsp;</td>
          </tr>
          <tr>
            <td style="font-size:6.5pt;padding:3pt 4pt 2pt 12pt;vertical-align:top;border-right:0.5pt solid #000;border-top:none;border-bottom:none;">
              a. &nbsp; Are you a member of any indigenous group?
            </td>
            <td style="font-size:6.5pt;padding:3pt 4pt 0 4pt;vertical-align:top;border-top:none;border-bottom:none;">
              <span class="yn">${cb(q4.q40a==='Yes'||q4.q40a===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q40a==='Yes'||q4.q40a===true))}</span> NO<br>
              <span style="font-size:6pt;">If YES, please specify: <span style="border-bottom:0.5pt solid #000;display:inline-block;min-width:80pt;font-weight:700;">${esc(q4.q40a_detail)}&nbsp;</span></span>
            </td>
          </tr>
          <tr>
            <td style="font-size:6.5pt;padding:3pt 4pt 2pt 12pt;vertical-align:top;border-right:0.5pt solid #000;border-top:none;border-bottom:none;">
              b. &nbsp; Are you a person with disability?
            </td>
            <td style="font-size:6.5pt;padding:3pt 4pt 0 4pt;vertical-align:top;border-top:none;border-bottom:none;">
              <span class="yn">${cb(q4.q40b==='Yes'||q4.q40b===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q40b==='Yes'||q4.q40b===true))}</span> NO<br>
              <span style="font-size:6pt;">If YES, please specify ID No: <span style="border-bottom:0.5pt solid #000;display:inline-block;min-width:80pt;font-weight:700;">${esc(q4.q40b_detail)}&nbsp;</span></span>
            </td>
          </tr>
          <tr>
            <td style="font-size:6.5pt;padding:3pt 4pt 3pt 12pt;vertical-align:top;border-right:0.5pt solid #000;border-top:none;">
              c. &nbsp; Are you a solo parent?
            </td>
            <td style="font-size:6.5pt;padding:3pt 4pt 0 4pt;vertical-align:top;border-top:none;">
              <span class="yn">${cb(q4.q40c==='Yes'||q4.q40c===true)}</span> YES &nbsp;&nbsp;&nbsp; <span class="yn">${cb(!(q4.q40c==='Yes'||q4.q40c===true))}</span> NO<br>
              <span style="font-size:6pt;">If YES, please specify ID No: <span style="border-bottom:0.5pt solid #000;display:inline-block;min-width:80pt;font-weight:700;">${esc(q4.q40c_detail)}&nbsp;</span></span>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </tbody>
</table>

<!-- ── REFERENCES (41) + DECLARATION (42) + GOV'T ID + PHOTO + THUMBMARK ── -->
<table style="margin-bottom:0;margin-top:0;border-collapse:collapse;">
  <tr>
    <td style="width:67%;vertical-align:top;padding:0;">
      <!-- 41 REFERENCES -->
      <table style="width:100%;border-collapse:collapse;margin-bottom:0;table-layout:fixed;">
        <colgroup><col style="width:45%"><col style="width:35%"><col style="width:20%"></colgroup>
        <tr>
          <td class="all" style="padding:2pt 4pt;font-size:7pt;" colspan="3">
            41. &nbsp; REFERENCES (Person not related by consanguinity or affinity to applicant /appointee)
          </td>
        </tr>
        <tr>
          <th class="hdr">NAME</th>
          <th class="hdr">OFFICE / RESIDENTIAL ADDRESS</th>
          <th class="hdr">CONTACT NO.<br>AND/OR EMAIL</th>
        </tr>
        ${charRefsPad.map(r => `<tr style="height:18pt;">
          <td class="all" style="font-size:6.5pt;">${esc(r.name)}&nbsp;</td>
          <td class="all" style="font-size:6.5pt;">${esc(r.address)}&nbsp;</td>
          <td class="all" style="font-size:6.5pt;">${esc(r.tel)}&nbsp;</td>
        </tr>`).join('')}
      </table>
      <!-- 42 DECLARATION -->
      <table style="width:100%;border-collapse:collapse;margin-bottom:0;">
        <tr>
          <td class="all" style="padding:6pt 8pt;font-size:7.5pt;line-height:1.7;vertical-align:top;text-align:justify;">
            <span style="font-size:8pt;">42.</span> &nbsp;I declare under oath that I have personally accomplished this Personal Data Sheet which is a true, correct, and complete statement pursuant to the provisions of pertinent laws, rules, and regulations of the Republic of the Philippines. I authorize the agency head/authorized representative to verify/validate the contents stated herein. &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; I agree that any misrepresentation made in this document and its attachments shall cause the filing of administrative/criminal case/s against me.
          </td>
        </tr>
      </table>
    </td>
    <td style="width:33%;vertical-align:top;padding:0 0 0 4pt;">
      <!-- PHOTO BOX -->
      <div style="text-align:center;padding:0 4pt 6pt 4pt;margin-top:28pt;">
        <div style="border:1.5pt solid #000;width:3.51cm;height:4.43cm;margin:0 auto;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:4pt;">
          <div style="font-size:7pt;line-height:1.4;">Passport-sized unfiltered<br>digital picture taken within<br>the last &nbsp;6 months<br>4.5 cm. X 3.5 cm</div>
        </div>
        <div style="margin-top:8pt;font-size:8pt;color:#c00;font-weight:400;">PHOTO</div>
      </div>
    </td>
  </tr>
</table>

<!-- ── MARGIN BEFORE GOV'T ID ── -->
<table style="margin-bottom:0;margin-top:5pt;border-collapse:collapse;width:100%;">
  <tr>
    <td style="height:5pt;">&nbsp;</td>
  </tr>
</table>

<!-- ── GOV'T ID + SIGNATURE + DATE + THUMBMARK ── -->
<table style="margin-bottom:0;margin-top:2pt;margin-left:2pt;border-collapse:collapse;width:100%;table-layout:fixed;">
  <tr>
    <td style="width:37%;vertical-align:top;padding:0;border:1pt solid #000;">
      <div style="font-size:7pt;padding:3pt 4pt;border-bottom:1pt solid #000;line-height:1.4;background:#c0c0c0;">
        <strong>Government Issued ID</strong> <span style="font-size:6pt;font-style:italic;">(i.e.Passport, GSIS, SSS, PRC, Driver's License, etc.)</span><br>
        <em><strong>PLEASE INDICATE ID Number and Date of Issuance</strong></em>
      </div>
      <div style="font-size:7pt;padding:3pt 4pt;border-bottom:1pt solid #000;height:16pt;line-height:10pt;overflow:hidden;">
        Government Issued ID:&nbsp;<span style="font-weight:700;">${esc(pi.gov_id_type)}</span>
      </div>
      <div style="font-size:7pt;padding:3pt 4pt;border-bottom:1pt solid #000;height:16pt;line-height:10pt;overflow:hidden;">
        ID/License/Passport No.:&nbsp;<span style="font-weight:700;">${esc(pi.gov_id_no)}</span>
      </div>
      <div style="font-size:7pt;padding:3pt 4pt;border-bottom:1pt solid #000;height:16pt;line-height:10pt;overflow:hidden;">
        Date/Place of Issuance:&nbsp;<span style="font-weight:700;">${esc(pi.gov_id_issuance)}</span>
      </div>
    </td>
    <td style="width:2%;vertical-align:top;padding:0;">&nbsp;</td>
    <td style="width:33%;vertical-align:top;padding:0;">
      <table style="width:100%;border-collapse:collapse;">
        <tr>
          <td style="border:1pt solid #000;vertical-align:top;padding:5pt 4pt;height:55pt;">
            &nbsp;
          </td>
        </tr>
        <tr>
          <td style="border-left:1pt solid #000;border-right:1pt solid #000;border-bottom:1pt solid #000;background-color:#f0f0f0;text-align:center;padding:4pt 4pt;vertical-align:middle;">
            <div style="font-size:7pt;">Signature (Sign inside the box)</div>
          </td>
        </tr>
        <tr>
          <td style="border-left:1pt solid #000;border-right:1pt solid #000;border-bottom:1pt solid #000;vertical-align:top;padding:2pt 4pt;height:5pt;">
            &nbsp;
          </td>
        </tr>
        <tr>
          <td style="border-left:1pt solid #000;border-right:1pt solid #000;border-bottom:1pt solid #000;background-color:#f0f0f0;text-align:center;padding:4pt 4pt;vertical-align:middle;">
            <div style="font-size:7pt;">Date Accomplished</div>
            <div style="font-weight:700;font-size:7pt;">${pi.date_accomplished ? fmtD(pi.date_accomplished) : ''}&nbsp;</div>
          </td>
        </tr>
      </table>
    </td>
    <td style="width:35%;vertical-align:top;padding:0 0 0 4pt;">
      <!-- RIGHT THUMBMARK BOX -->
      <div style="text-align:center;padding:4pt;">
        <div style="border:1.5pt solid #000;width:3.51cm;height:4.43cm;margin:0 auto;"></div>
        <div style="font-size:7pt;font-weight:700;font-style:italic;margin-top:4pt;">Right Thumbmark</div>
      </div>
    </td>
  </tr>
</table>

<!-- ── SUBSCRIBED AND SWORN ── -->
<table style="margin-top:2pt;">
  <tr>
    <td class="all" style="padding:3pt 4pt;font-size:6.5pt;line-height:1.6;">
      SUBSCRIBED AND SWORN to before me this <span style="display:inline-block;min-width:80pt;border-bottom:0.5pt solid #000;">&nbsp;</span>, affiant exhibiting his/her validly issued government ID as indicated above.
      <div style="margin-top:18pt;display:inline-block;width:45%;text-align:center;">
        <div style="border-top:0.5pt solid #000;padding-top:2pt;font-size:6pt;">Person Administering Oath</div>
      </div>
    </td>
  </tr>
</table>
<div class="pg-foot">CS FORM 212 (Revised 2025), Page 4 of 4</div>
</div>`;
  };

  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8"/>
  <title>PDS — CS Form 212 (Revised 2025)</title>
  <style>${CSS}</style>
</head>
<body>
${PAGE1()}
${PAGE2()}
${PAGE3()}
${PAGE4()}
</body>
</html>`;
}
function printPDSFromData(pdsData, empData) {
  try {
    const html = buildPDSPrintHTML(pdsData, empData);
    if (!html) {
      console.error('[printPDSFromData] ERROR: buildPDSPrintHTML returned undefined');
      return;
    }
    const w = window.open('', '_blank', 'width=960,height=1100');
    if (!w) {
      console.error('[printPDSFromData] ERROR: Failed to open print window (may be blocked by popup blocker)');
      return;
    }
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 600);
  } catch (e) {
    console.error('[printPDSFromData] ERROR:', e.message);
  }
}

/**
 * Print SALN from Data
 */
function printSALNFromData(salnData, empData) {
  try {
    const html = buildSALNPrintHTML(salnData, empData, false);
    if (!html) {
      console.error('[printSALNFromData] ERROR: buildSALNPrintHTML returned undefined');
      return;
    }
    const w = window.open('', '_blank', 'width=900,height=1100');
    if (!w) {
      console.error('[printSALNFromData] ERROR: Failed to open print window (may be blocked by popup blocker)');
      return;
    }
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 600);
  } catch (e) {
    console.error('[printSALNFromData] ERROR:', e.message);
  }
}