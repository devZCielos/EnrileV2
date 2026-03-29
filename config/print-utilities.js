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
function buildPDSPrintHTML(d, empRow) {
  const pi = safeJson(d.personal_info) || {};
  const fb = safeJson(d.family_background) || {};
  const ed = safeJson(d.education) || [];
  const cs = safeJson(d.civil_service) || [];
  const we = safeJson(d.work_experience) || [];
  const vw = safeJson(d.voluntary_work) || [];
  const tr = safeJson(d.trainings) || [];
  const oi = safeJson(d.other_info) || {};
  const q4 = safeJson(d.q4_questions) || {};
  const refs = safeJson(d.pds_references || d['references']) || [];

  const v = formatValue;
  const fmtD = formatDate;
  const cb = checkboxSymbol;
  const pad = padArray;

  const resAddr = [pi.res_housenum, pi.res_street, pi.res_subdivision, pi.res_barangay, pi.res_city, pi.res_province].filter(Boolean).join(', ');
  const permAddr = pi.perm_same ? resAddr : [pi.perm_housenum, pi.perm_street, pi.perm_subdivision, pi.perm_barangay, pi.perm_city, pi.perm_province].filter(Boolean).join(', ');

  const edPad = pad(ed, 5);
  const csPad = pad(cs, 4);
  const wePad = pad(we, 9);
  const vwPad = pad(vw, 5);
  const trPad = pad(tr, 5);
  const refPad = pad(refs, 3);

  const css = `
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Arial Narrow',Arial,sans-serif;font-size:6.5pt;color:#000;background:#fff;}
    @page{size:A4 portrait;margin:5mm 6mm;}
    @media print{body{margin:0;}}
    table{border-collapse:collapse;width:100%;}
    td,th{border:0.5pt solid #000;padding:1.5pt 2.5pt;vertical-align:top;font-size:6.5pt;}
    .lbl{font-size:5pt;font-weight:700;text-transform:uppercase;color:#444;display:block;line-height:1.1;margin-bottom:0.5pt;}
    .val{font-size:7pt;font-weight:700;min-height:9pt;display:block;padding:0.5pt 0;word-break:break-word;}
    .sec{background:#000;color:#fff;font-size:6pt;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:2pt 4pt;}
    .hdr{background:#e0e0e0;font-size:6pt;font-weight:700;text-align:center;padding:1.5pt 2pt;}
    .page{padding:0;page-break-after:always;}
    .page:last-child{page-break-after:auto;}
    .sig-box{height:20pt;border-top:0.5pt solid #000;margin-top:2pt;}
    .pg-num{font-size:5.5pt;font-style:italic;text-align:right;margin-top:2pt;}
    .yn{display:inline-block;border:0.5pt solid #000;width:7pt;height:7pt;text-align:center;font-size:6pt;line-height:7pt;margin-right:1pt;}
  `;

  const pageHdr = `
  <table style="margin-bottom:1pt;border-collapse:collapse;width:100%;">
    <tr>
      <td style="border:0.5pt solid #000;padding:2pt 5pt;width:18%;vertical-align:top;">
        <div style="font-size:6.5pt;font-weight:700;font-style:italic;">CS Form No. 212</div>
        <div style="font-size:6pt;font-style:italic;">Revised 2025</div>
      </td>
      <td style="border:0.5pt solid #000;border-left:none;padding:3pt 5pt;text-align:center;vertical-align:middle;">
        <div style="font-size:14pt;font-weight:900;text-transform:uppercase;letter-spacing:.04em;font-family:Arial,sans-serif;">Personal Data Sheet</div>
      </td>
    </tr>
  </table>
  <table style="margin-bottom:1pt;border-collapse:collapse;width:100%;">
    <tr>
      <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:5.5pt;line-height:1.5;" colspan="12">
        <strong><em>WARNING: Any misrepresentation made in the Personal Data Sheet and the Work Experience Sheet shall cause the filing of administrative/criminal case/s against the person concerned.</em></strong><br>
        <strong><em>READ THE ATTACHED GUIDE TO FILLING OUT THE PERSONAL DATA SHEET (PDS) BEFORE ACCOMPLISHING THE PDS FORM.</em></strong><br>
        Print legibly if accomplished through own handwriting. Tick appropriate boxes <span style="border:0.5pt solid #000;display:inline-block;width:7pt;height:7pt;vertical-align:middle;font-size:6pt;text-align:center;">✓</span> and use separate sheet if necessary. Indicate N/A if not applicable. &nbsp;<strong>DO NOT ABBREVIATE.</strong>
      </td>
    </tr>
  </table>`;

  // PAGE 1: Personal Information
  const page1 = `<div class="page">
  ${pageHdr}
  <table><tr><td colspan="12" class="sec">I. Personal Information</td></tr></table>
  <table>
    <tr>
      <td style="width:4%;"><span class="lbl">1.</span></td>
      <td style="width:28%;"><span class="lbl">Surname</span><span class="val">${v(pi.last_name)}</span></td>
      <td style="width:4%;"><span class="lbl">2.</span></td>
      <td style="width:28%;"><span class="lbl">First Name</span><span class="val">${v(pi.first_name)}</span></td>
      <td style="width:18%;"><span class="lbl">Name Extension (Jr., Sr.)</span><span class="val">${v(pi.suffix)}</span></td>
      <td rowspan="6" style="width:18%;padding:2pt;">
        <span class="lbl" style="margin-bottom:2pt;display:block;">16. Citizenship</span>
        <div style="margin:1pt 0;"><span class="yn">${pi.citizenship === 'Filipino' || !pi.citizenship ? '✓' : ''}</span><span style="font-size:6pt;"> Filipino</span></div>
        <div style="margin:1pt 0;"><span class="yn">${pi.citizenship === 'Dual Citizenship' ? '✓' : ''}</span><span style="font-size:6pt;"> Dual Citizenship</span></div>
      </td>
    </tr>
    <tr>
      <td></td>
      <td><span class="lbl">Middle Name</span><span class="val">${v(pi.middle_name)}</span></td>
      <td></td><td colspan="2"></td>
    </tr>
    <tr>
      <td><span class="lbl">3.</span></td>
      <td><span class="lbl">Date of Birth (mm/dd/yyyy)</span><span class="val">${fmtD(pi.dob)}</span></td>
      <td><span class="lbl">5.</span></td>
      <td colspan="2">
        <span class="lbl">Sex at Birth</span>
        <span style="font-size:6pt;"><span class="yn">${pi.sex === 'Male' ? '✓' : ''}</span> Male &nbsp; <span class="yn">${pi.sex === 'Female' ? '✓' : ''}</span> Female</span>
      </td>
    </tr>
    <tr>
      <td><span class="lbl">4.</span></td>
      <td><span class="lbl">Place of Birth</span><span class="val">${v(pi.pob)}</span></td>
      <td><span class="lbl">6.</span></td>
      <td colspan="2">
        <span class="lbl">Civil Status</span>
        <div style="font-size:5.5pt;line-height:1.7;">
          <span class="yn">${pi.civil_status === 'Single' ? '✓' : ''}</span> Single &nbsp;
          <span class="yn">${pi.civil_status === 'Married' ? '✓' : ''}</span> Married &nbsp;
          <span class="yn">${pi.civil_status === 'Widow/er' ? '✓' : ''}</span> Widow/er
        </div>
      </td>
    </tr>
    <tr>
      <td><span class="lbl">7.</span></td>
      <td><span class="lbl">Height (m)</span><span class="val">${v(pi.height)}</span></td>
      <td><span class="lbl">10.</span></td>
      <td colspan="2"><span class="lbl">UMID ID No.</span><span class="val">${v(pi.umid)}</span></td>
    </tr>
    <tr>
      <td><span class="lbl">8.</span></td>
      <td><span class="lbl">Weight (kg)</span><span class="val">${v(pi.weight)}</span></td>
      <td><span class="lbl">11.</span></td>
      <td colspan="2"><span class="lbl">PAG-IBIG ID No.</span><span class="val">${v(pi.pagibig)}</span></td>
    </tr>
  </table>

  <!-- Blood Type, IDs, Address -->
  <table>
    <tr>
      <td style="width:4%;"><span class="lbl">9.</span></td>
      <td style="width:22%;"><span class="lbl">Blood Type</span><span class="val">${v(pi.blood_type)}</span></td>
      <td style="width:4%;"><span class="lbl">12.</span></td>
      <td style="width:22%;"><span class="lbl">PhilHealth No.</span><span class="val">${v(pi.philhealth)}</span></td>
      <td style="width:48%;" rowspan="3">
        <span class="lbl">17. Residential Address</span>
        <table style="border:none;"><tr>
          <td style="border:none;padding:0 2pt 0 0;"><span class="lbl">House/Block/Lot No.</span><span class="val" style="font-size:6pt;">${v(pi.res_housenum)}</span></td>
          <td style="border:none;padding:0;"><span class="lbl">Street</span><span class="val" style="font-size:6pt;">${v(pi.res_street)}</span></td>
        </tr></table>
      </td>
    </tr>
    <tr>
      <td><span class="lbl">13.</span></td>
      <td><span class="lbl">PhilSys No. (PSN)</span><span class="val">${v(pi.psn)}</span></td>
      <td><span class="lbl">19.</span></td>
      <td><span class="lbl">Telephone No.</span><span class="val">${v(pi.tel)}</span></td>
    </tr>
    <tr>
      <td><span class="lbl">14.</span></td>
      <td><span class="lbl">TIN No.</span><span class="val">${v(pi.tin)}</span></td>
      <td><span class="lbl">20.</span></td>
      <td><span class="lbl">Mobile No.</span><span class="val">${v(pi.mobile)}</span></td>
    </tr>
  </table>

  <div class="pg-num">CS FORM 212 (Revised 2025), Page 1 of 4</div>
  </div>`;

  // PAGE 2: Family Background & Education
  const page2 = `<div class="page">
  ${pageHdr}
  <table><tr><td colspan="12" class="sec">II. Family Background</td></tr></table>
  <table>
    <tr>
      <td style="width:4%;font-size:6pt;font-weight:700;">22.</td>
      <td style="width:46%;"><span class="lbl">Spouse's Surname</span><span class="val">${v(fb.sp_surname)}</span></td>
      <td style="width:4%;font-size:6pt;font-weight:700;">23.</td>
      <td style="width:46%;"><span class="lbl">Father's Surname</span><span class="val">${v(fb.fa_surname)}</span></td>
    </tr>
  </table>

  <table><tr><td colspan="12" class="sec">III. Educational Background</td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:3%;">26.</th>
        <th class="hdr" style="width:20%;">LEVEL</th>
        <th class="hdr" style="width:30%;">SCHOOL/UNIVERSITY</th>
        <th class="hdr" style="width:25%;">DEGREE/COURSE</th>
        <th class="hdr" style="width:22%;">YEAR GRADUATED</th>
      </tr>
    </thead>
    <tbody>
      ${['Elementary', 'Secondary', 'Vocational', 'College', 'Graduate'].map((lvl, i) => `<tr>
        <td></td>
        <td style="font-size:6pt;font-weight:700;">${lvl}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v((edPad[i] || {}).school)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v((edPad[i] || {}).degree)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v((edPad[i] || {}).year_grad)}</td>
      </tr>`).join('')}
    </tbody>
  </table>

  <div class="pg-num">CS FORM 212 (Revised 2025), Page 2 of 4</div>
  </div>`;

  // PAGE 3: Work Experience
  const page3 = `<div class="page">
  ${pageHdr}
  <table><tr><td colspan="9" class="sec">IV. Work Experience</td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:3%;">28.</th>
        <th class="hdr" style="width:12%;">FROM</th>
        <th class="hdr" style="width:12%;">TO</th>
        <th class="hdr" style="width:25%;">POSITION</th>
        <th class="hdr" style="width:25%;">DEPARTMENT/AGENCY</th>
        <th class="hdr" style="width:12%;">SALARY GRADE</th>
        <th class="hdr" style="width:11%;">STATUS</th>
      </tr>
    </thead>
    <tbody>
      ${wePad.slice(0, 6).map(e => `<tr>
        <td></td>
        <td style="font-size:6pt;text-align:center;">${fmtD(e.date_from)}</td>
        <td style="font-size:6pt;text-align:center;">${fmtD(e.date_to)}</td>
        <td style="font-size:6pt;">${v(e.position)}</td>
        <td style="font-size:6pt;">${v(e.dept_agency)}</td>
        <td style="font-size:6pt;text-align:center;">${v(e.salary_grade)}</td>
        <td style="font-size:6pt;">${v(e.status)}</td>
      </tr>`).join('')}
    </tbody>
  </table>

  <div class="pg-num">CS FORM 212 (Revised 2025), Page 3 of 4</div>
  </div>`;

  // PAGE 4: References & Questions
  const page4 = `<div class="page">
  ${pageHdr}
  <table><tr><td colspan="4" class="sec">Questions & References</td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:3%;">#</th>
        <th class="hdr">QUESTION</th>
        <th class="hdr" style="width:9%;text-align:center;">YES</th>
        <th class="hdr" style="width:9%;text-align:center;">NO</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>34</td>
        <td style="font-size:6pt;">Are you related by consanguinity or affinity to the appointing authority within the third degree?</td>
        <td style="text-align:center;"><span class="yn">${cb(q4.q34)}</span></td>
        <td style="text-align:center;"><span class="yn">${cb(!q4.q34)}</span></td>
      </tr>
    </tbody>
  </table>

  <table style="margin-top:1pt;"><tr><td colspan="4" class="sec">41. References</td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr">NAME</th>
        <th class="hdr">ADDRESS</th>
        <th class="hdr">TELEPHONE</th>
      </tr>
    </thead>
    <tbody>
      ${refPad.slice(0, 3).map(r => `<tr>
        <td style="font-size:6.5pt;font-weight:600;">${v(r.name)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v(r.address)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v(r.tel)}</td>
      </tr>`).join('')}
    </tbody>
  </table>

  <div class="pg-num">CS FORM 212 (Revised 2025), Page 4 of 4</div>
  </div>`;

  return `<!DOCTYPE html><html><head>
    <meta charset="UTF-8"/>
    <title>PDS — CS Form 212 (Revised 2025)</title>
    <style>${css}</style>
  </head><body>
    ${page1}${page2}${page3}${page4}
  </body></html>`;
  return html;
}

