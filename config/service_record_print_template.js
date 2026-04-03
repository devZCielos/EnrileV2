(function () {
  function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function shortBranch(v) {
    const src = String(v || '').trim();
    if (!src) return '';
    const norm = src.toLowerCase().replace(/[^a-z]/g, '');
    if (norm === 'localgovernmentunit') return 'LGU';
    return src;
  }

  function fmtPrint(d) {
    if (!d) return '';
    try {
      const dt = new Date(d + 'T00:00:00');
      return dt.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
      return d;
    }
  }

  function cellValue(value) {
    return value ? value : '&nbsp;';
  }

  function buildSRPageHTML(emp, records, pageNum, totalPages, sliceStart, sliceEnd) {
    const surname = emp.last_name || '';
    const firstName = emp.first_name || '';
    const mi = emp.middle_name ? emp.middle_name[0] + '.' : '';
    const dob = emp.date_of_birth ? fmtPrint(emp.date_of_birth) : '';
    const gsisBp = emp.gsis_number || '';
    const tin = emp.tin_number || '';
    const pageRecs = records.slice(sliceStart, sliceEnd);

    const rows = pageRecs
      .map(
        (sr) => `
    <tr>
      <td>${cellValue(sr.date_from ? fmtPrint(sr.date_from) : '')}</td>
      <td>${cellValue(sr.date_to ? fmtPrint(sr.date_to) : '')}</td>
      <td>${cellValue(esc(sr.designation) || '')}</td>
      <td style="text-align:center;">${cellValue(esc(sr.status) || '')}</td>
      <td style="text-align:right;">${cellValue(
        sr.salary ? parseFloat(sr.salary).toLocaleString('en-PH', { minimumFractionDigits: 2 }) : ''
      )}</td>
      <td>${cellValue(esc(shortBranch(sr.branch)) || '')}</td>
      <td>${cellValue(esc(sr.station) || '')}</td>
      <td style="text-align:center;">${cellValue(esc(sr.lvbs_wo_pay) || '')}</td>
      <td style="text-align:center;">${cellValue(sr.separation_date ? fmtPrint(sr.separation_date) : '')}</td>
      <td>${cellValue(esc(sr.separation_cause) || '')}</td>
    </tr>`
      )
      .join('');

    const minRows = 26;
    let blankRows = '';
    for (let i = pageRecs.length; i < minRows; i++) {
      blankRows += '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>';
    }

    return `
  <div class="sr-page">
    <div class="hdr-flex">
      <img src="../images/Enrile.png" alt="LGU Enrile" class="hdr-logo" onerror="this.style.display='none'"/>
      <div class="hdr-main">
        <div class="hdr-republic">Republic of the Philippines</div>
        <div class="hdr-province">Province of Cagayan</div>
        <div class="hdr-muni">Municipality of Enrile</div>
        <div class="hdr-brand">ONE ENRILE</div>
      </div>
      <img src="../images/Peanut.png" alt="Peanut Capital" class="hdr-logo" onerror="this.style.display='none'"/>
    </div>
    <div class="hdr-line-wrap">
      <img src="../images/line.png" alt="Header line" class="hdr-line" onerror="this.style.display='none'"/>
    </div>
    <div class="hdr-office">Office of the Municipal Mayor</div>

    <div class="meta-row">
      <div class="meta-item"><em>GSIS BP No: ${gsisBp}</em></div>
      <div class="meta-item"><em>Tax Identification No.: ${tin}</em></div>
    </div>

    <div class="sr-title">S E R V I C E &nbsp;&nbsp; R E C O R D</div>

    <table class="name-tbl">
      <tr>
        <td class="name-label"><strong>NAME:</strong></td>
        <td class="name-cell name-underline">${surname}</td>
        <td class="name-cell name-underline">${firstName}</td>
        <td class="name-cell name-underline mi-cell">${mi}</td>
        <td class="married-note">(If married, give maiden name)</td>
      </tr>
      <tr>
        <td></td>
        <td class="name-sub">(Surname)</td>
        <td class="name-sub">(First Name)</td>
        <td class="name-sub">(M.I)</td>
        <td></td>
      </tr>
    </table>

    <div class="dob-row">DATE OF BIRTH: <span class="dob-val">${dob}</span>
      <span class="dob-note">(Date herein should be checked from Birth or Baptismal Certificate or some reliable resources.)</span>
    </div>

    <div class="cert-text">THIS IS TO CERTIFY that the above-named employee actually rendered services in the Office as shown in the column below. Each line is supported by appointment papers actually issued by this office and approved by authorities concerned.</div>

    <table class="sr-tbl">
      <thead>
        <tr>
          <th colspan="2" class="grp-hd">SERVICE</th>
          <th colspan="3" class="grp-hd">RECORD OF APPOINTMENT</th>
          <th colspan="2" class="grp-hd">OFFICE/ENTITY</th>
          <th class="grp-hd" rowspan="2">LVBS<br>W/O PAY</th>
          <th colspan="2" class="grp-hd">SEPARATION</th>
        </tr>
        <tr>
          <th class="col-hd">From</th>
          <th class="col-hd">To</th>
          <th class="col-hd">Designation</th>
          <th class="col-hd">Status</th>
          <th class="col-hd">Salary per Annum</th>
          <th class="col-hd">Branch</th>
          <th class="col-hd">Station/Place</th>
          <th class="col-hd">Date</th>
          <th class="col-hd">Cause</th>
        </tr>
      </thead>
      <tbody>
        ${rows}${blankRows}
      </tbody>
    </table>

    <div class="footer-cert">
      Issued in compliance with Executive Order No. 54 dated August 10, 1954 and in accordance with Circular No. 58,
      dated August 10, 1954 of the system.
    </div>
    <table class="sig-tbl">
      <tr>
        <td class="sig-left">
          <div class="sig-prep">Prepared by:</div>
          <div class="sig-name">EMIROSE P. MADRIAGA</div>
          <div class="sig-pos">HRMO III</div>
        </td>
        <td class="sig-right">
          <div class="sig-prep">Conforme:</div>
          <div class="sig-name">MIGUEL B. DECENA, JR.</div>
          <div class="sig-pos">Municipal Mayor</div>
        </td>
      </tr>
    </table>

    <div class="btm-bar">
      <img src="../images/Bagong_Pilipinas.png" alt="Bagong Pilipinas" class="btm-logo" onerror="this.style.display='none'"/>
      <div class="btm-motto">&ldquo;OHANA: Awan Na Matalan, Awan Nga Kalippanan&rdquo;</div>
      <img src="../images/Enrile@175.png" alt="Enrile @175" class="btm-logo btm-logo-right" onerror="this.style.display='none'"/>
    </div>
    <div class="btm-line-wrap">
      <img src="../images/line.png" alt="" class="btm-line" onerror="this.style.display='none'"/>
    </div>
    <div class="page-num">Page ${pageNum} of ${totalPages}</div>
  </div>`;
  }

  function print(emp, records) {
    const ROWS_PER_PAGE = 26;
    const totalPages = Math.max(1, Math.ceil((records || []).length / ROWS_PER_PAGE));

    let pages = '';
    for (let p = 0; p < totalPages; p++) {
      pages += buildSRPageHTML(emp || {}, records || [], p + 1, totalPages, p * ROWS_PER_PAGE, (p + 1) * ROWS_PER_PAGE);
    }

    const css = `
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Century Gothic','Calibri',sans-serif; font-size:10.5pt; color:#000; background:#fff; margin:0; }
    @page { size:legal portrait; margin:2mm; }
    @media print {
      html, body { width:100%; height:100%; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      .sr-page { page-break-after:always; width:100%; }
      .sr-page:last-child { page-break-after:avoid; }
    }

    .hdr-flex { display:flex; align-items:center; justify-content:center; gap:10pt; margin-bottom:2pt; }
    .hdr-logo { width:82pt; height:82pt; object-fit:contain; flex-shrink:0; }
    .hdr-main  { text-align:center; flex-shrink:0; }
    .hdr-republic { font-family:Tahoma,sans-serif; font-weight:700; font-size:10pt; }
    .hdr-province { font-family:Tahoma,sans-serif; font-weight:700; font-size:9.5pt; }
    .hdr-muni     { font-family:Tahoma,sans-serif; font-weight:700; font-size:10pt; }
    .hdr-brand    { font-family:Arial,sans-serif; font-weight:900; font-size:24pt; letter-spacing:3pt; color:#cc0000; margin:3pt 0; }
    .hdr-office   { font-family:'Old English Text MT','UnifrakturMaguntia','Blackletter','MedievalSharp','Lucida Calligraphy',cursive; font-size:17pt; text-align:center; display:block; margin:3pt 0 2pt; }
    .hdr-line-wrap { width:100%; margin:2pt 0 4pt; line-height:0; }
    .hdr-line { width:100%; height:auto; display:block; }

    .meta-row { display:flex; flex-direction:column; align-items:flex-start; gap:1pt; margin:4pt 0; font-family:'Comic Sans MS',cursive; font-size:9pt; font-style:italic; }

    .sr-title { font-family:'Bookman Old Style','Georgia',serif; font-weight:700; font-size:18pt;
                text-align:center; margin:6pt 0 5pt; letter-spacing:2pt; }

    .name-tbl  { width:100%; border-collapse:collapse; margin-bottom:4pt; }
    .name-label { font-family:'Century Gothic',sans-serif; font-weight:700; font-size:12pt; width:62pt; padding-right:4pt; white-space:nowrap; }
    .name-cell  { font-family:'Century Gothic',sans-serif; font-size:12pt; padding:0 6pt; text-align:center; }
    .name-underline { border-bottom:1pt solid #000; min-width:80pt; }
    .mi-cell { max-width:40pt; }
    .name-sub   { font-family:Calibri,sans-serif; font-size:10.5pt; text-align:center; color:#333; padding-top:1pt; }
    .married-note { font-family:'Century Gothic',sans-serif; font-size:10.5pt; padding-left:6pt; color:#333; white-space:nowrap; }

    .dob-row  { font-family:'Century Gothic',sans-serif; font-size:11pt; margin-bottom:10pt; }
    .dob-val  { border-bottom:1pt solid #000; min-width:80pt; display:inline-block; padding:0 8pt; }
    .dob-note { font-size:9.5pt; color:#444; }

    .cert-text { font-family:'Century Gothic',sans-serif; font-weight:700; font-size:10.5pt;
                 text-align:justify; margin-bottom:5pt; line-height:1.4; }

    .sr-tbl { width:100%; border-collapse:collapse; table-layout:fixed; empty-cells:show; font-size:9pt; }
    .grp-hd { background:#d0d0d0; border:1pt solid #000; padding:4pt 4pt;
          text-align:center; font-family:'Century Gothic',sans-serif; font-weight:700; font-size:9.5pt; }
    .col-hd { background:#e8e8e8; border:1pt solid #000; padding:4pt 4pt;
          text-align:center; font-family:'Century Gothic',sans-serif; font-weight:700; font-size:9pt; }
    .sr-tbl td { border:1pt solid #000; padding:4pt 4pt; font-family:'Century Gothic',sans-serif;
           font-size:9pt; vertical-align:middle; height:24pt; }
    .sr-tbl tbody tr { height:24pt; }

    .footer-cert { font-family:Calibri,sans-serif; font-weight:700; font-size:9.5pt; margin:8pt 0 6pt; line-height:1.4; }
    .sig-tbl { width:100%; border-collapse:collapse; margin-top:4pt; }
    .sig-left  { width:55%; vertical-align:top; padding-right:10pt; }
    .sig-right { vertical-align:top; padding-left:10pt; }
    .sig-prep  { font-family:'Century Gothic',sans-serif; font-size:11pt; margin-bottom:36pt; }
    .sig-name  { font-family:'Century Gothic',sans-serif; font-weight:700; font-size:12pt; border-top:1pt solid #000; padding-top:3pt; }
    .sig-pos   { font-family:'Century Gothic',sans-serif; font-size:12pt; }
    .page-num  { font-family:'Century Gothic',sans-serif; font-size:11pt; text-align:right; margin-top:4pt; }
    .sr-page   { margin-bottom:0; }

    .btm-line-wrap { width:100%; margin:6pt 0 0; line-height:0; }
    .btm-line { width:100%; height:auto; display:block; }
    .btm-bar { display:flex; align-items:center; justify-content:space-between; padding:4pt 0 2pt; }
    .btm-logo { height:42pt; width:auto; object-fit:contain; flex-shrink:0; }
    .btm-logo-right { height:36pt; }
    .btm-motto { flex:1; text-align:center; font-family:'Century Gothic',sans-serif; font-weight:700; font-size:14pt; font-style:italic; padding:0 10pt; color:#166534; white-space:nowrap; }
    `;

    const w = window.open('', '_blank', 'width=900,height=1100');
    w.document.write(`<!DOCTYPE html><html><head>
      <title>Service Record — ${emp.last_name || ''}, ${emp.first_name || ''}</title>
      <style>${css}</style>
    </head><body>${pages}</body></html>`);
    w.document.close();
    w.focus();
    setTimeout(function () {
      w.print();
    }, 600);
  }

  window.SRPrintTemplate = { print: print };
})();
