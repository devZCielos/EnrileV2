/**
 * ============================================================
 *  LGU ENRILE HRMS — Shared Print Utilities
 *  File: config/print-utilities.js
 *  Shared by Admin & Employee portals for consistent printing
 * ============================================================
 */

/**
 * Helper: Safe JSON parse
 */
function safeJson(s) {
  try {
    return s ? JSON.parse(s) : null;
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
//  Replaces the old version in config/print-utilities.js
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
  const ul  = (val, w) => `<span style="display:inline-block;min-width:${w||'60pt'};border-bottom:0.5pt solid #000;font-size:7pt;font-weight:700;padding:0 1pt;">${esc(v(val))}&nbsp;</span>`;
  const pad = (arr, n) => { const a = [...(arr||[])]; while(a.length<n) a.push({}); return a; };

  // ── parse PDS data ─────────────────────────────────────────
  const pi  = sj(d.personal_info)     || {};
  const fb  = sj(d.family_background) || {};
  const ed  = sj(d.education)         || [];
  const cs  = sj(d.civil_service)     || [];
  const we  = sj(d.work_experience)   || [];
  const vw  = sj(d.voluntary_work)    || [];
  const tr  = sj(d.trainings)         || [];
  const oi  = sj(d.other_info)        || {};
  const q4  = sj(d.q4_questions)      || {};
  const refs     = sj(d.pds_references || d['references'])       || [];
  const charRefs = sj(d.pds_character_references) || [];

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
    body{font-family:"Arial Narrow",Arial,sans-serif;font-size:7pt;color:#000;background:#fff;}
    @page{size:8.5in 13in;margin:6mm 7mm;}
    @media print{body{margin:0;}.pg{page-break-after:always;}.pg:last-child{page-break-after:auto;}}
    table{border-collapse:collapse;table-layout:fixed;}
    td,th{overflow:hidden;font-size:7pt;vertical-align:middle;padding:1pt 2pt;}
    .bdr{border:0.5pt solid #000;}
    .bdt{border-top:0.5pt solid #000;}
    .bdb{border-bottom:0.5pt solid #000;}
    .bdl{border-left:0.5pt solid #000;}
    .bdr2{border-right:0.5pt solid #000;}
    .all{border:0.5pt solid #000;}
    .hdr{background:#d9d9d9;font-size:6pt;font-weight:700;text-align:center;border:0.5pt solid #000;}
    .hdr2{background:#d9d9d9;font-size:6pt;font-weight:700;border:0.5pt solid #000;}
    .sec{background:#808080;color:#fff;font-size:7pt;font-weight:700;border:0.5pt solid #000;padding:2pt 4pt;}
    .lbl{font-size:6pt;color:#444;display:block;line-height:1.1;}
    .val{font-size:8pt;font-weight:700;display:block;min-height:9pt;word-break:break-word;}
    .yn{display:inline-block;border:0.5pt solid #000;width:8pt;height:8pt;text-align:center;line-height:8pt;font-size:7pt;margin-right:1pt;}
    .sig{height:26pt;border-bottom:0.5pt solid #000;}
    .pg{padding-bottom:8pt;}
    .warn{font-size:6pt;font-weight:700;font-style:italic;}
    .note{font-size:6pt;}
    .pg-foot{font-size:6pt;text-align:right;margin-top:2pt;}
  `;

  // ── PAGE 1 — Personal Information + Family + Education ─────
  const PAGE1 = () => {
    const perm = pi.perm_same
      ? { housenum:pi.res_housenum, street:pi.res_street, subdivision:pi.res_subdivision,
          barangay:pi.res_barangay, city:pi.res_city, province:pi.res_province, zip:pi.res_zip }
      : { housenum:pi.perm_housenum, street:pi.perm_street, subdivision:pi.perm_subdivision,
          barangay:pi.perm_barangay, city:pi.perm_city, province:pi.perm_province, zip:pi.perm_zip };

    // Education rows (Elementary, Secondary, Vocational, College, Graduate Studies)
    const EDU_LEVELS = ['Elementary','Secondary','Vocational/Trade Course','College','Graduate Studies'];
    const eduRows = EDU_LEVELS.map(lvl => {
      const e = edu(lvl);
      return `<tr style="height:22pt;">
        <td class="all" style="width:11%;font-size:6.5pt;font-weight:700;vertical-align:top;padding:2pt;">${esc(lvl)}</td>
        <td class="all" style="width:18%;font-size:7pt;vertical-align:top;">${esc(e.school)}</td>
        <td class="all" style="width:15%;font-size:7pt;vertical-align:top;">${esc(e.degree)}</td>
        <td class="all" style="width:7%;text-align:center;font-size:7pt;">${esc(e.from)}</td>
        <td class="all" style="width:7%;text-align:center;font-size:7pt;">${esc(e.to)}</td>
        <td class="all" style="width:11%;text-align:center;font-size:7pt;">${esc(e.units)}</td>
        <td class="all" style="width:9%;text-align:center;font-size:7pt;">${esc(e.year_grad)}</td>
        <td class="all" style="width:22%;font-size:7pt;vertical-align:top;">${esc(e.honors)}</td>
      </tr>`;
    }).join('');

    // Children rows (min 8)
    const children = pad(fb.children || [], 8);
    const childRows = children.map(ch => `<tr style="height:15pt;">
      <td class="all" style="width:58%;font-size:7pt;">${esc(ch.name)}&nbsp;</td>
      <td class="all" style="width:42%;text-align:center;font-size:7pt;">${esc(ch.dob ? fmtD(ch.dob) : ch.dob)}&nbsp;</td>
    </tr>`).join('');

    return `<div class="pg">
<!-- ── HEADER ── -->
<table style="width:100%;margin-bottom:1pt;">
  <tr>
    <td style="width:20%;font-size:6.5pt;font-style:italic;border:0.5pt solid #000;padding:2pt 4pt;vertical-align:top;">
      CS Form No. 212<br>Revised 2025
    </td>
    <td style="width:80%;border:0.5pt solid #000;border-left:none;text-align:center;padding:3pt 0;vertical-align:middle;">
      <div style="font-size:14pt;font-weight:900;text-transform:uppercase;letter-spacing:1pt;">Personal Data Sheet</div>
    </td>
  </tr>
</table>
<table style="width:100%;margin-bottom:1pt;">
  <tr>
    <td class="all" style="padding:2pt 4pt;">
      <div class="warn">WARNING: Any misrepresentation made in the Personal Data Sheet and the Work Experience Sheet shall cause the filing of administrative/criminal case/s against the person concerned.</div>
      <div class="warn">READ THE ATTACHED GUIDE TO FILLING OUT THE PERSONAL DATA SHEET (PDS) BEFORE ACCOMPLISHING THE PDS FORM.</div>
      <div class="note">Print legibly if accomplished through own handwriting. Tick appropriate boxes (☐) and use separate sheet if necessary. Indicate N/A if not applicable. <strong>DO NOT ABBREVIATE.</strong></div>
    </td>
  </tr>
</table>

<!-- ── SECTION I HEADER ── -->
<table style="width:100%;"><tr><td class="sec" colspan="99">I. PERSONAL INFORMATION</td></tr></table>

<!-- ── NAME + EXTENSION ── -->
<table style="width:100%;">
  <tr>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;padding:1pt 2pt;">1.</td>
    <td class="all" style="width:30%;">
      <span class="lbl">SURNAME</span>
      <span class="val">${esc(pi.last_name)}</span>
    </td>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;padding:1pt 2pt;">2.</td>
    <td class="all" style="width:27%;">
      <span class="lbl">FIRST NAME</span>
      <span class="val">${esc(pi.first_name)}</span>
    </td>
    <td class="all" style="width:15%;">
      <span class="lbl">NAME EXTENSION (JR., SR.)</span>
      <span class="val">${esc(pi.suffix)}</span>
    </td>
    <!-- CITIZENSHIP — rowspan block -->
    <td class="all" rowspan="6" style="width:22%;vertical-align:top;padding:3pt 4pt;font-size:6.5pt;">
      <strong>16. CITIZENSHIP</strong><br><br>
      <div style="margin:2pt 0;">${ynBox(pi.citizenship==='Filipino'||!pi.citizenship, 'Filipino')}</div>
      <div style="margin:2pt 0;">${ynBox(pi.citizenship==='Dual Citizenship', 'Dual Citizenship')}</div>
      <div style="font-size:6pt;margin-top:4pt;">If holder of dual citizenship, please indicate the details.</div>
      <div style="margin-top:3pt;font-size:6pt;">By: &nbsp;${ynBox(pi.dual_by==='Birth','Birth')} &nbsp;${ynBox(pi.dual_by==='Naturalization','Naturalization')}</div>
      <div style="margin-top:3pt;font-size:6pt;">Pls. indicate country:</div>
      <div style="font-size:7pt;font-weight:700;border-bottom:0.5pt solid #000;min-height:10pt;">${esc(pi.dual_country)}&nbsp;</div>
    </td>
  </tr>
  <tr>
    <td class="all" style="width:3%;text-align:right;font-size:6pt;"></td>
    <td class="all" colspan="2" style="width:60%;">
      <span class="lbl">MIDDLE NAME</span>
      <span class="val">${esc(pi.middle_name)}</span>
    </td>
    <td class="all" style="width:15%;font-size:6pt;"></td>
  </tr>
  <tr>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">3.</td>
    <td class="all" style="width:30%;">
      <span class="lbl">DATE OF BIRTH (dd/mm/yyyy)</span>
      <span class="val">${pi.dob ? fmtD(pi.dob) : ''}</span>
    </td>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">5.</td>
    <td class="all" style="width:27%;padding:2pt;">
      <span class="lbl">SEX AT BIRTH</span>
      <div style="margin-top:1pt;">${ynBox(pi.sex==='Male','Male')} &nbsp; ${ynBox(pi.sex==='Female','Female')}</div>
    </td>
    <td class="all" style="width:15%;vertical-align:top;padding:2pt;">
      <span class="lbl">CIVIL STATUS</span>
      <div style="font-size:6.5pt;line-height:1.5;">
        ${ynBox(pi.civil_status==='Single','Single')} ${ynBox(pi.civil_status==='Married','Married')}<br>
        ${ynBox(pi.civil_status==='Widow/er','Widow/er')}<br>
        ${ynBox(pi.civil_status==='Separated','Separated')}<br>
        ${ynBox(pi.civil_status==='Solo Parent','Solo Parent')}<br>
        ${ynBox(pi.civil_status==='Others','Others')}
      </div>
    </td>
  </tr>
  <tr>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">4.</td>
    <td class="all" colspan="2" style="width:60%;">
      <span class="lbl">PLACE OF BIRTH</span>
      <span class="val">${esc(pi.pob)}</span>
    </td>
    <td class="all" style="width:15%;"></td>
  </tr>
  <tr>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">7.</td>
    <td class="all" style="width:30%;">
      <span class="lbl">HEIGHT (m)</span>
      <span class="val">${esc(pi.height)}</span>
    </td>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">10.</td>
    <td class="all" style="width:27%;">
      <span class="lbl">UMID ID NO.</span>
      <span class="val">${esc(pi.umid)}</span>
    </td>
    <td class="all" style="width:15%;"></td>
  </tr>
  <tr>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">8.</td>
    <td class="all" style="width:30%;">
      <span class="lbl">WEIGHT (kg)</span>
      <span class="val">${esc(pi.weight)}</span>
    </td>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">11.</td>
    <td class="all" style="width:27%;">
      <span class="lbl">PAG-IBIG ID NO.</span>
      <span class="val">${esc(pi.pagibig)}</span>
    </td>
    <td class="all" style="width:15%;"></td>
  </tr>
</table>

<!-- ── IDs + ADDRESSES ── -->
<table style="width:100%;">
  <tr>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">9.</td>
    <td class="all" style="width:20%;">
      <span class="lbl">BLOOD TYPE</span>
      <span class="val">${esc(pi.blood_type)}</span>
    </td>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">12.</td>
    <td class="all" style="width:22%;">
      <span class="lbl">PHILHEALTH NO.</span>
      <span class="val">${esc(pi.philhealth)}</span>
    </td>
    <!-- RESIDENTIAL ADDRESS rowspan=5 -->
    <td class="all" rowspan="5" style="width:52%;vertical-align:top;padding:2pt 3pt;font-size:6.5pt;">
      <strong>17. RESIDENTIAL ADDRESS</strong>
      <table style="width:100%;border-collapse:collapse;margin-top:2pt;">
        <tr>
          <td style="width:50%;padding:0 2pt 0 0;font-size:6pt;">House/Block/Lot No.<br><strong>${esc(pi.res_housenum)}</strong></td>
          <td style="width:50%;font-size:6pt;">Street<br><strong>${esc(pi.res_street)}</strong></td>
        </tr>
        <tr>
          <td style="padding:2pt 2pt 0 0;font-size:6pt;">Subdivision/Village<br><strong>${esc(pi.res_subdivision)}</strong></td>
          <td style="padding:2pt 0 0 0;font-size:6pt;">Barangay<br><strong>${esc(pi.res_barangay)}</strong></td>
        </tr>
        <tr>
          <td style="padding:2pt 2pt 0 0;font-size:6pt;">City/Municipality<br><strong>${esc(pi.res_city)}</strong></td>
          <td style="padding:2pt 0 0 0;font-size:6pt;">Province<br><strong>${esc(pi.res_province)}</strong></td>
        </tr>
        <tr>
          <td colspan="2" style="padding:2pt 0 0 0;font-size:6pt;">ZIP Code<br><strong>${esc(pi.res_zip)}</strong></td>
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">13.</td>
    <td class="all" style="width:20%;">
      <span class="lbl">PhilSys Number (PSN)</span>
      <span class="val">${esc(pi.psn)}</span>
    </td>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">19.</td>
    <td class="all" style="width:22%;">
      <span class="lbl">TELEPHONE NO.</span>
      <span class="val">${esc(pi.tel)}</span>
    </td>
  </tr>
  <tr>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">14.</td>
    <td class="all" style="width:20%;">
      <span class="lbl">TIN NO.</span>
      <span class="val">${esc(pi.tin)}</span>
    </td>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">20.</td>
    <td class="all" style="width:22%;">
      <span class="lbl">MOBILE NO.</span>
      <span class="val">${esc(pi.mobile)}</span>
    </td>
  </tr>
  <tr>
    <td class="all" colspan="4" style="width:48%;">
      <span class="lbl">15. AGENCY EMPLOYEE NO.</span>
      <span class="val">${esc(pi.agency_no)}</span>
    </td>
  </tr>
  <tr>
    <td class="all" colspan="4" style="width:48%;">
      <span class="lbl">21. E-MAIL ADDRESS (if any)</span>
      <span class="val">${esc(pi.email)}</span>
    </td>
  </tr>
</table>

<!-- ── PERMANENT ADDRESS ── -->
<table style="width:100%;">
  <tr>
    <td class="all" style="padding:2pt 3pt;font-size:6.5pt;" colspan="99">
      <strong>18. PERMANENT ADDRESS</strong>${pi.perm_same?' <em>(Same as Residential Address)</em>':''}
      <table style="width:100%;border-collapse:collapse;margin-top:2pt;">
        <tr>
          <td style="width:25%;padding:0 2pt 0 0;font-size:6pt;">House/Block/Lot No.<br><strong>${esc(perm.housenum)}</strong></td>
          <td style="width:25%;padding:0 2pt 0 0;font-size:6pt;">Street<br><strong>${esc(perm.street)}</strong></td>
          <td style="width:25%;padding:0 2pt 0 0;font-size:6pt;">Subdivision/Village<br><strong>${esc(perm.subdivision)}</strong></td>
          <td style="width:25%;font-size:6pt;">Barangay<br><strong>${esc(perm.barangay)}</strong></td>
        </tr>
        <tr>
          <td style="padding:2pt 2pt 0 0;font-size:6pt;">City/Municipality<br><strong>${esc(perm.city)}</strong></td>
          <td style="padding:2pt 2pt 0 0;font-size:6pt;">Province<br><strong>${esc(perm.province)}</strong></td>
          <td colspan="2" style="padding:2pt 0 0 0;font-size:6pt;">ZIP Code<br><strong>${esc(perm.zip)}</strong></td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<!-- ── SECTION II FAMILY BACKGROUND ── -->
<table style="width:100%;margin-top:1pt;"><tr><td class="sec">II. FAMILY BACKGROUND</td></tr></table>
<table style="width:100%;">
  <tr>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;vertical-align:top;padding-top:3pt;">22.</td>
    <td class="all" style="width:45%;vertical-align:top;padding:2pt 3pt;font-size:6.5pt;">
      <table style="width:100%;border-collapse:collapse;">
        <tr><td style="font-size:6pt;">SPOUSE'S SURNAME</td></tr>
        <tr><td style="font-weight:700;min-height:10pt;">${esc(fb.sp_surname)}&nbsp;</td></tr>
        <tr><td style="font-size:6pt;padding-top:1pt;">FIRST NAME</td></tr>
        <tr><td style="font-weight:700;min-height:10pt;">${esc(fb.sp_firstname)}&nbsp;</td></tr>
        <tr><td style="font-size:6pt;padding-top:1pt;">NAME EXTENSION (JR., SR.)</td></tr>
        <tr><td style="font-weight:700;min-height:10pt;">${esc(fb.sp_ext)}&nbsp;</td></tr>
        <tr><td style="font-size:6pt;padding-top:1pt;">MIDDLE NAME</td></tr>
        <tr><td style="font-weight:700;min-height:10pt;">${esc(fb.sp_middle)}&nbsp;</td></tr>
        <tr><td style="font-size:6pt;padding-top:1pt;">OCCUPATION</td></tr>
        <tr><td style="font-weight:700;min-height:10pt;">${esc(fb.sp_occupation)}&nbsp;</td></tr>
        <tr><td style="font-size:6pt;padding-top:1pt;">EMPLOYER/BUSINESS NAME</td></tr>
        <tr><td style="font-weight:700;min-height:10pt;">${esc(fb.sp_employer)}&nbsp;</td></tr>
        <tr><td style="font-size:6pt;padding-top:1pt;">BUSINESS ADDRESS</td></tr>
        <tr><td style="font-weight:700;min-height:10pt;">${esc(fb.sp_biz_addr)}&nbsp;</td></tr>
        <tr><td style="font-size:6pt;padding-top:1pt;">TELEPHONE NO.</td></tr>
        <tr><td style="font-weight:700;min-height:10pt;">${esc(fb.sp_tel)}&nbsp;</td></tr>
      </table>
    </td>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;vertical-align:top;padding-top:3pt;">23.</td>
    <td class="all" style="width:49%;vertical-align:top;padding:2pt 3pt;font-size:6.5pt;">
      <div style="font-size:6pt;font-weight:700;margin-bottom:2pt;">NAME OF CHILDREN (Write full name and list all)</div>
      <table style="width:100%;border-collapse:collapse;">
        <tr>
          <th style="border:0.5pt solid #000;background:#d9d9d9;font-size:6pt;padding:2pt;width:58%;">NAME OF CHILD</th>
          <th style="border:0.5pt solid #000;background:#d9d9d9;font-size:6pt;padding:2pt;width:42%;text-align:center;">DATE OF BIRTH<br>(mm/dd/yyyy)</th>
        </tr>
        ${childRows}
      </table>
    </td>
  </tr>
</table>

<!-- ── FATHER + MOTHER ── -->
<table style="width:100%;">
  <tr>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">24.</td>
    <td class="all" style="width:45%;padding:2pt 3pt;font-size:6.5pt;">
      <div style="font-size:6pt;font-weight:700;">FATHER'S NAME</div>
      <table style="width:100%;border-collapse:collapse;">
        <tr>
          <td style="width:34%;font-size:6pt;">SURNAME<br><strong>${esc(fb.fa_surname)}&nbsp;</strong></td>
          <td style="width:34%;font-size:6pt;padding-left:3pt;">FIRST NAME<br><strong>${esc(fb.fa_firstname)}&nbsp;</strong></td>
          <td style="width:16%;font-size:6pt;padding-left:3pt;">NAME EXT.<br><strong>${esc(fb.fa_ext)}&nbsp;</strong></td>
          <td style="width:16%;font-size:6pt;padding-left:3pt;">MIDDLE NAME<br><strong>${esc(fb.fa_middle)}&nbsp;</strong></td>
        </tr>
      </table>
    </td>
    <td class="all" style="width:3%;text-align:right;font-size:7pt;">25.</td>
    <td class="all" style="width:49%;padding:2pt 3pt;font-size:6.5pt;">
      <div style="font-size:6pt;font-weight:700;">MOTHER'S MAIDEN NAME</div>
      <table style="width:100%;border-collapse:collapse;">
        <tr>
          <td style="width:34%;font-size:6pt;">SURNAME<br><strong>${esc(fb.mo_surname)}&nbsp;</strong></td>
          <td style="width:34%;font-size:6pt;padding-left:3pt;">FIRST NAME<br><strong>${esc(fb.mo_firstname)}&nbsp;</strong></td>
          <td style="width:32%;font-size:6pt;padding-left:3pt;">MIDDLE NAME<br><strong>${esc(fb.mo_middle)}&nbsp;</strong></td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<!-- ── SECTION III EDUCATIONAL BACKGROUND ── -->
<table style="width:100%;margin-top:1pt;"><tr><td class="sec">III. EDUCATIONAL BACKGROUND</td></tr></table>
<table style="width:100%;">
  <thead>
    <tr>
      <th class="hdr" style="width:3%;">26.</th>
      <th class="hdr" style="width:11%;">LEVEL</th>
      <th class="hdr" style="width:18%;">NAME OF SCHOOL<br><span style="font-weight:400;">(Write in full)</span></th>
      <th class="hdr" style="width:15%;">BASIC EDUCATION/DEGREE/COURSE<br><span style="font-weight:400;">(Write in full)</span></th>
      <th class="hdr" style="width:7%;">PERIOD<br>From</th>
      <th class="hdr" style="width:7%;">PERIOD<br>To</th>
      <th class="hdr" style="width:11%;">HIGHEST LEVEL/UNITS EARNED<br><span style="font-weight:400;">(if not graduated)</span></th>
      <th class="hdr" style="width:9%;">YEAR GRADUATED</th>
      <th class="hdr" style="width:19%;">SCHOLARSHIP/ACADEMIC HONORS RECEIVED</th>
    </tr>
  </thead>
  <tbody>
    ${eduRows}
  </tbody>
</table>
<table style="width:100%;"><tr>
  <td class="all" style="text-align:center;font-size:6.5pt;font-weight:700;">(Continue on separate sheet if necessary)</td>
</tr></table>

<!-- ── SIGNATURE + PAGE FOOTER ── -->
<table style="width:100%;margin-top:2pt;">
  <tr>
    <td class="all" style="width:50%;text-align:center;padding:2pt;vertical-align:bottom;">
      <div class="sig"></div>
      <div style="font-size:6pt;">SIGNATURE (wet signature/e-signature/digital certificate)</div>
    </td>
    <td class="all" style="width:50%;text-align:center;padding:2pt;vertical-align:bottom;">
      <div class="sig"></div>
      <div style="font-size:6pt;">DATE</div>
    </td>
  </tr>
</table>
<div class="pg-foot">CS FORM 212 (Revised 2025), Page 1 of 4</div>
</div>`;
  };

  // ── PAGE 2 — Civil Service + Work Experience ───────────────
  const PAGE2 = () => {
    const csPad = pad(cs, 7);
    const wePad = pad(we, 28);

    const csRows = csPad.map(e => `<tr style="height:21pt;">
      <td class="all" style="width:3%;"></td>
      <td class="all" style="width:30%;font-size:7pt;">${esc(e.eligibility)}&nbsp;</td>
      <td class="all" style="width:10%;text-align:center;font-size:7pt;">${esc(e.rating)}&nbsp;</td>
      <td class="all" style="width:13%;text-align:center;font-size:7pt;">${e.exam_date ? fmtD(e.exam_date) : ''}&nbsp;</td>
      <td class="all" style="width:22%;font-size:7pt;">${esc(e.exam_place)}&nbsp;</td>
      <td class="all" style="width:11%;text-align:center;font-size:7pt;">${esc(e.license_no)}&nbsp;</td>
      <td class="all" style="width:11%;text-align:center;font-size:7pt;">${e.license_validity ? fmtD(e.license_validity) : ''}&nbsp;</td>
    </tr>`).join('');

    const weRows = wePad.map(e => `<tr style="height:21pt;">
      <td class="all" style="width:3%;"></td>
      <td class="all" style="width:9%;text-align:center;font-size:6.5pt;">${e.date_from ? fmtD(e.date_from) : ''}&nbsp;</td>
      <td class="all" style="width:9%;text-align:center;font-size:6.5pt;">${e.date_to ? fmtD(e.date_to) : ''}&nbsp;</td>
      <td class="all" style="width:20%;font-size:7pt;">${esc(e.position)}&nbsp;</td>
      <td class="all" style="width:22%;font-size:7pt;">${esc(e.dept_agency)}&nbsp;</td>
      <td class="all" style="width:10%;text-align:right;font-size:7pt;">${e.monthly_salary ? '₱'+parseFloat(e.monthly_salary).toLocaleString() : ''}&nbsp;</td>
      <td class="all" style="width:10%;text-align:center;font-size:7pt;">${esc(e.salary_grade)}&nbsp;</td>
      <td class="all" style="width:10%;font-size:7pt;">${esc(e.status)}&nbsp;</td>
      <td class="all" style="width:7%;text-align:center;font-size:7pt;">${esc(e.govt_service)}&nbsp;</td>
    </tr>`).join('');

    return `<div class="pg">
<table style="width:100%;"><tr><td class="sec">IV. CIVIL SERVICE ELIGIBILITY</td></tr></table>
<table style="width:100%;">
  <thead>
    <tr>
      <th class="hdr" style="width:3%;">27.</th>
      <th class="hdr" style="width:30%;">CES/CSEE/CAREER SERVICE/RA 1080 (BOARD/BAR)/UNDER SPECIAL LAWS/CATEGORY II/IV ELIGIBILITY and ELIGIBILITIES FOR UNIFORMED PERSONNEL</th>
      <th class="hdr" style="width:10%;">RATING<br><span style="font-weight:400;">(If Applicable)</span></th>
      <th class="hdr" style="width:13%;">DATE OF EXAMINATION/<br>CONFERMENT</th>
      <th class="hdr" style="width:22%;">PLACE OF EXAMINATION/<br>CONFERMENT</th>
      <th class="hdr" style="width:11%;">LICENSE NUMBER<br><span style="font-weight:400;">(if applicable)</span></th>
      <th class="hdr" style="width:11%;">LICENSE VALID UNTIL</th>
    </tr>
  </thead>
  <tbody>${csRows}</tbody>
</table>
<table style="width:100%;"><tr><td class="all" style="text-align:center;font-size:6.5pt;font-weight:700;">(Continue on separate sheet if necessary)</td></tr></table>

<table style="width:100%;margin-top:1pt;"><tr><td class="sec">V. WORK EXPERIENCE</td></tr></table>
<table style="width:100%;"><tr>
  <td class="all" style="font-size:6.5pt;font-weight:700;font-style:italic;padding:2pt 4pt;">
    (Include private employment. Start from your recent work.) Description of duties should be indicated in the attached Work Experience Sheet.
  </td>
</tr></table>
<table style="width:100%;">
  <thead>
    <tr>
      <th class="hdr" style="width:3%;">28.</th>
      <th class="hdr" style="width:9%;">INCLUSIVE DATES<br>From</th>
      <th class="hdr" style="width:9%;">INCLUSIVE DATES<br>To</th>
      <th class="hdr" style="width:20%;">POSITION TITLE<br><span style="font-weight:400;">(Write in full/Do not abbreviate)</span></th>
      <th class="hdr" style="width:22%;">DEPARTMENT/AGENCY/OFFICE/COMPANY<br><span style="font-weight:400;">(Write in full/Do not abbreviate)</span></th>
      <th class="hdr" style="width:10%;">MONTHLY SALARY</th>
      <th class="hdr" style="width:10%;">SALARY/JOB/PAY GRADE<br><span style="font-weight:400;">(Format: SG/Step)</span></th>
      <th class="hdr" style="width:10%;">STATUS OF APPOINTMENT</th>
      <th class="hdr" style="width:7%;">GOV'T SERVICE<br>(Y/N)</th>
    </tr>
  </thead>
  <tbody>${weRows}</tbody>
</table>
<table style="width:100%;"><tr><td class="all" style="text-align:center;font-size:6.5pt;font-weight:700;">(Continue on separate sheet if necessary)</td></tr></table>

<table style="width:100%;margin-top:2pt;">
  <tr>
    <td class="all" style="width:50%;text-align:center;padding:2pt;vertical-align:bottom;">
      <div class="sig"></div>
      <div style="font-size:6pt;">SIGNATURE (wet signature/e-signature/digital certificate)</div>
    </td>
    <td class="all" style="width:50%;text-align:center;padding:2pt;vertical-align:bottom;">
      <div class="sig"></div>
      <div style="font-size:6pt;">DATE</div>
    </td>
  </tr>
</table>
<div class="pg-foot">CS FORM 212 (Revised 2025), Page 2 of 4</div>
</div>`;
  };

  // ── PAGE 3 — Voluntary Work + L&D + Other Info ────────────
  const PAGE3 = () => {
    const vwPad = pad(vw, 7);
    const trPad = pad(tr, 22);

    const vwRows = vwPad.map(e => `<tr style="height:22pt;">
      <td class="all" style="width:3%;"></td>
      <td class="all" style="width:33%;font-size:7pt;">${esc(e.org_name)}&nbsp;</td>
      <td class="all" style="width:12%;text-align:center;font-size:7pt;">${e.date_from ? fmtD(e.date_from) : ''}&nbsp;</td>
      <td class="all" style="width:12%;text-align:center;font-size:7pt;">${e.date_to ? fmtD(e.date_to) : ''}&nbsp;</td>
      <td class="all" style="width:10%;text-align:center;font-size:7pt;">${esc(e.no_hours)}&nbsp;</td>
      <td class="all" style="width:30%;font-size:7pt;">${esc(e.position)}&nbsp;</td>
    </tr>`).join('');

    const trRows = trPad.map(e => `<tr style="height:22pt;">
      <td class="all" style="width:3%;"></td>
      <td class="all" style="width:30%;font-size:7pt;">${esc(e.title)}&nbsp;</td>
      <td class="all" style="width:11%;text-align:center;font-size:7pt;">${e.date_from ? fmtD(e.date_from) : ''}&nbsp;</td>
      <td class="all" style="width:11%;text-align:center;font-size:7pt;">${e.date_to ? fmtD(e.date_to) : ''}&nbsp;</td>
      <td class="all" style="width:9%;text-align:center;font-size:7pt;">${esc(e.no_hours)}&nbsp;</td>
      <td class="all" style="width:12%;font-size:7pt;">${esc(e.ld_type)}&nbsp;</td>
      <td class="all" style="width:24%;font-size:7pt;">${esc(e.conducted_by)}&nbsp;</td>
    </tr>`).join('');

    const SKILL_ROWS = 7;
    const skillsPad = pad(skills.map(s=>({v:s})), SKILL_ROWS);
    const distinctPad = pad(distinctions.map(s=>({v:s})), SKILL_ROWS);
    const memberPad = pad(memberships.map(s=>({v:s})), SKILL_ROWS);
    const oiRows = Array.from({length: SKILL_ROWS}, (_,i) => `<tr style="height:22pt;">
      <td class="all" style="font-size:7pt;">${esc(skillsPad[i]?.v || '')}&nbsp;</td>
      <td class="all" style="font-size:7pt;">${esc(distinctPad[i]?.v || '')}&nbsp;</td>
      <td class="all" style="font-size:7pt;">${esc(memberPad[i]?.v || '')}&nbsp;</td>
    </tr>`).join('');

    return `<div class="pg">
<table style="width:100%;"><tr><td class="sec">VI. VOLUNTARY WORK OR INVOLVEMENT IN CIVIC/NON-GOVERNMENT/PEOPLE/VOLUNTARY ORGANIZATION/S</td></tr></table>
<table style="width:100%;">
  <thead>
    <tr>
      <th class="hdr" style="width:3%;">29.</th>
      <th class="hdr" style="width:33%;">NAME &amp; ADDRESS OF ORGANIZATION<br><span style="font-weight:400;">(Write in full)</span></th>
      <th class="hdr" style="width:12%;">INCLUSIVE DATES<br>From</th>
      <th class="hdr" style="width:12%;">INCLUSIVE DATES<br>To</th>
      <th class="hdr" style="width:10%;">NUMBER OF HOURS</th>
      <th class="hdr" style="width:30%;">POSITION/NATURE OF WORK</th>
    </tr>
  </thead>
  <tbody>${vwRows}</tbody>
</table>
<table style="width:100%;"><tr><td class="all" style="text-align:center;font-size:6.5pt;font-weight:700;">(Continue on separate sheet if necessary)</td></tr></table>

<table style="width:100%;margin-top:1pt;"><tr><td class="sec">VII. LEARNING AND DEVELOPMENT (L&amp;D) INTERVENTIONS/TRAINING PROGRAMS ATTENDED</td></tr></table>
<table style="width:100%;">
  <thead>
    <tr>
      <th class="hdr" style="width:3%;">30.</th>
      <th class="hdr" style="width:30%;">TITLE OF L&amp;D INTERVENTIONS/TRAINING PROGRAMS<br><span style="font-weight:400;">(Write in full)</span></th>
      <th class="hdr" style="width:11%;">INCLUSIVE DATES<br>From</th>
      <th class="hdr" style="width:11%;">INCLUSIVE DATES<br>To</th>
      <th class="hdr" style="width:9%;">NUMBER OF HOURS</th>
      <th class="hdr" style="width:12%;">TYPE OF L&amp;D<br><span style="font-weight:400;">(Managerial/Supervisory/Technical/etc.)</span></th>
      <th class="hdr" style="width:24%;">CONDUCTED/SPONSORED BY<br><span style="font-weight:400;">(Write in full)</span></th>
    </tr>
  </thead>
  <tbody>${trRows}</tbody>
</table>
<table style="width:100%;"><tr><td class="all" style="text-align:center;font-size:6.5pt;font-weight:700;">(Continue on separate sheet if necessary)</td></tr></table>

<table style="width:100%;margin-top:1pt;"><tr><td class="sec">VIII. OTHER INFORMATION</td></tr></table>
<table style="width:100%;">
  <thead>
    <tr>
      <th class="hdr" style="width:33%;">31. SPECIAL SKILLS and HOBBIES</th>
      <th class="hdr" style="width:34%;">32. NON-ACADEMIC DISTINCTIONS/RECOGNITION<br><span style="font-weight:400;">(Write in full)</span></th>
      <th class="hdr" style="width:33%;">33. MEMBERSHIP IN ASSOCIATION/ORGANIZATION<br><span style="font-weight:400;">(Write in full)</span></th>
    </tr>
  </thead>
  <tbody>${oiRows}</tbody>
</table>
<table style="width:100%;"><tr><td class="all" style="text-align:center;font-size:6.5pt;font-weight:700;">(Continue on separate sheet if necessary)</td></tr></table>

<table style="width:100%;margin-top:2pt;">
  <tr>
    <td class="all" style="width:50%;text-align:center;padding:2pt;vertical-align:bottom;">
      <div class="sig"></div>
      <div style="font-size:6pt;">SIGNATURE (wet signature/e-signature/digital certificate)</div>
    </td>
    <td class="all" style="width:50%;text-align:center;padding:2pt;vertical-align:bottom;">
      <div class="sig"></div>
      <div style="font-size:6pt;">DATE</div>
    </td>
  </tr>
</table>
<div class="pg-foot">CS FORM 212 (Revised 2025), Page 3 of 4</div>
</div>`;
  };

  // ── PAGE 4 — Q34-40 + References + Declaration ────────────
  const PAGE4 = () => {
    const ynRow = (num, question, yesKey, detKey, extraHtml) => `
      <tr>
        <td class="all" style="width:3%;text-align:center;font-size:7pt;vertical-align:top;padding-top:3pt;">${num}</td>
        <td class="all" style="width:55%;font-size:6.5pt;line-height:1.5;padding:3pt;">${question}</td>
        <td class="all" style="width:9%;text-align:center;vertical-align:middle;">
          <span class="yn">${cb(q4[yesKey])}</span><br><span style="font-size:6pt;">YES</span>
        </td>
        <td class="all" style="width:9%;text-align:center;vertical-align:middle;">
          <span class="yn">${cb(q4[yesKey]==='No'||(q4[yesKey]!=='Yes'&&!q4[yesKey]))}</span><br><span style="font-size:6pt;">NO</span>
        </td>
        <td class="all" style="width:24%;font-size:6pt;vertical-align:top;padding:2pt;">
          ${detKey ? `If YES, give details:<br><strong>${esc(q4[detKey])}&nbsp;</strong>` : ''}
          ${extraHtml || ''}
        </td>
      </tr>`;

    const refsPad = pad(refs, 7);
    const charRefsPad = pad(charRefs, 3);

    const refRows = refsPad.map(r => `<tr style="height:18pt;">
      <td class="all" style="width:28%;font-size:7pt;">${esc(r.name)}&nbsp;</td>
      <td class="all" style="width:44%;font-size:7pt;">${esc(r.address)}&nbsp;</td>
      <td class="all" style="width:28%;font-size:7pt;">${esc(r.tel)}&nbsp;</td>
    </tr>`).join('');

    const charRefRows = charRefsPad.map(r => `<tr style="height:18pt;">
      <td class="all" style="width:28%;font-size:7pt;">${esc(r.name)}&nbsp;</td>
      <td class="all" style="width:44%;font-size:7pt;">${esc(r.address)}&nbsp;</td>
      <td class="all" style="width:28%;font-size:7pt;">${esc(r.tel)}&nbsp;</td>
    </tr>`).join('');

    return `<div class="pg">
<!-- ── QUESTIONS 34-40 ── -->
<table style="width:100%;">
  <thead>
    <tr>
      <th class="hdr" style="width:3%;">#</th>
      <th class="hdr" style="width:55%;">QUESTION</th>
      <th class="hdr" style="width:9%;">YES</th>
      <th class="hdr" style="width:9%;">NO</th>
      <th class="hdr" style="width:24%;">If YES, give details</th>
    </tr>
  </thead>
  <tbody>
    ${ynRow('34a',
      'Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau or office or to the person who has immediate supervision over you in the Office, Bureau or Department where you will be appointed,<br><strong>a. within the third degree?</strong>',
      'q34a','q34_details')}
    ${ynRow('34b',
      '<strong>b.</strong> within the fourth degree (for Local Government Unit – Career Employees)?',
      'q34b','')}
    ${ynRow('35a',
      '<strong>a.</strong> Have you ever been found guilty of any <strong>administrative offense</strong>?',
      'q35a','q35a_details')}
    ${ynRow('35b',
      '<strong>b.</strong> Have you been <strong>criminally charged</strong> before any court?',
      'q35b','',
      `Date Filed: <strong>${q4.q35b_date ? fmtD(q4.q35b_date) : ''}&nbsp;</strong><br>
       Status of Case/s: <strong>${esc(q4.q35b_status)}&nbsp;</strong>`)}
    ${ynRow('36',
      'Have you ever been <strong>convicted</strong> of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?',
      'q36','q36_details')}
    ${ynRow('37',
      'Have you ever been <strong>separated from the service</strong> in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?',
      'q37','q37_details')}
    ${ynRow('38a',
      '<strong>a.</strong> Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?',
      'q38a','q38a_details')}
    ${ynRow('38b',
      '<strong>b.</strong> Have you resigned from the government service during the three (3)-month period before the last election to promote/actively campaign for a national or local candidate?',
      'q38b','q38b_details')}
    ${ynRow('39',
      'Have you acquired the status of an immigrant or permanent resident of another country?',
      'q39','q39_details')}
    <tr>
      <td class="all" style="width:3%;text-align:center;font-size:7pt;vertical-align:top;padding-top:3pt;">40.</td>
      <td class="all" colspan="4" style="font-size:6.5pt;padding:3pt;">
        <div>Pursuant to: (a) Indigenous People's Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277, as amended); and (c) Expanded Solo Parents Welfare Act (RA 11861):</div>
        <table style="width:100%;border-collapse:collapse;margin-top:3pt;">
          <tr>
            <td style="width:3%;font-size:7pt;">a.</td>
            <td style="width:35%;font-size:6.5pt;">Are you a member of any indigenous group?</td>
            <td style="width:8%;text-align:center;"><span class="yn">${cb(q4.q40a)}</span><br><span style="font-size:5pt;">YES</span></td>
            <td style="width:8%;text-align:center;"><span class="yn">${cb(!q4.q40a)}</span><br><span style="font-size:5pt;">NO</span></td>
            <td style="width:46%;font-size:6pt;">If YES, please specify:<br><strong>${esc(q4.q40a_detail)}&nbsp;</strong></td>
          </tr>
          <tr>
            <td style="font-size:7pt;">b.</td>
            <td style="font-size:6.5pt;">Are you a person with disability?</td>
            <td style="text-align:center;"><span class="yn">${cb(q4.q40b)}</span><br><span style="font-size:5pt;">YES</span></td>
            <td style="text-align:center;"><span class="yn">${cb(!q4.q40b)}</span><br><span style="font-size:5pt;">NO</span></td>
            <td style="font-size:6pt;">If YES, please specify ID No.:<br><strong>${esc(q4.q40b_detail)}&nbsp;</strong></td>
          </tr>
          <tr>
            <td style="font-size:7pt;">c.</td>
            <td style="font-size:6.5pt;">Are you a solo parent?</td>
            <td style="text-align:center;"><span class="yn">${cb(q4.q40c)}</span><br><span style="font-size:5pt;">YES</span></td>
            <td style="text-align:center;"><span class="yn">${cb(!q4.q40c)}</span><br><span style="font-size:5pt;">NO</span></td>
            <td style="font-size:6pt;">If YES, please specify ID No.:<br><strong>${esc(q4.q40c_detail)}&nbsp;</strong></td>
          </tr>
        </table>
      </td>
    </tr>
  </tbody>
</table>

<!-- ── REFERENCES (41) ── -->
<table style="width:100%;margin-top:2pt;">
  <tr>
    <td class="all" colspan="99" style="font-size:6.5pt;font-weight:700;padding:2pt 4pt;">
      41. REFERENCES (Person not related by consanguinity or affinity to applicant/appointee)
    </td>
  </tr>
  <tr>
    <th class="hdr" style="width:28%;">NAME</th>
    <th class="hdr" style="width:44%;">OFFICE/RESIDENTIAL ADDRESS</th>
    <th class="hdr" style="width:28%;">CONTACT NO. AND/OR EMAIL</th>
  </tr>
  ${charRefRows}
</table>

<!-- ── DECLARATION (42) ── -->
<table style="width:100%;margin-top:2pt;">
  <tr>
    <td class="all" colspan="99" style="padding:3pt 4pt;">
      <span style="font-size:7pt;font-weight:700;">42.</span>
      <span style="font-size:6.5pt;"> I declare under oath that I have personally accomplished this Personal Data Sheet which is a true, correct and complete statement pursuant to the provisions of pertinent laws, rules and regulations of the Republic of the Philippines. I authorize the agency head/authorized representative to verify/validate the contents stated herein. I agree that any misrepresentation made in this document and its attachments shall cause the filing of administrative/criminal case/s against me.</span>
    </td>
  </tr>
</table>

<!-- ── GOV'T ID + SIGNATURE + PHOTO + THUMBMARK ── -->
<table style="width:100%;margin-top:2pt;">
  <tr>
    <!-- Left: Gov ID + Signature -->
    <td class="all" style="width:50%;vertical-align:top;padding:3pt 4pt;">
      <div style="font-size:6pt;">Government Issued ID (i.e. Passport, GSIS, SSS, PRC, Driver's License, etc.)</div>
      <div style="font-size:6pt;margin-top:2pt;">Government Issued ID: 
        <span style="font-weight:700;border-bottom:0.5pt solid #000;display:inline-block;min-width:120pt;">${esc(pi.gov_id_type)}&nbsp;</span>
      </div>
      <div style="font-size:6pt;margin-top:2pt;">ID/License/Passport No.:
        <span style="font-weight:700;border-bottom:0.5pt solid #000;display:inline-block;min-width:110pt;">${esc(pi.gov_id_no)}&nbsp;</span>
      </div>
      <div style="font-size:6pt;margin-top:2pt;">Date/Place of Issuance:
        <span style="font-weight:700;border-bottom:0.5pt solid #000;display:inline-block;min-width:110pt;">${esc(pi.gov_id_issuance)}&nbsp;</span>
      </div>
      <div class="sig" style="margin-top:12pt;"></div>
      <div style="font-size:6pt;text-align:center;">SIGNATURE (wet signature/e-signature/digital certificate)</div>
      <div style="font-size:6pt;margin-top:3pt;">Date Accomplished:
        <span style="font-weight:700;border-bottom:0.5pt solid #000;display:inline-block;min-width:100pt;">${pi.date_accomplished ? fmtD(pi.date_accomplished) : ''}&nbsp;</span>
      </div>
    </td>
    <!-- Right: Photo + Sign + Thumbmark -->
    <td class="all" style="width:50%;vertical-align:top;padding:3pt 4pt;">
      <table style="width:100%;border-collapse:collapse;">
        <tr>
          <td style="width:50%;border:0.5pt solid #000;height:55pt;text-align:center;vertical-align:middle;font-size:6.5pt;color:#666;">
            PHOTO<br>(passport size)
          </td>
          <td style="width:50%;padding-left:4pt;vertical-align:top;">
            <div style="font-size:6pt;margin-bottom:2pt;">Signature (Sign inside the box)</div>
            <div style="border:0.5pt solid #000;height:28pt;"></div>
            <div style="font-size:6pt;margin-top:3pt;font-weight:700;text-align:center;">DATE ACCOMPLISHED</div>
            <div style="border-bottom:0.5pt solid #000;height:14pt;text-align:center;font-weight:700;font-size:7pt;">${pi.date_accomplished ? fmtD(pi.date_accomplished) : ''}&nbsp;</div>
            <div style="font-size:6pt;margin-top:3pt;font-weight:700;text-align:center;">Right Thumbmark</div>
            <div style="border:0.5pt solid #000;height:26pt;"></div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<!-- ── OATH ── -->
<table style="width:100%;margin-top:2pt;">
  <tr>
    <td class="all" style="padding:3pt 4pt;font-size:6.5pt;line-height:1.6;">
      SUBSCRIBED AND SWORN to before me this __________ day of ________________________, affiant exhibiting his/her validly issued government ID as indicated above.
      <div style="margin-top:12pt;border-top:0.5pt solid #000;width:40%;padding-top:2pt;font-size:6pt;text-align:center;">(wet signature/e-signature/digital certificate except for notary public)</div>
      <div style="font-size:6pt;text-align:left;margin-top:1pt;font-weight:700;">Person Administering Oath</div>
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