/**
 * Build SALN Print HTML — Statement of Assets, Liabilities & Net Worth
 */
function buildSALNPrintHTML(d, emp, openPrint) {
  const sj = safeJson;
  const v = formatValue;
  const fmtD = formatDate;
  const fmtM = formatMoney;
  const cb = checkboxSymbol;
  const padR = (arr, min, fn) => {
    const n = Math.max(min, (arr || []).length);
    let h = '';
    for (let i = 0; i < n; i++) h += fn((arr || [])[i] || {});
    return h;
  };

  const dec = sj(d.declarant_info) || {};
  const sp = sj(d.spouse_info) || {};
  const ch = sj(d.children_info) || [];
  const rp = sj(d.real_properties || d.real_property) || [];
  const pp = sj(d.personal_properties || d.personal_property) || [];
  const lb = sj(d.liabilities) || [];
  const biz = sj(d.biz_interests || d.business_interests) || [];
  const rel = sj(d.relatives_govt || d.relatives_government) || [];

  const C = (txt, s) => `<td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;vertical-align:middle;${s || ''}">${v(txt)}&nbsp;</td>`;
  const H = (txt, s) => `<td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:5.5pt;font-weight:700;background:#e0e0e0;vertical-align:middle;${s || ''}">${txt}</td>`;
  const ul = (val, w) => `<span style="display:inline-block;min-width:${w || '70pt'};border-bottom:0.5pt solid #000;font-size:7pt;font-weight:700;padding-bottom:0.5pt;">${v(val)}&nbsp;</span>`;

  const childRows = padR(ch, 3, c => `<tr style="height:13pt;">
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;">${v(c.name)}&nbsp;</td>
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;text-align:center;">${v(c.age)}&nbsp;</td>
  </tr>`);

  const css = `
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Arial Narrow',Arial,sans-serif;font-size:7pt;color:#000;background:#fff;}
    table{border-collapse:collapse;}
    .lbl{font-size:5.5pt;font-weight:700;color:#333;display:block;}
    .val{font-size:7pt;font-weight:700;display:block;}
    @media print{body{margin:0;}}
  `;

  const html = `<!DOCTYPE html>
<html><head>
  <meta charset="UTF-8"/>
  <title>SALN ${v(d.saln_year)} — ${v(dec.lastname)}, ${v(dec.firstname)}</title>
  <style>${css}</style>
</head><body>

<div style="text-align:center;margin-bottom:3pt;">
  <div style="font-size:6.5pt;color:#555;margin-bottom:2pt;">SALN Form &nbsp;·&nbsp; Statement of Assets, Liabilities & Net Worth</div>
  <div style="font-size:9pt;font-weight:900;text-transform:uppercase;letter-spacing:.02em;">${v(dec.lastname)}, ${v(dec.firstname)}</div>
  <div style="font-size:7pt;color:#666;margin-top:1pt;">Filed for Period: ${v(d.saln_year)}</div>
</div>

<table style="width:100%;margin-bottom:2pt;">
  <tr>
    <td style="border:0.5pt solid #000;width:50%;padding:3pt 5pt;">
      <div class="lbl">Filing Type</div>
      <div class="val">${v(d.filing_type)}</div>
    </td>
    <td style="border:0.5pt solid #000;width:50%;padding:3pt 5pt;">
      <div class="lbl">Position</div>
      <div class="val">${v(dec.position)}</div>
    </td>
  </tr>
</table>

<table style="width:100%;margin-bottom:2pt;border:0.5pt solid #000;">
  <tr>
    <th colspan="2" style="border:0.5pt solid #000;padding:2pt 5pt;font-size:6.5pt;font-weight:700;text-align:left;background:#f0f0f0;">REAL PROPERTIES</th>
  </tr>
  <tr>
    ${H('Description','width:30%;')}
    ${H('Location','width:25%;')}
    ${H('Current Value','width:45%;')}
  </tr>
  ${padR(rp, 2, p => `<tr style="height:12pt;">
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;">${v(p.description)}&nbsp;</td>
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;">${v(p.location)}&nbsp;</td>
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;text-align:right;">${fmtM(p.value)}&nbsp;</td>
  </tr>`)}
</table>

<table style="width:100%;margin-bottom:2pt;border:0.5pt solid #000;">
  <tr>
    <th colspan="3" style="border:0.5pt solid #000;padding:2pt 5pt;font-size:6.5pt;font-weight:700;text-align:left;background:#f0f0f0;">LIABILITIES</th>
  </tr>
  <tr>
    ${H('Nature','width:35%;')}
    ${H('Creditor','width:40%;')}
    ${H('Amount','width:25%;text-align:center;')}
  </tr>
  ${padR(lb, 2, l => `<tr style="height:12pt;">
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;">${v(l.nature)}&nbsp;</td>
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;">${v(l.creditor)}&nbsp;</td>
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;text-align:right;">${fmtM(l.amount)}&nbsp;</td>
  </tr>`)}
</table>

<div style="margin-top:3pt;padding:10pt 14pt;font-size:6pt;color:#666;text-align:right;border-top:1px solid #ccc;">
  Printed on ${new Date().toLocaleDateString('en-PH')}
</div>

</body></html>`;

  if (openPrint) {
    const w = window.open('', '_blank', 'width=900,height=1100');
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 600);
  }
  return html;
}

/**
 * Print PDS from Data
 */
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
