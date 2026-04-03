<?php
// ============================================================
//  LGU ENRILE HRMS — PDS Print Page
//  File: Employee/pds_print.php
//
//  This file renders the official CS Form 212 (Revised 2025)
//  in the exact government format, pulling data from pds_records
//  (JSON-based storage) via PDO.
//
//  Access: Employee portal only (auth guard applied)
// ============================================================
require_once __DIR__ . '/_shared/auth.php';

// Load PDS record for this employee
$stmt = $pdo->prepare("SELECT * FROM pds_records WHERE employee_id = ? ORDER BY updated_at DESC LIMIT 1");
$stmt->execute([$employeeId]);
$pdsRow = $stmt->fetch();

// Load employee base info
$empStmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
$empStmt->execute([$employeeId]);
$empRow = $empStmt->fetch();

// Helper: safely decode JSON fields
function safeJson($str) {
    if (!$str) return [];
    $decoded = json_decode($str, true);
    return is_array($decoded) ? $decoded : [];
}

// Helper: get a value safely from an array
function val($arr, $key, $default = '') {
    $v = $arr[$key] ?? $default;
    return ($v === null || $v === 'null' || $v === 'undefined') ? $default : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Helper: format date to dd/mm/yyyy
function fmtDate($dateStr) {
    if (!$dateStr || $dateStr === '0000-00-00' || $dateStr === '') return '';
    try {
        $dt = new DateTime($dateStr);
        return $dt->format('d/m/Y');
    } catch (Exception $e) {
        return htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8');
    }
}

// Helper: checkbox checked attribute
function chk($val, $expected) {
    return (trim((string)$val) === trim((string)$expected)) ? 'checked' : '';
}

// Decode all JSON sections
$pi  = safeJson($pdsRow['personal_info']    ?? '');
$fb  = safeJson($pdsRow['family_background'] ?? '');
$ed  = safeJson($pdsRow['education']         ?? '');
$cs  = safeJson($pdsRow['civil_service']     ?? '');
$we  = safeJson($pdsRow['work_experience']   ?? '');
$vw  = safeJson($pdsRow['voluntary_work']    ?? '');
$tr  = safeJson($pdsRow['trainings']         ?? '');
$oi  = safeJson($pdsRow['other_info']        ?? '');
$q4  = safeJson($pdsRow['q4_questions']      ?? '');
$refs = safeJson($pdsRow['pds_references']   ?? '');

// Children: stored inside family_background as 'children' array
$children = $fb['children'] ?? [];

// Pad arrays to minimum rows (matching the working print blank rows)
function padArr($arr, $min) {
    while (count($arr) < $min) $arr[] = [];
    return $arr;
}
$edPad   = padArr($ed, 5);
$csPad   = padArr($cs, 7);
$wePad   = padArr($we, 28);
$vwPad   = padArr($vw, 7);
$trPad   = padArr($tr, 21);
$oiPad   = padArr($oi['rows'] ?? (is_array($oi) && isset($oi[0]) ? $oi : []), 7);
$refsPad = padArr($refs, 3);
$childPad = padArr($children, 12);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>PDS — CS Form 212 (Revised 2025)</title>
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <style>
<?php
// Embed the full stylesheet inline so the file is self-contained
// (no dependency on external CSS files from the old PDS system)
$stylesheetPath = __DIR__ . '/../config/pds_stylesheet.css';
if (file_exists($stylesheetPath)) {
    echo file_get_contents($stylesheetPath);
}
?>
  </style>
  <style>
a:link { text-decoration: none; }
@media print {
  @page {
    size: legal portrait;
    margin: 10mm;
  }
  body { zoom: 1.17; }
  .page {
    height: 1344px;
    overflow: hidden;
    page-break-after: always;
  }
  .page:last-child { page-break-after: auto; }
  .no-print { display: none !important; }
}
.no-print {
  text-align: center;
  padding: 12px;
  background: #f0f4f8;
  margin-bottom: 16px;
}
.no-print button {
  padding: 10px 28px;
  background: #0369a1;
  color: #fff;
  border: none;
  border-radius: 6px;
  font-size: 1rem;
  font-weight: 700;
  cursor: pointer;
  margin-right: 8px;
}
.no-print a {
  padding: 10px 20px;
  background: #64748b;
  color: #fff;
  border-radius: 6px;
  font-size: .9rem;
  font-weight: 600;
  text-decoration: none;
}
  </style>
</head>
<body class="hold-transition skin-blue sidebar-mini" onafterprint="history.back();">

<!-- Print button (hidden when printing) -->
<div class="no-print">
  <button onclick="window.print()">🖨️ Print PDS</button>
  <a href="Employee_portal.php">← Back to Portal</a>
</div>

<!-- ============================================================
     PAGE 1 — Personal Information
     ============================================================ -->
<div class="page">
<section class="content-wrapper" style="background-color:#FFFFFF">

<table border=0 cellpadding=0 cellspacing=0 width=1000 style='border-collapse:collapse;table-layout:fixed;width:1157pt;'>
  <col class=xl153 width=17><col class=xl153 width=95><col class=xl153 width=42>
  <col class=xl153 width=131><col class=xl153 width=44><col class=xl153 width=39>
  <col class=xl153 width=60><col class=xl153 width=70><col class=xl153 width=66>
  <col class=xl153 width=54 span=2><col class=xl153 width=80>
  <col class=xl153 width=62><col class=xl153 width=67>
  <col class=xl153 width=98><col class=xl153 width=0 span=2 style='display:none;'>
  <col class=xl153 width=98><col class=xl153 width=64 span=6>

  <tr height=15>
    <td height=15 class=xl236 style='height:11.25pt;font-size:14px'><i>CS Form No. 212<br/><font size="-1">Revised 2025</font></i></td>
    <td class=xl237></td><td class=xl237>&nbsp;</td><td class=xl237>&nbsp;</td>
    <td class=xl237>&nbsp;</td><td class=xl237>&nbsp;</td><td class=xl237>&nbsp;</td>
    <td class=xl237>&nbsp;</td><td class=xl237>&nbsp;</td><td class=xl237>&nbsp;</td>
    <td class=xl237>&nbsp;</td><td class=xl237>&nbsp;</td><td class=xl237>&nbsp;</td>
    <td class=xl238>&nbsp;</td>
  </tr>
  <tr height=55>
    <td colspan=14 height=55 class=xl449 style='border-right:1.0pt solid black;height:41.25pt'>PERSONAL DATA SHEET</td>
  </tr>
  <tr height=29>
    <td colspan=14 height=29 class=xl472 width=881 style='border-right:1.0pt solid black;height:21.75pt;width:663pt'> WARNING: Any misinterpretation made in the Personal Data Sheet and the Work Experience Sheet shall cause the filing of administrative/criminal case/s against the person concerned.</td>
  </tr>
  <tr height=15>
    <td colspan=14 height=15 class=xl364 style='border-right:1.0pt solid black;height:11.25pt'> READ THE ATTACHED GUIDE TO FILLING OUT THE PERSONAL DATA SHEET (PDS) BEFORE ACCOMPLISHING THE PDS FORM.</td>
  </tr>
  <tr height=0 style='display:none;'>
    <td class=xl273>&nbsp;</td><td class=xl288></td>
    <td class=xl274></td><td class=xl274></td><td class=xl274></td><td class=xl274></td>
    <td class=xl274></td><td class=xl274></td><td class=xl274></td><td class=xl274></td>
    <td class=xl274></td><td class=xl274></td><td class=xl274></td><td class=xl275>&nbsp;</td>
  </tr>
  <tr class=xl161 height=17>
    <td height=17 class=xl239 colspan=12 style='height:12.75pt;border-bottom:2px solid;'> Print legibly if accomplished through own handwriting. Tick appropriate boxes <span>(<input type="checkbox">)</span> and use separate sheet if necessary. Indicate N/A if not applicable.<font class="font36"> DO NOT ABBREVIATE.</font></td>
    <td height=17 class=xl159 width=881 style='height:12.75pt;width:98pt;border-bottom:2px solid;'></td>
    <td class=xl159 style="border-right:1px solid;border-bottom:2px solid;">&nbsp;</td>
  </tr>
  <tr height=3>
    <td height=3 class=xl240 style='height:2.25pt'>&nbsp;</td>
    <td class=xl153></td><td class=xl153></td><td class=xl153></td><td class=xl153></td>
    <td class=xl153></td><td class=xl153></td><td class=xl153></td><td class=xl153></td>
    <td class=xl153></td><td class=xl153></td><td class=xl153></td><td class=xl153></td>
    <td class=xl231>&nbsp;</td>
  </tr>

  <!-- I. PERSONAL INFORMATION -->
  <tr height=22>
    <td colspan=14 height=22 class=xl452 width=881 style='border-right:1.0pt solid black;height:16.5pt;width:663pt'> I. PERSONAL INFORMATION</td>
  </tr>
  <!-- Surname -->
  <tr height=30>
    <td height=30 class=xl241 style='height:22.5pt'>2.</td>
    <td colspan=2 class=xl215 style='border-right:.5pt solid black'> SURNAME</td>
    <td colspan=11 class=xl428 style='border-right:1.0pt solid black;border-left:none;padding-left:5px;letter-spacing:5px'><?= strtoupper(val($pi,'last_name')) ?></td>
  </tr>
  <!-- First Name -->
  <tr height=30>
    <td height=30 class=xl241 style='height:22.5pt'>&nbsp;</td>
    <td colspan=2 class=xl230 style='border-right:.5pt solid black'> FIRST NAME</td>
    <td class=xl281 style='border-top:none;border-left:none;padding-left:5px;letter-spacing:5px'><?= strtoupper(val($pi,'first_name')) ?></td>
    <td class=xl282 style='border-top:none'>&nbsp;</td>
    <td class=xl282 style='border-top:none'>&nbsp;</td>
    <td class=xl282 style='border-top:none'>&nbsp;</td>
    <td class=xl282 style='border-top:none'>&nbsp;</td>
    <td class=xl282 style='border-top:none'>&nbsp;</td>
    <td class=xl282 style='border-top:none'>&nbsp;</td>
    <td class=xl282 style='border-top:none'>&nbsp;</td>
    <td colspan=3 class=xl397 style='border-right:1.0pt solid black'> NAME EXTENSION (JR., SR)<b><?= val($pi,'suffix') ?></b></td>
  </tr>
  <!-- Middle Name -->
  <tr height=29>
    <td height=29 class=xl242 style='height:21.75pt'>&nbsp;</td>
    <td colspan=2 class=xl289 style='border-right:.5pt solid black'> MIDDLE NAME</td>
    <td class=xl278 style='border-top:none;border-left:none;padding-left:5px;letter-spacing:5px'><?= strtoupper(val($pi,'middle_name')) ?></td>
    <td class=xl279 style='border-top:none'>&nbsp;</td><td class=xl279 style='border-top:none'>&nbsp;</td>
    <td class=xl279 style='border-top:none'>&nbsp;</td><td class=xl279 style='border-top:none'>&nbsp;</td>
    <td class=xl279 style='border-top:none'>&nbsp;</td><td class=xl279 style='border-top:none'>&nbsp;</td>
    <td class=xl279 style='border-top:none'>&nbsp;</td>
    <td class=xl283>&nbsp;</td><td class=xl283>&nbsp;</td><td class=xl284>&nbsp;</td>
  </tr>
  <!-- Date of Birth + Citizenship -->
  <tr height=32>
    <td height=32 class=xl318 style='height:24.0pt;border-top:none;'>3.</td>
    <td colspan=2 class=xl427 width=881 style='border-right:.5pt solid black;width:103pt'> DATE OF BIRTH<br>(dd/mm/yyyy)</td>
    <td colspan=3 class=xl400 width=881 style='border-right:1.0pt solid black;border-left:none;width:160pt;padding-right:150px;padding-left:5px'><?= fmtDate(val($pi,'dob')) ?></td>
    <td class=xl169 colspan=2>16. CITIZENSHIP</td>
    <td class=xl215 style='border-top:none'>&nbsp;</td>
    <td colspan=5 height=32 width=881 style='border-right:1.0pt solid black;height:24.0pt;width:239pt' align=left valign=top>
      <table cellpadding=0 cellspacing=0>
        <tr>
          <td colspan=5 height=32 class=xl407 width=317 style='height:24.0pt;width:239pt;'>
            <input type="checkbox" <?= chk(val($pi,'citizenship_type'),'Filipino') ?>> Filipino
            <input type="checkbox" <?= chk(val($pi,'citizenship_type'),'Dual Citizenship') ?>> Dual Citizenship<br/>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <input type="checkbox" <?= chk(val($pi,'dual_by'),'Birth') ?>> by birth
            <input type="checkbox" <?= chk(val($pi,'dual_by'),'Naturalization') ?>> by naturalization
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <tr height=16>
    <td height=16 class=xl241 style='height:12.0pt'>&nbsp;</td>
    <td class=xl312>&nbsp;</td><td class=xl312>&nbsp;</td>
    <td class=xl313 width=881 style='width:98pt'>&nbsp;</td>
    <td class=xl317 width=881 style='width:33pt'>&nbsp;</td>
    <td class=xl317 width=881 style='width:29pt'>&nbsp;</td>
    <td class=xl222>&nbsp;</td><td class=xl230>&nbsp;</td><td class=xl230>&nbsp;</td>
    <td class=xl314>&nbsp;</td><td class=xl315>&nbsp;</td><td class=xl315>&nbsp;</td>
    <td class=xl315>&nbsp;</td><td class=xl316>&nbsp;</td>
  </tr>
  <!-- Place of Birth -->
  <tr height=33>
    <td height=33 class=xl243 style='height:24.75pt'> 4.</td>
    <td class=xl164 style='border-top:none'> PLACE OF BIRTH</td>
    <td class=xl164 style='border-top:none'>&nbsp;</td>
    <td colspan=3 class=xl425 style="padding-right:170px;padding-left:5px"><?= val($pi,'pob') ?></td>
    <td colspan=3 class=xl405> If holder of dual citizenship,</td>
    <td class=xl216>&nbsp;</td><td class=xl217>&nbsp;</td>
    <td colspan=3 class=xl411 style='border-right:1.0pt solid black'>Pls. indicate country:</td>
  </tr>
  <!-- Sex -->
  <tr height=33>
    <td height=33 class=xl244 style='height:24.75pt;border-top:none'>5.</td>
    <td class=xl164 style='border-top:none'>SEX</td>
    <td class=xl165 style='border-top:none'>&nbsp;</td>
    <td colspan=3 height=33 width=881 style='height:24.75pt;width:160pt' align=left valign=top>
      &nbsp;&nbsp;<input type="checkbox" <?= chk(val($pi,'sex'),'Male') ?>> Male
      &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
      <input type="checkbox" <?= chk(val($pi,'sex'),'Female') ?>> Female
    </td>
    <td colspan=3 class=xl402 style='border-right:.5pt solid black'>please indicate the details.</td>
    <td class=xl153 colspan="4" align="center"><?= val($pi,'dual_country') ?></td>
    <td class=xl231>&nbsp;</td>
  </tr>
  <!-- Civil Status + Residential Address -->
  <tr height=21>
    <td rowspan=2 height=33 class=xl361 style='height:24.75pt'> 6.</td>
    <td colspan=2 rowspan=2 class=xl359 style='border-right:.5pt solid black'> CIVIL STATUS</td>
    <td colspan=3 rowspan=5 class=xl358 style="text-align:left;font-size:14px;font-weight:100;border-bottom:.5pt solid black">
      &nbsp;&nbsp;<input type="checkbox" <?= chk(val($pi,'civil_status'),'Single') ?>>Single&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
      <input type="checkbox" <?= chk(val($pi,'civil_status'),'Married') ?>>Married<br>
      &nbsp;&nbsp;<input type="checkbox" <?= chk(val($pi,'civil_status'),'Widowed') ?>>Widowed&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
      <input type="checkbox" <?= chk(val($pi,'civil_status'),'Separated') ?>>Separated<br>
      &nbsp;&nbsp;<input type="checkbox">&nbsp;Other/s:
    </td>
    <td colspan=2 class=xl388 style='border-right:.5pt solid black'> 7. RESIDENTIAL ADDRESS</td>
    <td class=xl215>&nbsp;</td>
    <td colspan=3 class=xl394 style="font-size:12px;"><?= val($pi,'res_housenum') ?></td>
    <td colspan=3 class=xl391 style='border-right:1.0pt solid black;font-size:12px;'><?= val($pi,'res_street') ?></td>
  </tr>
  <tr height=12>
    <td height=12 class=xl360 style='height:9.0pt'>&nbsp;</td>
    <td class=xl355>&nbsp;</td><td class=xl356>&nbsp;</td>
    <td class=xl357 colspan=3 style='border-right:.5pt solid black;font-size:9px'>&nbsp;&nbsp;House/Block/Lot No.&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Street</td>
    <td class=xl389 colspan=3 style='border-right:1.0pt solid black'>&nbsp;</td>
  </tr>
  <tr height=33>
    <td height=33 class=xl362 style='height:24.75pt'>&nbsp;</td>
    <td colspan=2 class=xl366>&nbsp;</td>
    <td colspan=2 class=xl408 style='border-right:.5pt solid black'>RESIDENTIAL ADDRESS (Cont.)</td>
    <td class=xl215>&nbsp;</td>
    <td colspan=3 rowspan=2 class=xl369 style='border-bottom:.5pt solid #969696;font-size:12px;'><?= val($pi,'res_subdivision') ?></td>
    <td class=xl371 style='border-bottom:.5pt solid #969696;font-size:12px;'><?= val($pi,'res_barangay') ?></td>
    <td colspan=2 class=xl390 style='border-right:1.0pt solid black;border-bottom:.5pt solid #969696;font-size:12px;'></td>
  </tr>
  <tr height=11>
    <td height=11 class=xl363 style='height:8.25pt'>&nbsp;</td>
    <td colspan=2 class=xl367>&nbsp;</td>
    <td colspan=2 class=xl412>&nbsp;</td>
    <td class=xl215>&nbsp;</td>
    <td class=xl375 style='font-size:9px;'>&nbsp;&nbsp;Subdivision/Village&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Barangay</td>
    <td colspan=2 class=xl374 style='border-right:1.0pt solid black'>&nbsp;</td>
  </tr>
  <!-- Height / City / Municipality -->
  <tr height=33>
    <td height=33 class=xl365 style='height:24.75pt'>&nbsp;</td>
    <td colspan=2 class=xl368>&nbsp;</td>
    <td colspan=2 class=xl413>&nbsp;</td>
    <td class=xl215 style='border-top:none'>&nbsp;</td>
    <td colspan=3 rowspan=2 class=xl424 style='border-bottom:.5pt solid black;padding-right:190px;padding-left:5px'><?= val($pi,'height') ?></td>
    <td colspan=3 class=xl394 style="font-size:12px;"><?= val($pi,'res_city') ?></td>
    <td colspan=3 class=xl391 style='border-right:1.0pt solid black;font-size:12px;'><?= val($pi,'res_province') ?></td>
  </tr>
  <tr height=11>
    <td height=11 class=xl241 style='height:8.25pt'>&nbsp;</td>
    <td colspan=2 class=xl230>&nbsp;</td>
    <td colspan=3 class=xl415 style='border-right:1.0pt solid black'>&nbsp;</td>
    <td class=xl375 style='font-size:9px;'>&nbsp;&nbsp;City/Municipality&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Province</td>
    <td colspan=2 class=xl374 style='border-right:1.0pt solid black'>&nbsp;</td>
  </tr>
  <!-- Weight / Zip Code -->
  <tr height=33>
    <td height=33 class=xl241 style='height:24.75pt'>8.</td>
    <td colspan=2 class=xl230 style='border-right:.5pt solid black'> HEIGHT (m)</td>
    <td colspan=3 class=xl382 style="padding-left:5px;"><?= val($pi,'height') ?></td>
    <td colspan=3 rowspan=2 class=xl378 style='border-bottom:.5pt solid black'>&nbsp;&nbsp;ZIP Code</td>
    <td colspan=3 class=xl394 style="font-size:12px;">&nbsp;</td>
    <td colspan=3 class=xl391 style='border-right:1.0pt solid black;font-size:12px;'></td>
  </tr>
  <!-- Blood Type / Zip -->
  <tr height=11>
    <td height=11 class=xl241>&nbsp;</td>
    <td colspan=2 class=xl230>&nbsp;</td>
    <td colspan=3 class=xl416 style='border-left:none;width:289pt;font-size:12px;padding-left:5px;text-align:center'><?= val($pi,'res_zip') ?></td>
    <td class=xl375 style='font-size:9px;'>&nbsp;&nbsp;ZIP Code</td>
    <td colspan=2 class=xl374 style='border-right:1.0pt solid black'>&nbsp;</td>
  </tr>
  <!-- Blood Type row -->
  <tr height=33>
    <td height=33 class=xl241>9.</td>
    <td colspan=2 class=xl230 style='border-right:.5pt solid black'> WEIGHT (kg)</td>
    <td colspan=3 class=xl382 style="padding-left:5px;"><?= val($pi,'weight') ?></td>
    <td colspan=3 rowspan=2 class=xl380 style='border-bottom:.5pt solid black;padding-right:190px;padding-left:5px'><?= val($pi,'blood_type') ?></td>
    <td colspan=3 class=xl383 style="font-size:12px;"><?= val($pi,'perm_housenum') ?></td>
    <td colspan=3 class=xl384 style='border-right:1.0pt solid black;font-size:12px;'><?= val($pi,'perm_street') ?></td>
  </tr>
  <tr height=11>
    <td height=11 class=xl241>&nbsp;</td>
    <td colspan=2 class=xl230>&nbsp;</td>
    <td colspan=3 class=xl415>&nbsp;</td>
    <td class=xl375 style='font-size:9px;'>&nbsp;&nbsp;House/Block/Lot No.&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Street</td>
    <td colspan=2 class=xl374 style='border-right:1.0pt solid black'>&nbsp;</td>
  </tr>
  <!-- GSIS / Permanent Subdivision -->
  <tr height=33>
    <td height=33 class=xl241>10.</td>
    <td colspan=2 class=xl230 style='border-right:.5pt solid black'> BLOOD TYPE</td>
    <td colspan=3 class=xl382 style="padding-left:5px;"><?= val($pi,'blood_type') ?></td>
    <td colspan=3 rowspan=2 class=xl380 style='border-bottom:.5pt solid black;padding-right:165px;padding-left:5px'><?= val($pi,'umid') ?></td>
    <td colspan=3 class=xl383 style="font-size:12px;"><?= val($pi,'perm_subdivision') ?></td>
    <td colspan=3 class=xl384 style='border-right:1.0pt solid black;font-size:12px;'><?= val($pi,'perm_barangay') ?></td>
  </tr>
  <tr height=11>
    <td height=11 class=xl241>&nbsp;</td>
    <td colspan=2 class=xl230>&nbsp;</td>
    <td colspan=3 class=xl415>&nbsp;</td>
    <td class=xl375 style='font-size:9px;'>&nbsp;&nbsp;Subdivision/Village&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Barangay</td>
    <td colspan=2 class=xl374 style='border-right:1.0pt solid black'>&nbsp;</td>
  </tr>
  <!-- GSIS ID No -->
  <tr height=33>
    <td height=33 class=xl241>11.</td>
    <td colspan=2 class=xl230 style='border-right:.5pt solid black'> GSIS ID NO.</td>
    <td colspan=3 class=xl382 style="padding-left:5px;"><?= val($pi,'umid') ?></td>
    <td colspan=3 rowspan=2 class=xl380 style='border-bottom:.5pt solid black;padding-right:165px;padding-left:5px'><?= val($pi,'pagibig') ?></td>
    <td colspan=3 class=xl394 style="font-size:12px;"><?= val($pi,'perm_city') ?></td>
    <td colspan=3 class=xl391 style='border-right:1.0pt solid black;font-size:12px;'><?= val($pi,'perm_province') ?></td>
  </tr>
  <tr height=11>
    <td height=11 class=xl241>&nbsp;</td>
    <td colspan=2 class=xl230>&nbsp;</td>
    <td colspan=3 class=xl415>&nbsp;</td>
    <td class=xl375 style='font-size:9px;'>&nbsp;&nbsp;City/Municipality&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Province</td>
    <td colspan=2 class=xl374 style='border-right:1.0pt solid black'>&nbsp;</td>
  </tr>
  <!-- PAG-IBIG ID No -->
  <tr height=33>
    <td height=33 class=xl241>12.</td>
    <td colspan=2 class=xl230 style='border-right:.5pt solid black'> PAG-IBIG ID NO.</td>
    <td colspan=3 class=xl410 style="padding-left:5px"><?= val($pi,'pagibig') ?></td>
    <td colspan=3 rowspan=2 class=xl380 style='border-bottom:.5pt solid black;padding-left:5px'>&nbsp;&nbsp;ZIP Code</td>
    <td colspan=3 class=xl226 style='border-top:none;border-left:none;font-size:12px;text-align:center;'><?= val($pi,'perm_zip') ?></td>
    <td colspan=3 class=xl227 style='border-top:none;border-right:1.0pt solid black;font-size:12px;text-align:center'>&nbsp;</td>
  </tr>
  <tr height=11>
    <td height=11 class=xl241>&nbsp;</td>
    <td colspan=2 class=xl230>&nbsp;</td>
    <td colspan=3 class=xl415>&nbsp;</td>
    <td colspan=6 class=xl463 style='border-left:none;font-size:12px;border-right:1.0pt solid black;padding-left:5px;text-align:center'><?= val($pi,'perm_zip') ?></td>
  </tr>
  <!-- PhilHealth -->
  <tr height=33>
    <td height=33 class=xl241>13.</td>
    <td colspan=2 class=xl230 style='border-right:.5pt solid black'> PHILHEALTH NO.</td>
    <td colspan=3 class=xl410 style="padding-left:5px"><?= val($pi,'philhealth') ?></td>
    <td colspan=3 rowspan=2 class=xl380 style='border-bottom:.5pt solid black;padding-right:340px;padding-left:5px'><b><?= val($pi,'tel') ?></b></td>
    <td colspan=6 style='border-right:1.0pt solid black;font-size:12px;padding-left:5px'>&nbsp;</td>
  </tr>
  <tr height=11>
    <td height=11 class=xl241>&nbsp;</td>
    <td colspan=2 class=xl230>&nbsp;</td>
    <td colspan=3 class=xl415>&nbsp;</td>
    <td class=xl375 style='font-size:9px;'>&nbsp;&nbsp;Telephone No.</td>
    <td colspan=2 class=xl374>&nbsp;</td>
  </tr>
  <!-- PSN / Mobile -->
  <tr height=33>
    <td height=33 class=xl241>14.</td>
    <td colspan=2 class=xl230 style='border-right:.5pt solid black'> SSS NO.</td>
    <td colspan=3 class=xl382 style="padding-left:5px;"><?= val($pi,'psn') ?></td>
    <td colspan=3 rowspan=2 class=xl380 style='border-bottom:.5pt solid black;padding-right:300px;padding-left:5px'><b><?= val($pi,'mobile') ?></b></td>
    <td colspan=6 style='border-right:1.0pt solid black;font-size:12px;padding-left:5px'>&nbsp;</td>
  </tr>
  <tr height=11>
    <td height=11 class=xl241>&nbsp;</td>
    <td colspan=2 class=xl230>&nbsp;</td>
    <td colspan=3 class=xl415>&nbsp;</td>
    <td class=xl375 style='font-size:9px;'>&nbsp;&nbsp;Mobile No.</td>
    <td colspan=2 class=xl374>&nbsp;</td>
  </tr>
  <!-- TIN / Email -->
  <tr height=33>
    <td height=33 class=xl241>15.</td>
    <td colspan=2 class=xl230 style='border-right:.5pt solid black'> TIN NO.</td>
    <td colspan=3 class=xl415 style="padding-left:5px"><b><?= val($pi,'tin') ?></b></td>
    <td colspan=3 rowspan=2 class=xl380 style='border-bottom:.5pt solid black;padding-left:5px'><b><?= val($pi,'email') ?></b></td>
    <td colspan=6 style='border-right:1.0pt solid black;font-size:12px;padding-left:5px'>&nbsp;</td>
  </tr>
  <tr height=11>
    <td height=11 class=xl241>&nbsp;</td>
    <td colspan=2 class=xl230>&nbsp;</td>
    <td colspan=3 class=xl415>&nbsp;</td>
    <td class=xl375 style='font-size:9px;'>&nbsp;&nbsp;E-mail address</td>
    <td colspan=2 class=xl374>&nbsp;</td>
  </tr>
  <!-- Agency Employee No / 8. PERMANENT ADDRESS label -->
  <tr height=33>
    <td height=33 class=xl241>&nbsp;</td>
    <td colspan=2 class=xl230 style='border-right:.5pt solid black'> AGENCY EMPLOYEE NO.</td>
    <td colspan=3 class=xl410 style="padding-left:5px"><?= val($pi,'agency_no') ?></td>
    <td colspan=2 class=xl388 style='border-right:.5pt solid black'> 8. PERMANENT ADDRESS</td>
    <td class=xl215>&nbsp;</td>
    <td colspan=6 style='border-right:1.0pt solid black'>&nbsp;</td>
  </tr>
  <!-- Signature / Date line -->
  <tr height=37>
    <td colspan=3 height=37 class=xl501 width=881 style='border-right:1.0pt solid black;height:27.75pt;width:116pt'>SIGNATURE</td>
    <td colspan=6 class=xl510 width=881 style='border-right:1.0pt solid black;width:308pt'>&nbsp;</td>
    <td colspan=2 class=xl513 style='border-right:1.0pt solid black'>DATE</td>
    <td colspan=3 class=xl507 style='border-right:1.0pt solid black;border-left:none'>&nbsp;</td>
  </tr>
  <tr class=xl213 height=16>
    <td colspan=14 height=16 class=xl500 style='height:12.0pt'><span style='mso-spacerun:yes'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>CS FORM 212 (Revised 2025), Page 1 of 4</td>
  </tr>
</table>
</section>
</div><!-- end page 1 -->


<!-- ============================================================
     PAGE 2 — Family Background + Educational Background
     ============================================================ -->
<div class="page">
<section class="content-wrapper" style="background-color:#FFFFFF">
<br><br><br>

<!-- Family Background (left half) + Children (right half) -->
<table border=0 cellpadding=0 cellspacing=0 width=1000 style='border-collapse:collapse;table-layout:fixed;width:1157pt;'>
  <col class=xl153 width=17><col class=xl153 width=95><col class=xl153 width=42>
  <col class=xl153 width=131><col class=xl153 width=44><col class=xl153 width=39>
  <col class=xl153 width=60><col class=xl153 width=70><col class=xl153 width=66>
  <col class=xl153 width=54 span=2><col class=xl153 width=80>
  <col class=xl153 width=62><col class=xl153 width=67>
  <col class=xl153 width=98><col class=xl153 width=0 span=2 style='display:none;'>
  <col class=xl153 width=98><col class=xl153 width=64 span=6>

  <tr>
    <td colspan=14><table border=0 cellpadding=0 cellspacing=0 width=520 style='border-collapse:collapse;table-layout:fixed;'>

      <!-- II. FAMILY BACKGROUND -->
      <tr height=22>
        <td colspan=14 height=22 class=xl452 style='border-right:1.0pt solid black;height:16.5pt;'> II. FAMILY BACKGROUND</td>
      </tr>
      <!-- Spouse -->
      <tr height=30>
        <td height=30 class=xl241 style='height:22.5pt'>17.</td>
        <td colspan=2 class=xl215 style='border-right:.5pt solid black'> SPOUSE'S SURNAME</td>
        <td colspan=11 class=xl428 style='border-right:1.0pt solid black;border-left:none;padding-left:5px;'><?= val($fb,'sp_surname') ?></td>
      </tr>
      <tr height=28>
        <td height=28 class=xl222 style='height:21.0pt'>&nbsp;</td>
        <td class=xl230>FIRST NAME</td><td class=xl230>&nbsp;</td>
        <td colspan=5 class=xl496 style="text-align:left;padding-left:5px"><?= val($fb,'sp_firstname') ?></td>
      </tr>
      <tr height=28>
        <td height=28 class=xl222 style='height:21.0pt'>&nbsp;</td>
        <td class=xl230>MIDDLE NAME</td><td class=xl230>&nbsp;</td>
        <td colspan=5 class=xl496 style="text-align:left;padding-left:5px"><?= val($fb,'sp_middle') ?></td>
      </tr>
      <tr height=28>
        <td height=28 class=xl222>&nbsp;</td>
        <td class=xl230>NAME EXTENSION</td><td class=xl230>&nbsp;</td>
        <td colspan=5 class=xl496 style="text-align:left;padding-left:5px"><?= val($fb,'sp_ext') ?></td>
      </tr>
      <tr height=28>
        <td height=28 class=xl222>&nbsp;</td>
        <td class=xl230>OCCUPATION</td><td class=xl230>&nbsp;</td>
        <td colspan=5 class=xl357 style='text-align:left;padding-left:5px;'><?= val($fb,'sp_occupation') ?></td>
      </tr>
      <tr height=28>
        <td height=28 class=xl222>&nbsp;</td>
        <td class=xl230>EMPLOYER/BUSINESS NAME</td><td class=xl230>&nbsp;</td>
        <td colspan=5 class=xl357 style='text-align:left;padding-left:5px;'><?= val($fb,'sp_employer') ?></td>
      </tr>
      <tr height=28>
        <td height=28 class=xl222>&nbsp;</td>
        <td class=xl230>BUSINESS ADDRESS</td><td class=xl230>&nbsp;</td>
        <td colspan=5 class=xl357 style='text-align:left;padding-left:5px;'><?= val($fb,'sp_biz_addr') ?></td>
      </tr>
      <tr height=28>
        <td height=28 class=xl250 style='height:21.0pt'>&nbsp;</td>
        <td class=xl289>TELEPHONE NO.</td><td class=xl289>&nbsp;</td>
        <td colspan=5 class=xl357 style='text-align:left;padding-left:5px;'><?= val($fb,'sp_tel') ?></td>
      </tr>
      <!-- Father -->
      <tr height=30>
        <td height=30 class=xl241>18.</td>
        <td colspan=2 class=xl215 style='border-right:.5pt solid black'> FATHER'S SURNAME</td>
        <td colspan=5 class=xl357 style='text-align:left;padding-left:5px;'><?= val($fb,'fa_surname') ?></td>
      </tr>
      <tr height=28>
        <td height=28 class=xl222>&nbsp;</td>
        <td class=xl230>FIRST NAME</td><td class=xl230>&nbsp;</td>
        <td colspan=5 class=xl496 style="text-align:left;padding-left:5px"><?= val($fb,'fa_firstname') ?></td>
      </tr>
      <tr height=28>
        <td height=28 class=xl222>&nbsp;</td>
        <td class=xl230>MIDDLE NAME</td><td class=xl230>&nbsp;</td>
        <td colspan=5 class=xl496 style="text-align:left;padding-left:5px"><?= val($fb,'fa_middle') ?></td>
      </tr>
      <!-- Mother -->
      <tr height=30>
        <td height=30 class=xl241>19.</td>
        <td colspan=2 class=xl215 style='border-right:.5pt solid black'> MOTHER'S MAIDEN NAME</td>
        <td colspan=5 class=xl357 style='text-align:left;padding-left:5px;'><?= val($fb,'mo_surname') ?></td>
      </tr>
      <tr height=28>
        <td height=28 class=xl222>&nbsp;</td>
        <td class=xl230>FIRST NAME</td><td class=xl230>&nbsp;</td>
        <td colspan=5 class=xl496 style="text-align:left;padding-left:5px"><?= val($fb,'mo_firstname') ?></td>
      </tr>
      <tr height=28>
        <td height=28 class=xl250>&nbsp;</td>
        <td class=xl289>MIDDLE NAME</td><td class=xl289>&nbsp;</td>
        <td colspan=5 class=xl350 style='text-align:left;padding-left:5px'><?= val($fb,'mo_middle') ?></td>
      </tr>

    </table></td>
    <td><table border=0 cellpadding=0 cellspacing=0 width=480 style='border-collapse:collapse;table-layout:fixed;'>
      <col width=10><col width=10><col width=100><col width=100>
      <col width=49><col width=90><col width=48 span=2>
      <col width=123><col width=59><col width=65><col width=78><col width=56><col width=64>

      <!-- Children section -->
      <tr height=27>
        <td colspan=4 class=xl436 style='border-right:.5pt solid black;'>23. NAME of CHILDREN<span style='mso-spacerun:yes'>  </span>(Write full name and list all)</td>
        <td colspan=2 class=xl440 style='border-right:1.0pt solid black;border-left:none;'>DATE OF BIRTH <br> (dd/mm/yyyy)</td>
      </tr>
      <?php
      $childCount = count($children);
      foreach ($childPad as $i => $child):
        $cname = htmlspecialchars($child['cname'] ?? $child['name'] ?? '', ENT_QUOTES, 'UTF-8');
        $cbday = fmtDate($child['bday'] ?? $child['date_of_birth'] ?? '');
        if ($i < $childCount): ?>
        <tr height=28>
          <td colspan=4 class=xl346 width=881 style='border-right:.5pt solid black;width:192px;font-size:12px;padding-top:5px'><b><?= $cname ?></b></td>
          <td colspan=2 class=xl351 width=881 style='border-right:1.0pt solid black;border-left:none;width:97pt'><?= $cbday ?></td>
        </tr>
        <?php else: ?>
        <tr height=28>
          <td colspan=4 class=xl346 style='border-right:.5pt solid black;width:192px;'></td>
          <td colspan=2 class=xl351 style='border-right:1.0pt solid black;border-left:none;width:97pt;'></td>
        </tr>
        <?php endif; endforeach; ?>
      <tr height=28>
        <td colspan=6 class=xl348 style='border-right:1.0pt solid black;width:289px;'>(Continue on separate sheet if necessary)</td>
      </tr>
    </table></td>
  </tr>
</table>

<!-- Educational Background -->
<table border=0 cellpadding=0 cellspacing=0 width=1000 style='border-collapse:collapse;table-layout:fixed;width:1157pt;'>
  <col class=xl153 width=17><col class=xl153 width=95><col class=xl153 width=42>
  <col class=xl153 width=131><col class=xl153 width=44><col class=xl153 width=39>
  <col class=xl153 width=60><col class=xl153 width=70><col class=xl153 width=66>
  <col class=xl153 width=54 span=2><col class=xl153 width=80>
  <col class=xl153 width=62><col class=xl153 width=67>

  <tr height=21>
    <td height=21 class=xl251 style='height:15.75pt' colspan="3">III. EDUCATIONAL BACKGROUND</td>
    <td class=xl168>&nbsp;</td><td class=xl168>&nbsp;</td><td class=xl168>&nbsp;</td>
    <td class=xl168>&nbsp;</td><td class=xl168>&nbsp;</td>
    <td colspan=6 class=xl432 width=881 style='border-right:1.0pt solid black;width:289pt'>&nbsp;</td>
  </tr>
  <tr height=19>
    <td height=45 class=xl442 style='height:33.75pt'>26.</td>
    <td colspan=2 rowspan=2 class=xl478 style='border-bottom:.5pt solid black'>LEVEL</td>
    <td colspan=3 rowspan=2 class=xl480 width=881 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:160pt'>NAME OF SCHOOL<br>(Write in full)</td>
    <td colspan=3 rowspan=2 class=xl480 width=881 style='border-right:1.0pt solid black;border-bottom:.5pt solid black;width:148pt'>BASIC EDUCATION/DEGREE/COURSE<br>(Write in full)</td>
    <td colspan=2 class=xl353 width=881 style='border-right:1.0pt solid black;border-bottom:.5pt solid black;width:82pt'>PERIOD OF <br>ATTENDANCE</td>
    <td rowspan=2 class=xl494 width=881 style='border-bottom:.5pt solid black;width:60pt'>HIGHEST LEVEL/<br>UNITS EARNED<br>(if not graduated)</td>
    <td rowspan=2 class=xl444 width=881 style='border-bottom:.5pt solid black;width:47pt'>YEAR GRADUATED</td>
    <td rowspan=2 class=xl438 width=881 style='border-bottom:.5pt solid black;width:50pt'>SCHOLARSHIP/ ACADEMIC HONORS RECEIVED</td>
  </tr>
  <tr height=19>
    <td height=19 class=xl302 style='height:14.25pt'>&nbsp;</td>
    <td class=xl173 width=881 style='border-top:none;width:41pt'>From</td>
    <td class=xl174 width=881 style='border-top:none;border-left:none;width:41pt'>To</td>
  </tr>
  <?php
  $eduLevels = ['Elementary','Secondary','Vocational/Trade Course','College','Graduate Studies'];
  foreach ($eduLevels as $idx => $level):
    $e = $edPad[$idx] ?? [];
    $school = htmlspecialchars($e['school'] ?? '', ENT_QUOTES, 'UTF-8');
    $degree = htmlspecialchars($e['degree'] ?? '', ENT_QUOTES, 'UTF-8');
    $from   = htmlspecialchars($e['from'] ?? $e['att_from'] ?? '', ENT_QUOTES, 'UTF-8');
    $to     = htmlspecialchars($e['to'] ?? $e['att_to'] ?? '', ENT_QUOTES, 'UTF-8');
    $units  = htmlspecialchars($e['units'] ?? $e['unitsearned'] ?? '', ENT_QUOTES, 'UTF-8');
    $yearGrad = htmlspecialchars($e['year_grad'] ?? $e['yeargraduated'] ?? '', ENT_QUOTES, 'UTF-8');
    $honors = htmlspecialchars($e['honors'] ?? $e['awards'] ?? '', ENT_QUOTES, 'UTF-8');
  ?>
  <tr height=38>
    <td height=38 class=xl307 width=881 style='height:28.5pt;border-top:none;width:13pt'>&nbsp;</td>
    <td class=xl308 width=881 style='border-top:none;width:71pt'><?= $level ?></td>
    <td class=xl308 width=881 style='border-top:none;width:32pt'>&nbsp;</td>
    <td colspan=3 class=xl488 width=881 style='border-right:.5pt solid black;width:160pt;font-size:14px;'><?= $school ?></td>
    <td colspan=3 class=xl469 width=881 style='border-right:1.0pt solid black;border-left:none;width:148pt;font-size:14px;text-align:center'><?= $degree ?></td>
    <td class=xl90 width=881 style='border-top:none;width:41pt;text-align:center'><?= $from ?></td>
    <td class=xl301 width=881 style='border-top:none;width:41pt'><?= $to ?></td>
    <td class=xl235 width=881 style='border-top:none;width:60pt;padding-left:5px;'><?= $units ?></td>
    <td class=xl82 width=881 style='border-top:none;border-left:none;width:47pt;text-align:center'><?= $yearGrad ?></td>
    <td class=xl229 width=881 style='border-top:none;border-left:none;width:50pt;padding-left:5px;'><?= $honors ?></td>
  </tr>
  <?php endforeach; ?>
  <tr height=16>
    <td colspan=14 height=16 class=xl504 style='border-right:1.0pt solid black;height:12.0pt'>(Continue on separate sheet if necessary)</td>
  </tr>
  <tr height=37>
    <td colspan=3 height=37 class=xl501 width=881 style='border-right:1.0pt solid black;height:27.75pt;width:116pt'>SIGNATURE</td>
    <td colspan=6 class=xl510 width=881 style='border-right:1.0pt solid black;width:308pt'>&nbsp;</td>
    <td colspan=2 class=xl513 style='border-right:1.0pt solid black'>DATE</td>
    <td colspan=3 class=xl507 style='border-right:1.0pt solid black;border-left:none'>&nbsp;</td>
  </tr>
  <tr class=xl213 height=16>
    <td colspan=14 height=16 class=xl500 style='height:12.0pt'><span style='mso-spacerun:yes'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>CS FORM 212 (Revised 2025), Page 1 of 4</td>
  </tr>
</table>
</section>
</div><!-- end page 2 -->


<!-- ============================================================
     PAGE 3 — Civil Service Eligibility + Work Experience
     ============================================================ -->
<div class="page">
<section class="content-wrapper" style="background-color:#FFFFFF">
<br>
<table border=0 cellpadding=0 cellspacing=0 width=1000 style='border-collapse:collapse;table-layout:fixed;width:753pt'>
  <col width=23><col width=44><col width=68><col width=74>
  <col width=49><col width=90><col width=48 span=2>
  <col width=123><col width=59><col width=65><col width=78><col width=56><col width=64>

  <!-- IV. CIVIL SERVICE ELIGIBILITY -->
  <tr height=24>
    <td colspan=13 height=24 class=xl558 style='border-right:1.0pt solid black;height:18.0pt'>IV. CIVIL SERVICE ELIGIBILITY</td>
    <td></td>
  </tr>
  <tr height=20>
    <td height=20 class=xl210 style='height:15.0pt;border-top:none'>27.</td>
    <td colspan=4 rowspan=2 class=xl552 width=235 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:177pt'>CAREER SERVICE/ RA 1080 (BOARD/BAR) UNDER SPECIAL LAWS/ CES/ CSEE<br>BARANGAY ELIGIBILITY / DRIVER'S LICENSE</td>
    <td rowspan=2 class=xl556 width=90 style='border-top:none;width:68pt'>RATING<br>(If Applicable)</td>
    <td colspan=2 rowspan=2 class=xl554 width=96 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:72pt'>DATE OF<br>EXAMINATION /<br>CONFERMENT</td>
    <td colspan=3 rowspan=2 class=xl554 width=247 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:185pt'>PLACE OF EXAMINATION / CONFERMENT</td>
    <td colspan=2 class=xl256 style='border-right:1.0pt solid black;border-left:none'>LICENSE (if applicable)</td>
    <td></td>
  </tr>
  <tr height=34>
    <td height=34 class=xl68 style='height:25.5pt'>&nbsp;</td>
    <td class=xl69 style='border-top:none;border-left:none'>NUMBER</td>
    <td class=xl70 width=56 style='border-top:none;border-left:none;width:42pt'>Date of<br>Validity</td>
    <td></td>
  </tr>
  <?php
  foreach ($csPad as $i => $e):
    $career = htmlspecialchars($e['eligibility'] ?? $e['career'] ?? '', ENT_QUOTES, 'UTF-8');
    $rate   = htmlspecialchars($e['rating'] ?? $e['rate'] ?? '', ENT_QUOTES, 'UTF-8');
    $dExam  = fmtDate($e['date_exam'] ?? $e['dateexam'] ?? '');
    $place  = htmlspecialchars($e['place'] ?? $e['placeexam'] ?? '', ENT_QUOTES, 'UTF-8');
    $num    = htmlspecialchars($e['number'] ?? '', ENT_QUOTES, 'UTF-8');
    $dValid = fmtDate($e['date_valid'] ?? $e['datevalid'] ?? '');
  ?>
  <tr height=36>
    <td colspan=5 height=36 class=xl532 width=258 style='height:27.0pt;width:194pt'><?= $career ?></td>
    <td class=xl80 width=90 style='border-left:none;width:68pt'><?= $rate ?></td>
    <td colspan=2 class=xl534 width=96 style='border-left:none;width:72pt'><?= $dExam ?></td>
    <td colspan=3 class=xl533 width=247 style='border-left:none;width:185pt'><?= $place ?></td>
    <td class=xl73 width=78 style='border-left:none;width:59pt'><?= $num ?></td>
    <td class=xl86 width=56 style='border-left:none;width:42pt'><?= $dValid ?></td>
    <td></td>
  </tr>
  <?php endforeach; ?>
  <tr height=16>
    <td colspan=13 height=16 class=xl528 style='border-right:1.0pt solid black;height:12.0pt'>(Continue on separate sheet if necessary)</td>
    <td></td>
  </tr>

  <!-- V. WORK EXPERIENCE -->
  <tr height=24>
    <td colspan=13 height=24 class=xl290 style='border-right:1.0pt solid black;height:18.0pt'>V. WORK EXPERIENCE</td>
    <td></td>
  </tr>
  <tr height=16>
    <td height=16 class=xl286 colspan=12 style='height:12.0pt;mso-ignore:colspan'>(Include private employment. Start from your recent work) Description of duties should be indicated in the attached Work Experience sheet.</td>
    <td class=xl285>&nbsp;</td>
    <td></td>
  </tr>
  <tr height=24>
    <td height=24 class=xl68 style='height:18.0pt'>28.</td>
    <td colspan=2 rowspan=2 class=xl524 width=112 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:84pt'>INCLUSIVE DATES (dd/mm/yyyy)</td>
    <td colspan=3 rowspan=3 class=xl538 width=213 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:161pt'>POSITION TITLE<br>(Write in full/Do not abbreviate)</td>
    <td colspan=4 rowspan=3 class=xl538 width=219 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:164pt'>DEPARTMENT / AGENCY / OFFICE / COMPANY<br>(Write in full/Do not abbreviate)</td>
    <td rowspan=3 colspan=2 class=xl538 width=101 style='border-bottom:.5pt solid black;width:101pt'>STATUS OF APPOINTMENT</td>
    <td rowspan=3 class=xl561 width=103 style='border-bottom:.5pt solid black;width:104pt'>GOV'T<br>SERVICE<br>(Y/ N)</td>
    <td></td>
  </tr>
  <tr height=20>
    <td height=20 class=xl71 style='height:15.0pt'></td>
    <td></td>
  </tr>
  <tr height=25>
    <td colspan=2 height=25 class=xl537 style='border-right:.5pt solid black;height:18.75pt'>From</td>
    <td class=xl72 style='border-top:none;border-left:none'>To</td>
    <td></td>
  </tr>
  <?php foreach ($wePad as $e):
    $wFrom   = fmtDate($e['date_from'] ?? $e['workfrom'] ?? '');
    $wTo     = $e['date_to'] ?? $e['workto'] ?? '';
    $wToFmt  = (!$wTo || $wTo === '') ? 'Present' : fmtDate($wTo);
    $wPos    = htmlspecialchars($e['position'] ?? $e['workposition'] ?? '', ENT_QUOTES, 'UTF-8');
    $wDept   = htmlspecialchars($e['dept_agency'] ?? $e['workdept'] ?? '', ENT_QUOTES, 'UTF-8');
    $wStatus = htmlspecialchars($e['status'] ?? $e['workstatus'] ?? '', ENT_QUOTES, 'UTF-8');
    $wGov    = htmlspecialchars($e['gov_service'] ?? $e['workgov'] ?? '', ENT_QUOTES, 'UTF-8');
  ?>
  <tr class=xl77 height=35>
    <td colspan=2 height=35 class=xl515 width=67 style='height:27.0pt;width:50pt;padding-left:5px;'><?= $wFrom ?></td>
    <td class=xl88 width=68 style='border-top:none;border-left:none;width:51pt;padding-left:5px;'><?= $wToFmt ?></td>
    <td colspan=3 class=xl73 width=213 style='border-left:none;width:161pt'><?= $wPos ?></td>
    <td colspan=4 class=xl73 width=219 style='border-left:none;width:164pt'><?= $wDept ?></td>
    <td class=xl73 colspan=2 width=213 style='border-top:none;border-left:none;width:103pt'><?= $wStatus ?></td>
    <td class=xl74 width=213 style='border-top:none;border-left:none;width:101pt'><?= $wGov ?></td>
    <td></td>
  </tr>
  <?php endforeach; ?>
  <tr height=13>
    <td colspan=13 height=13 class=xl519 style='border-right:1.0pt solid black;height:9.75pt'>(Continue on separate sheet if necessary)</td>
    <td></td>
  </tr>
  <tr height=37>
    <td colspan=3 height=37 class=xl501 width=135 style='border-right:1.0pt solid black;height:27.75pt;width:101pt'>SIGNATURE</td>
    <td colspan=5 class=xl546 style='border-right:1.0pt solid black;border-left:none'>&nbsp;</td>
    <td class=xl320 style='border-left:none'>DATE</td>
    <td class=xl321 style='border-left:none'>&nbsp;</td>
    <td class=xl322>&nbsp;</td><td class=xl322>&nbsp;</td><td class=xl327>&nbsp;</td><td class=xl323></td>
  </tr>
  <tr height=12>
    <td colspan=13 height=12 class=xl545 style='height:9.0pt'><span style='mso-spacerun:yes'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>CS FORM 212 (Revised 2025), Page 2 of 4</td>
    <td class=xl326>&nbsp;</td>
  </tr>
</table>
</section>
</div><!-- end page 3 -->


<!-- ============================================================
     PAGE 4 — Voluntary Work + Training + Other Info + Q&A + References
     ============================================================ -->
<div class="page">
<section class="content-wrapper" style="background-color:#FFFFFF">
<br>
<table border=0 cellpadding=0 cellspacing=0 width=995 style='border-collapse:collapse;table-layout:fixed;width:648pt'>
  <col width=26><col width=214><col width=22><col width=145>
  <col width=72><col width=71 span=2><col width=79>
  <col width=21><col width=32><col width=183><col width=26><col width=33>

  <!-- VI. VOLUNTARY WORK -->
  <tr style='page-break-before:always;'>
    <td colspan=11 height=30 class=xl582 style='border-right:1.0pt solid black;height:22.5pt'>VI. VOLUNTARY WORK OR INVOLVEMENT IN CIVIC / NON-GOVERNMENT / PEOPLE / VOLUNTARY ORGANIZATION/S</td>
    <td></td><td></td>
  </tr>
  <tr height=20>
    <td rowspan=2 height=35 class=xl596 style='height:26.25pt;border-top:none'>29.</td>
    <td colspan=3 rowspan=2 class=xl597 style='border-right:.5pt solid black;width:287pt'>NAME &amp; ADDRESS OF ORGANIZATION<br>(Write in full)</td>
    <td colspan=2 rowspan=2 class=xl552 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:107pt'>INCLUSIVE DATES<br>(dd/mm/yyyy)</td>
    <td rowspan=3 class=xl616 width=71 style='border-bottom:.5pt solid black;border-top:none;width:53pt'>NUMBER OF HOURS</td>
    <td colspan=4 rowspan=3 class=xl554 style='border-right:1.0pt solid black;border-bottom:.5pt solid black;width:236pt'>POSITION / NATURE OF WORK</td>
    <td></td><td></td>
  </tr>
  <tr height=15>
    <td height=15 style='height:11.25pt'></td><td></td>
  </tr>
  <tr height=18>
    <td height=18 class=xl92 style='height:13.5pt'>&nbsp;</td>
    <td colspan=3 class=xl614 style='border-right:.5pt solid black;width:287pt'>&nbsp;</td>
    <td class=xl93 style='border-top:none'>From</td>
    <td class=xl72 style='border-top:none;border-left:none'>To</td>
    <td></td><td></td>
  </tr>
  <?php foreach ($vwPad as $e):
    $vName = htmlspecialchars($e['org_name'] ?? $e['vname'] ?? '', ENT_QUOTES, 'UTF-8');
    $vFrom = fmtDate($e['from'] ?? $e['vfrom'] ?? '');
    $vTo   = fmtDate($e['to'] ?? $e['vto'] ?? '');
    $vHrs  = htmlspecialchars($e['hours'] ?? $e['vhours'] ?? '', ENT_QUOTES, 'UTF-8');
    $vPos  = htmlspecialchars($e['nature'] ?? $e['vposition'] ?? '', ENT_QUOTES, 'UTF-8');
  ?>
  <tr height=37>
    <td colspan=4 height=37 class=xl604 style='border-right:.5pt solid black;height:27.75pt;width:307pt;padding-left:5px;'><?= $vName ?></td>
    <td class=xl102 style='border-top:none;border-left:none;padding-left:5px;'><?= $vFrom ?></td>
    <td class=xl102 style='border-top:none;border-left:none;padding-left:5px;'><?= $vTo ?></td>
    <td class=xl103 style='border-top:none;border-left:none;padding-left:5px;'><?= $vHrs ?></td>
    <td colspan=4 class=xl593 style='border-right:1.0pt solid black;border-left:none;padding-left:5px;'><?= $vPos ?></td>
    <td></td><td></td>
  </tr>
  <?php endforeach; ?>
  <tr height=15>
    <td colspan=11 height=15 class=xl528 style='border-right:1.0pt solid black;height:11.25pt'>(Continue on separate sheet if necessary)</td>
    <td></td><td></td>
  </tr>

  <!-- VII. LEARNING AND DEVELOPMENT -->
  <tr height=24>
    <td colspan=11 height=24 class=xl577 style='border-right:1.0pt solid black;height:18.0pt;width:703pt'>VII. LEARNING AND DEVELOPMENT (L&amp;D) INTERVENTIONS/TRAINING PROGRAMS ATTENDED</td>
    <td></td><td></td>
  </tr>
  <tr height=21>
    <td colspan=11 height=21 class=xl590 style='border-right:1.0pt solid black;height:15.75pt;width:703pt'>(Start from the most recent L&amp;D/training program and include only the relevant L&amp;D/training taken for the last five (5) years for Division Chief/Executive/Managerial positions)</td>
    <td></td><td></td>
  </tr>
  <tr height=24>
    <td rowspan=2 height=58 class=xl405 style='height:43.5pt'>30.</td>
    <td colspan=3 rowspan=3 class=xl524 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:287pt'>TITLE OF LEARNING AND DEVELOPMENT INTERVENTIONS/TRAINING PROGRAMS<br>(Write in full)</td>
    <td colspan=2 rowspan=2 class=xl524 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:107pt'>INCLUSIVE DATES OF ATTENDANCE<br>(dd/mm/yyyy)</td>
    <td rowspan=3 class=xl535 width=71 style='border-bottom:.5pt solid black;width:53pt'>NUMBER OF HOURS</td>
    <td rowspan=3 class=xl541 width=79 style='border-bottom:.5pt solid black;width:59pt'>Type of LD<br>(Managerial/Supervisory/Technical/etc)</td>
    <td colspan=3 rowspan=3 class=xl538 style='border-right:1.0pt solid black;border-bottom:.5pt solid black;width:177pt'>CONDUCTED/SPONSORED BY<br>(Write in full)</td>
    <td></td><td></td>
  </tr>
  <tr height=34>
    <td height=34 style='height:25.5pt'></td><td></td>
  </tr>
  <tr height=18>
    <td height=18 class=xl92 style='height:13.5pt'>&nbsp;</td>
    <td class=xl93 style='border-top:none'>From</td>
    <td class=xl72 style='border-top:none;border-left:none'>To</td>
    <td></td><td></td>
  </tr>
  <?php foreach ($trPad as $e):
    $tTitle   = htmlspecialchars($e['program'] ?? $e['title'] ?? '', ENT_QUOTES, 'UTF-8');
    $tFrom    = fmtDate($e['from'] ?? $e['lfrom'] ?? '');
    $tTo      = fmtDate($e['to'] ?? $e['lto'] ?? '');
    $tHours   = htmlspecialchars($e['hours'] ?? $e['lhours'] ?? '', ENT_QUOTES, 'UTF-8');
    $tType    = htmlspecialchars($e['type'] ?? '', ENT_QUOTES, 'UTF-8');
    $tSponsor = htmlspecialchars($e['sponsor'] ?? $e['conduct'] ?? '', ENT_QUOTES, 'UTF-8');
  ?>
  <tr height=33>
    <td colspan=4 height=33 class=xl270 style='border-right:.5pt solid black;height:24.75pt;width:307pt;padding-left:5px;'><?= $tTitle ?></td>
    <td class=xl94 style='border-top:none;border-left:none;padding-left:5px;'><?= $tFrom ?></td>
    <td class=xl94 style='border-top:none;border-left:none;padding-left:5px;'><center><?= $tTo ?></center></td>
    <td class=xl96 style='border-top:none;border-left:none;padding-left:5px;'><?= $tHours ?></td>
    <td class=xl95 style='border-top:none;border-left:none;padding-left:5px;'><?= $tType ?></td>
    <td colspan=3 class=xl97 style='border-right:1.0pt solid black;border-left:none;width:177pt;padding-left:5px;'><?= $tSponsor ?></td>
    <td></td><td></td>
  </tr>
  <?php endforeach; ?>
  <tr height=18>
    <td colspan=11 height=18 class=xl504 style='border-right:1.0pt solid black;height:13.5pt'>(Continue on separate sheet if necessary)</td>
    <td></td><td></td>
  </tr>

  <!-- VIII. OTHER INFORMATION -->
  <tr height=30>
    <td colspan=11 height=30 class=xl609 style='border-right:1.0pt solid black;height:22.5pt;width:703pt'>VIII. OTHER INFORMATION</td>
    <td></td><td></td>
  </tr>
  <tr height=45>
    <td height=45 class=xl211 style='height:33.75pt;border-top:none'>31.</td>
    <td class=xl218 width=215 style='border-top:none;width:161pt'>SPECIAL SKILLS and HOBBIES</td>
    <td class=xl212 width=23 style='border-top:none;width:17pt'>32.</td>
    <td colspan=5 class=xl218 style='border-right:.5pt solid black;width:328pt'>NON-ACADEMIC DISTINCTIONS / RECOGNITION<br>(Write in full)</td>
    <td class=xl212 width=21 style='border-top:none;border-left:none;width:16pt'>33.</td>
    <td colspan=2 class=xl218 style='border-right:1.0pt solid black;width:161pt'>MEMBERSHIP IN ASSOCIATION/ORGANIZATION<br>(Write in full)</td>
    <td></td><td></td>
  </tr>
  <?php
  // Other info rows — stored as flat fields or as rows array
  $oiSkills = [];
  if (!empty($oi['skills_rows']) && is_array($oi['skills_rows'])) {
      $oiSkills = padArr($oi['skills_rows'], 7);
  } else {
      // Fall back: single skill/recognition/member set or empty rows
      $oiSkills = padArr(
          (!empty($oi['skill']) || !empty($oi['recognitions']) || !empty($oi['memberships']))
              ? [['skill'=>$oi['skill']??'','nonacad'=>$oi['recognitions']??'','member'=>$oi['memberships']??'']]
              : [],
          7
      );
  }
  foreach ($oiSkills as $o):
    $skill   = htmlspecialchars($o['skill'] ?? '', ENT_QUOTES, 'UTF-8');
    $nonacad = htmlspecialchars($o['nonacad'] ?? $o['recognition'] ?? '', ENT_QUOTES, 'UTF-8');
    $member  = htmlspecialchars($o['member'] ?? $o['memberships'] ?? '', ENT_QUOTES, 'UTF-8');
  ?>
  <tr height=32>
    <td colspan=2 height=32 class=xl612 style='border-right:.5pt solid black;height:23.75pt;width:181pt'><?= $skill ?></td>
    <td colspan=6 class=xl606 style='border-right:.5pt solid black;border-left:none;width:345pt'><?= $nonacad ?></td>
    <td colspan=3 class=xl606 style='border-right:1.0pt solid black;border-left:none;width:177pt'><?= $member ?></td>
    <td></td><td></td>
  </tr>
  <?php endforeach; ?>
  <tr height=15>
    <td colspan=11 height=15 class=xl504 style='border-right:1.0pt solid black;height:11.25pt'>(Continue on separate sheet if necessary)</td>
    <td></td><td></td>
  </tr>
  <tr height=38>
    <td colspan=2 height=38 class=xl501 style='border-right:1.0pt solid black;height:28.5pt;width:181pt'>SIGNATURE</td>
    <td colspan=4 class=xl546 style='border-right:1.0pt solid black;border-left:none'>&nbsp;</td>
    <td colspan=2 class=xl619 style='border-left:none'>DATE</td>
    <td colspan=3 class=xl546 style='border-right:1.0pt solid black'>&nbsp;</td>
    <td class=xl324></td><td class=xl324></td>
  </tr>
  <tr height=13>
    <td height=13 colspan=11 style='height:9.75pt;'>
      <span style='mso-spacerun:yes'> </span>CS FORM 212 (Revised 2025), Page 3 of 4
    </td>
    <td></td><td></td>
  </tr>
</table>

<!-- Q&A + References (Page 4 continues) -->
<?php
// Helper to render a YES/NO question row using the working print's exact pattern
function qRow($num, $question, $answerKey, $q4, $detailKey = null, $detailVal = null, $extraRows = []) {
    $isYes = ($q4[$answerKey] ?? '') === 'YES';
    $isNo  = ($q4[$answerKey] ?? '') === 'NO';
    $yChk  = $isYes ? 'checked' : '';
    $nChk  = $isNo  ? 'checked' : '';
    echo "<tr height=24>";
    echo "<td colspan=2 height=24 class=xl293 style='height:18.0pt'>{$num}.</td>";
    echo "<td colspan=4 rowspan=4 class=xl637 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:351pt'>{$question}</td>";
    echo "<td align=left valign=top><input type=\"checkbox\" {$yChk}>YES</td>";
    echo "<td class=xl116 style='border-top:none'>&nbsp;</td><td class=xl116 style='border-top:none'>&nbsp;</td>";
    echo "<td align=left valign=top><input type=\"checkbox\" {$nChk}>NO</td>";
    echo "<td class=xl116 style='border-top:none'>&nbsp;</td><td class=xl116 style='border-top:none'>&nbsp;</td>";
    echo "<td class=xl117 style='border-top:none'>&nbsp;</td><td></td></tr>";
    echo "<tr height=19><td height=19 class=xl118>&nbsp;</td><td class=xl119>&nbsp;</td>";
    echo "<td colspan=6 class=xl623 style='border-left:none;width:213pt'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;If YES, give details:</td>";
    echo "<td class=xl196>&nbsp;</td><td></td></tr>";
    $detail = htmlspecialchars($detailVal ?? ($q4[$detailKey] ?? ''), ENT_QUOTES, 'UTF-8');
    echo "<tr height=19><td height=19 class=xl118>&nbsp;</td><td class=xl119>&nbsp;</td>";
    echo "<td class=xl110></td><td class=xl339>&nbsp;</td>";
    echo "<td colspan=4 class=xl625>{$detail}</td>";
    echo "<td class=xl196>&nbsp;</td><td></td></tr>";
    echo "<tr height=7><td height=7 class=xl118>&nbsp;</td><td class=xl119>&nbsp;</td>";
    echo "<td class=xl193 width=29>&nbsp;</td><td class=xl329 width=50>&nbsp;</td>";
    echo "<td colspan=4 class=xl626 style='width:156pt'>&nbsp;</td>";
    echo "<td class=xl194 width=15>&nbsp;</td><td></td></tr>";
    foreach ($extraRows as $r) echo $r;
}
?>
<table border=0 cellpadding=0 cellspacing=0 width=1000 style='border-collapse:collapse;table-layout:fixed;width:757pt'>
  <col width=12 span=2><col width=91><col width=166><col width=21><col width=189>
  <col width=26><col width=49><col width=21><col width=17><col width=52><col width=117><col width=14><col width=64>

  <tr height=6>
    <td colspan=13 height=4 class=xl642 style='border-right:1.0pt solid black;height:3.0pt;width:593pt'>&nbsp;</td>
    <td width=272></td>
  </tr>

  <!-- Q34: Related to appointing authority -->
  <tr height=20>
    <td colspan=2 height=18 class=xl651 style='height:13.5pt'>34.</td>
    <td colspan=4 class=xl647 style='border-right:.5pt solid black;width:351pt'>Are you related by consanguinity or affinity to the appointing or recommending authority, or to the</td>
    <td class=xl105>&nbsp;</td><td class=xl105>&nbsp;</td>
    <td colspan=2 class=xl645>&nbsp;</td><td class=xl106>&nbsp;</td><td class=xl106>&nbsp;</td><td class=xl107>&nbsp;</td><td></td>
  </tr>
  <tr height=17>
    <td height=17 class=xl108>&nbsp;</td><td class=xl109>&nbsp;</td>
    <td colspan=4 class=xl259 style='border-right:.5pt solid black;width:351pt'>chief of bureau or office or to the person who has immediate supervision over you in the Office,</td>
    <td class=xl110></td><td class=xl110></td>
    <td colspan=2 class=xl646></td><td class=xl111></td><td class=xl111></td><td class=xl112>&nbsp;</td><td></td>
  </tr>
  <tr height=16>
    <td height=16 class=xl108>&nbsp;</td><td class=xl109>&nbsp;</td>
    <td colspan=4 class=xl259 style='border-right:.5pt solid black;width:351pt'>Bureau or Department where you will be appointed,</td>
    <td></td><td></td><td></td><td></td><td></td><td></td><td class=xl66>&nbsp;</td><td></td>
  </tr>
  <!-- Q34a -->
  <tr height=19>
    <td height=19 class=xl108>&nbsp;</td><td class=xl109>&nbsp;</td>
    <td colspan=4 class=xl259 style='border-right:.5pt solid black;width:351pt'>a. within the third degree?</td>
    <td align=left valign=top>
      <input type="checkbox" <?= chk($q4['a1'] ?? '', 'YES') ?>>YES
    </td>
    <td></td>
    <td colspan=4 height=19 align=left valign=top>
      <input type="checkbox" <?= chk($q4['a1'] ?? '', 'NO') ?>>NO
    </td>
    <td class=xl66>&nbsp;</td><td></td>
  </tr>
  <tr height=4>
    <td height=4 class=xl108>&nbsp;</td><td class=xl109>&nbsp;</td>
    <td colspan=4 class=xl259 style='border-right:.5pt solid black;width:351pt'>&nbsp;</td>
    <td colspan=6 class=xl624 style='width:213pt'></td>
    <td class=xl66>&nbsp;</td><td></td>
  </tr>
  <tr height=17>
    <td height=17 class=xl108>&nbsp;</td><td class=xl109>&nbsp;</td>
    <td colspan=4 class=xl259 style='border-right:.5pt solid black;width:351pt'>&nbsp;</td>
    <td colspan=6 class=xl624 style='width:213pt'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;If YES, give details:</td>
    <td class=xl158>&nbsp;</td><td></td>
  </tr>
  <tr height=19>
    <td height=19 class=xl108>&nbsp;</td><td class=xl109>&nbsp;</td>
    <td colspan=4 class=xl259 style='border-right:.5pt solid black;width:351pt'>&nbsp;</td>
    <td class=xl219 width=29></td><td class=xl220 width=50>&nbsp;</td>
    <td colspan=4 class=xl653 style='width:156pt'><?= htmlspecialchars($q4['d2'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    <td class=xl158>&nbsp;</td><td></td>
  </tr>
  <!-- Q34b -->
  <tr height=21>
    <td height=21 class=xl108>&nbsp;</td><td class=xl109>&nbsp;</td>
    <td colspan=4 class=xl259 style='border-right:.5pt solid black;width:351pt'>b. within the fourth degree (for Local Government Unit - Career Employees)?</td>
    <td align=left valign=top>
      <input type="checkbox" <?= chk($q4['a2'] ?? '', 'YES') ?>>YES
    </td>
    <td></td>
    <td colspan=4 align=left valign=top>
      <input type="checkbox" <?= chk($q4['a2'] ?? '', 'NO') ?>>NO
    </td>
    <td class=xl113>&nbsp;</td><td></td>
  </tr>

  <!-- Q35a: Administrative offense -->
  <?php qRow(35, 'a. Have you ever been found guilty of any administrative offense?', 'a3', $q4, 'd3'); ?>

  <!-- Q35b: Criminal charge -->
  <tr height=17>
    <td height=17 class=xl120>&nbsp;</td><td class=xl121>&nbsp;</td>
    <td colspan=4 rowspan=5 class=xl742 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:351pt'>b. Have you been criminally charged before any court?</td>
    <td class=xl125 width=29></td><td class=xl125 width=50></td><td class=xl125 width=21></td>
    <td class=xl125 width=19></td><td class=xl125 width=54></td><td class=xl125 width=117></td>
    <td class=xl126>&nbsp;</td><td></td>
  </tr>
  <tr height=19>
    <td height=19 class=xl120>&nbsp;</td><td class=xl121>&nbsp;</td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a4'] ?? '', 'YES') ?>>YES</td>
    <td></td><td></td><td></td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a4'] ?? '', 'NO') ?>>NO</td>
    <td></td><td class=xl180>&nbsp;</td><td></td>
  </tr>
  <tr height=19>
    <td height=19 class=xl120>&nbsp;</td><td class=xl121>&nbsp;</td>
    <td colspan=6 class=xl623 style='border-left:none;width:213pt'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;If YES, give details:</td>
    <td class=xl126>&nbsp;</td><td></td>
  </tr>
  <tr height=19>
    <td height=19 class=xl120>&nbsp;</td><td class=xl121>&nbsp;</td>
    <td class=xl125 width=29></td>
    <td colspan=2 class=xl720>Date Filed:</td>
    <td class=xl340 width=19></td><td class=xl341 width=54>&nbsp;</td>
    <td class=xl198 width=117><?= htmlspecialchars($q4['d4'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    <td class=xl126>&nbsp;</td><td></td>
  </tr>
  <tr height=19>
    <td height=19 class=xl120>&nbsp;</td><td class=xl121>&nbsp;</td>
    <td colspan=3 class=xl721 style='border-left:none'>Status of Case/s:</td>
    <td class=xl340 width=19></td><td class=xl342 width=54>&nbsp;</td>
    <td class=xl198 width=117><?= htmlspecialchars($q4['s4'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    <td class=xl126>&nbsp;</td><td></td>
  </tr>

  <!-- Q36: Convicted of crime -->
  <?php qRow(36, 'Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?', 'a5', $q4, 'd5'); ?>

  <!-- Q37: Separated from service -->
  <?php qRow(37, 'Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?', 'a6', $q4, 'd6'); ?>

  <!-- Q38a: Candidate in election -->
  <tr height=24>
    <td colspan=2 height=24 class=xl293 style='height:18.0pt'>38.</td>
    <td colspan=4 rowspan=2 class=xl637 style='border-right:.5pt solid black;width:351pt'>a. Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?</td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a7'] ?? '', 'YES') ?>>YES</td>
    <td class=xl116 style='border-top:none'>&nbsp;</td><td class=xl116 style='border-top:none'>&nbsp;</td><td class=xl116 style='border-top:none'>&nbsp;</td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a7'] ?? '', 'NO') ?>>NO</td>
    <td class=xl116 style='border-top:none'>&nbsp;</td><td class=xl117 style='border-top:none'>&nbsp;</td><td></td>
  </tr>
  <tr height=16>
    <td height=16 class=xl118>&nbsp;</td><td class=xl119>&nbsp;</td>
    <td colspan=4 class=xl676 style='border-left:none'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;If YES, give details:</td>
    <td colspan=2 class=xl654><?= htmlspecialchars($q4['d7'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    <td class=xl192>&nbsp;</td><td></td>
  </tr>
  <!-- Q38b: Resigned to campaign -->
  <tr height=19>
    <td height=19 class=xl118>&nbsp;</td><td class=xl119>&nbsp;</td>
    <td colspan=4 rowspan=3 class=xl262 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:351pt'>b. Have you resigned from the government service during the three (3)-month period before the last election to promote/actively campaign for a national or local candidate?</td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a8'] ?? '', 'YES') ?>>YES</td>
    <td class=xl138></td><td class=xl186></td><td class=xl186></td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a8'] ?? '', 'NO') ?>>NO</td>
    <td class=xl264></td><td class=xl192>&nbsp;</td><td></td>
  </tr>
  <tr height=19>
    <td height=19 class=xl118>&nbsp;</td><td class=xl119>&nbsp;</td>
    <td colspan=4 class=xl676 style='border-left:none'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;If YES, give details:</td>
    <td colspan=2 class=xl654><?= htmlspecialchars($q4['d8'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    <td class=xl192>&nbsp;</td><td></td>
  </tr>
  <tr height=6>
    <td height=6 class=xl129>&nbsp;</td><td class=xl130>&nbsp;</td>
    <td class=xl195>&nbsp;</td><td class=xl138></td><td class=xl186></td>
    <td class=xl186></td><td></td><td class=xl190></td><td class=xl194>&nbsp;</td><td></td>
  </tr>

  <!-- Q39: Immigrant/permanent resident -->
  <?php qRow(39, 'Have you acquired the status of an immigrant or permanent resident of another country?', 'a9', $q4, 'd9'); ?>

  <!-- Q40: Special laws (IP, PWD, Solo Parent) -->
  <tr height=38>
    <td colspan=2 height=38 class=xl627 style='height:28.5pt'>40.</td>
    <td colspan=4 rowspan=2 class=xl262 style='border-right:.5pt solid black;width:351pt'>Pursuant to: (a) Indigenous People's Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277); and (c) Solo Parents Welfare Act of 2000 (RA 8972), please answer the following items:</td>
    <td class=xl116>&nbsp;</td><td class=xl116>&nbsp;</td><td class=xl133>&nbsp;</td><td class=xl133>&nbsp;</td>
    <td class=xl134>&nbsp;</td><td class=xl134>&nbsp;</td><td class=xl135>&nbsp;</td><td></td>
  </tr>
  <tr height=1>
    <td height=1 class=xl131>&nbsp;</td><td class=xl132>&nbsp;</td>
    <td class=xl136></td><td class=xl136></td><td class=xl136></td><td class=xl136></td>
    <td class=xl136></td><td class=xl136></td><td class=xl137>&nbsp;</td><td></td>
  </tr>
  <!-- Q40a: Indigenous group -->
  <tr height=18>
    <td height=18 class=xl131>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;a.</td><td class=xl132>&nbsp;</td>
    <td colspan=4 rowspan=2 class=xl262 style='border-right:.5pt solid black;width:351pt'>Are you a member of any indigenous group?</td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a9'] ?? '', 'YES') ?>>YES</td>
    <td></td><td></td><td></td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a9'] ?? '', 'NO') ?>>NO</td>
    <td></td><td class=xl66>&nbsp;</td><td></td>
  </tr>
  <tr height=20>
    <td height=20 class=xl131>&nbsp;</td><td class=xl132>&nbsp;</td>
    <td colspan=5 style='mso-ignore:colspan'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;If YES, please specify:</td>
    <td class=xl190><?= htmlspecialchars($q4['d9'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    <td class=xl187>&nbsp;</td><td class=xl138></td>
  </tr>
  <!-- Q40b: PWD -->
  <tr height=18>
    <td height=18 class=xl131>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;b.</td><td class=xl132>&nbsp;</td>
    <td colspan=4 rowspan=2 class=xl261 style='width:351pt'>Are you a person with disability?</td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a10'] ?? '', 'YES') ?>>YES</td>
    <td></td><td></td><td></td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a10'] ?? '', 'NO') ?>>NO</td>
    <td></td><td class=xl66>&nbsp;</td><td></td>
  </tr>
  <tr height=20>
    <td height=20 class=xl131>&nbsp;</td><td class=xl132>&nbsp;</td>
    <td colspan=5 style='mso-ignore:colspan'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;If YES, please specify ID No:</td>
    <td class=xl190><?= htmlspecialchars($q4['d10'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    <td class=xl187>&nbsp;</td><td class=xl138></td>
  </tr>
  <!-- Q40c: Solo parent -->
  <tr height=18>
    <td height=18 class=xl131>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;c.</td><td class=xl132>&nbsp;</td>
    <td colspan=2 class=xl262 style='width:193pt'>Are you a solo parent?</td>
    <td class=xl183>&nbsp;</td><td class=xl184>&nbsp;</td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a11'] ?? '', 'YES') ?>>YES</td>
    <td></td><td></td><td></td>
    <td align=left valign=top><input type="checkbox" <?= chk($q4['a11'] ?? '', 'NO') ?>>NO</td>
    <td></td><td class=xl66>&nbsp;</td><td></td>
  </tr>
  <tr height=16>
    <td colspan=6 rowspan=2 height=25 class=xl666 style='border-right:.5pt solid black;border-bottom:1.0pt solid black;height:18.75pt;width:369pt'>&nbsp;</td>
    <td class=xl269 colspan=5 style='mso-ignore:colspan'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;If YES, please specify ID No:</td>
    <td class=xl190><?= htmlspecialchars($q4['d11'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    <td class=xl189>&nbsp;</td><td class=xl138></td>
  </tr>
  <tr height=9>
    <td height=9 class=xl185>&nbsp;</td><td class=xl181>&nbsp;</td><td class=xl181>&nbsp;</td>
    <td class=xl181>&nbsp;</td><td class=xl181>&nbsp;</td><td class=xl181>&nbsp;</td>
    <td class=xl182>&nbsp;</td><td class=xl138></td>
  </tr>

  <!-- 41. REFERENCES -->
  <tr height=31>
    <td colspan=2 height=31 class=xl739 style='height:23.25pt'>41.</td>
    <td class=xl265 colspan=4 style='mso-ignore:colspan'>REFERENCES <font class="font18">(Person not related by consanguinity or affinity to applicant/appointee)</font></td>
    <td class=xl265 style='border-top:none'>&nbsp;</td>
    <td class=xl265 style='border-top:none'>&nbsp;</td>
    <td class=xl266 style='border-top:none'>&nbsp;</td>
    <td colspan=4>&nbsp;</td>
    <td></td>
  </tr>
  <tr height=20>
    <td height=20 class=xl651>&nbsp;</td>
    <td colspan=5 class=xl741 style='border-right:.5pt solid black;border-bottom:.5pt solid black;width:369pt'>NAME<br>(Surname, First Name, MI)</td>
    <td class=xl271 style='border-top:none'>&nbsp;</td>
    <td class=xl272 style='border-top:none'>&nbsp;</td>
    <td class=xl272 style='border-top:none'>&nbsp;</td>
    <td colspan=3 class=xl732 style='border-right:.5pt solid black;border-bottom:.5pt solid black'>ADDRESS</td>
    <td class=xl734 style='border-bottom:.5pt solid black'>CONTACT NO. AND/OR EMAIL</td>
    <td></td>
  </tr>
  <?php
  // References stored as array: [{name, address, tel}, ...]
  // or as flat fields: ref1_name, ref1_address, ref1_tel etc.
  $ref1 = $refs[0] ?? [];
  $ref2 = $refs[1] ?? [];
  $ref3 = $refs[2] ?? [];
  function refRow($cls1, $cls2, $cls3, $r) {
      $name = htmlspecialchars($r['name'] ?? $r['refname1'] ?? '', ENT_QUOTES, 'UTF-8');
      $addr = htmlspecialchars($r['address'] ?? $r['refadd1'] ?? '', ENT_QUOTES, 'UTF-8');
      $tel  = htmlspecialchars($r['tel'] ?? $r['reftel1'] ?? '', ENT_QUOTES, 'UTF-8');
      echo "<tr height=40>";
      echo "<td colspan=5 height=40 class={$cls1} style='border-right:.5pt solid black;height:24.0pt;width:227pt'>{$name}</td>";
      echo "<td class={$cls2} width=189 style='border-top:none;border-left:none;width:142pt'>{$addr}</td>";
      echo "<td colspan=3 class={$cls3} style='border-right:1.0pt solid black;width:73pt'>{$tel}</td>";
      echo "<td></td></tr>";
  }
  refRow('xl689','xl139','xl673', $ref1);
  refRow('xl689','xl139','xl673', $ref2);
  refRow('xl685','xl277','xl660', $ref3);
  ?>

  <!-- 42. Declaration -->
  <tr height=53>
    <td colspan=2 height=53 class=xl651 style='height:39.75pt'>42.</td>
    <td colspan=6 rowspan=3 class=xl694 style='width:408pt'>I declare under oath that I have personally accomplished this Personal Data Sheet which is a true, correct and complete statement pursuant to the provisions of pertinent laws, rules and regulations of the Republic of the Philippines. I authorize the agency head/authorized representative to verify/validate the contents stated herein. I agree that any misrepresentation made in this document and its attachments shall cause the filing of administrative/criminal case/s against me.</td>
    <td class=xl335>&nbsp;</td><td></td>
  </tr>
  <tr height=18>
    <td height=18 class=xl140>&nbsp;</td><td class=xl141>&nbsp;</td>
    <td class=xl336>&nbsp;</td><td class=xl276></td>
    <td colspan=2 class=xl634>PHOTO</td>
  </tr>
  <tr height=18>
    <td height=18 class=xl140>&nbsp;</td><td class=xl141>&nbsp;</td>
    <td class=xl336>&nbsp;</td><td></td>
    <td colspan=2 class=xl634>&nbsp;</td>
  </tr>
  <tr height=17>
    <td height=17 class=xl142>&nbsp;</td><td></td>
    <td colspan=4 class=xl708 style='border-right:1.0pt solid black;width:215pt'>Date Accomplished</td>
    <td class=xl145></td>
    <td colspan=2 class=xl706 style='border-right:1.0pt solid black'>Right Thumbmark</td>
    <td class=xl143>&nbsp;</td><td></td>
  </tr>
  <tr height=71>
    <td height=71 class=xl142>&nbsp;</td><td class=xl145></td>
    <td class=xl152></td><td class=xl152></td>
    <td colspan=5 class=xl700 style='border-right:1.0pt solid black'>&nbsp;</td>
    <td class=xl201>&nbsp;</td><td class=xl201>&nbsp;</td>
    <td></td><td class=xl143>&nbsp;</td><td></td>
  </tr>
  <tr height=24>
    <td height=24 class=xl142>&nbsp;</td><td class=xl145></td>
    <td class=xl319></td><td class=xl319></td>
    <td colspan=5 class=xl697 style='border-right:1.0pt solid black;width:231pt'>Person Administering Oath</td>
    <td class=xl203>&nbsp;</td><td class=xl202>&nbsp;</td>
    <td class=xl153></td><td class=xl143>&nbsp;</td><td></td>
  </tr>
  <tr class=xl157 height=15>
    <td height=15 class=xl154>&nbsp;</td><td class=xl155>&nbsp;</td><td class=xl155>&nbsp;</td>
    <td class=xl155>&nbsp;</td><td class=xl155>&nbsp;</td><td class=xl155>&nbsp;</td>
    <td class=xl155>&nbsp;</td><td class=xl155>&nbsp;</td><td class=xl155>&nbsp;</td>
    <td class=xl155>&nbsp;</td><td class=xl155>&nbsp;</td><td class=xl155>&nbsp;</td>
    <td class=xl156>&nbsp;</td><td class=xl157></td>
  </tr>
  <tr height=14>
    <td colspan=13 height=14 class=xl696 style='height:10.5pt'>CS FORM 212 (Revised 2025), Page 4 of 4</td>
    <td></td>
  </tr>
</table>

</section>
</div><!-- end page 4 -->

</body>
</html>
