<?php
declare(strict_types=1);

ini_set('display_errors','0');
error_reporting(E_ALL);
ob_start();
date_default_timezone_set('Asia/Bangkok');

require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
    header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
    exit;
}

/* ----- Composer autoload (PhpSpreadsheet) ----- */
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo "ไม่พบ vendor/autoload.php\nโปรดติดตั้ง: composer require phpoffice/phpspreadsheet";
    exit;
}
require $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/* ---------- รับ/ตรวจสอบพารามิเตอร์วันที่ ---------- */
$d1 = trim($_GET['d1'] ?? '');
$d2 = trim($_GET['d2'] ?? '');

$validateYmd = function (string $s) : string {
    if ($s === '') return '';
    $dt = DateTime::createFromFormat('Y-m-d', $s);
    return ($dt && $dt->format('Y-m-d') === $s) ? $s : '';
};
$d1 = $validateYmd($d1);
$d2 = $validateYmd($d2);

/* ค่าเริ่มต้นเหมือนหน้า list: ถ้าไม่ส่ง d1,d2 -> เดือนปัจจุบัน */
if ($d1 === '' && $d2 === '') {
    $d1 = date('Y-m-01');   // วันแรกของเดือนนี้
    $d2 = date('Y-m-t');    // วันสุดท้ายของเดือนนี้
}

/* กติกาเดียวกับหน้า list */
if ($d1 && !$d2) $d2 = $d1;
if ($d1 && $d2 && $d2 < $d1) $d1 = $d2;

/* half-open สำหรับขอบบน */
$d2p = $d2 ? date('Y-m-d', strtotime($d2 . ' +1 day')) : '';

/* ---------- WHERE & PARAMS (อิง created_at ทั้งหมด) ---------- */
$where  = [];
$params = [];
if ($d1)  { $where[] = 'a.created_at >= :d1';  $params[':d1']  = $d1; }
if ($d2p) { $where[] = 'a.created_at < :d2p';  $params[':d2p'] = $d2p; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ---------- ดึงข้อมูลให้ตรงกับหน้าจอ ---------- */
$sql = "
  SELECT a.cashflow_id, a.doc_date, a.created_at, a.type_cashflow, a.amount, a.doc_no, a.remark,
         b.type_name AS type2_name,
         c.code      AS machine_code,
         d.model_name,
         e.brand_name
  FROM cashflow a
  LEFT JOIN type2_cashflow b ON a.type2_cashflow = b.type_id
  LEFT JOIN machines        c ON a.machine_id     = c.machine_id
  LEFT JOIN models          d ON c.model_id       = d.model_id
  LEFT JOIN brands          e ON d.brand_id       = e.brand_id
  {$whereSql}
  ORDER BY a.created_at DESC, a.cashflow_id DESC
";
$stm = $pdo->prepare($sql);
$stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);

/* ---------- สร้าง Spreadsheet ---------- */
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet()->setTitle('Cashflow');

$sheet->setCellValue('A1', 'รายงานกระแสเงินสด (Cashflow)');
$filterText = [];
if ($d1) $filterText[] = "จาก {$d1}";
if ($d2) $filterText[] = "ถึง {$d2}";
$sheet->setCellValue('A2', $filterText ? ('ตัวกรอง (created_at): ' . implode(' / ', $filterText)) : 'ตัวกรอง: ทั้งหมด');

$headerRow = 4;
$headers = ['วันที่', 'ประเภท', 'ประเภทย่อย', 'เลขเอกสาร', 'รถ', 'จำนวนเงิน', 'หมายเหตุ'];
$cols = range('A', 'G');
foreach ($headers as $i => $h) {
    $sheet->setCellValue($cols[$i] . $headerRow, $h);
}

/* header style */
$sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFont()->setBold(true);
$sheet->getStyle("A{$headerRow}:G{$headerRow}")
      ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFEFEF');
$sheet->getStyle("A1")->getFont()->setBold(true)->setSize(14);
$sheet->freezePane('A' . ($headerRow + 1));

/* เติมข้อมูล + สะสมยอด */
$r = $headerRow + 1;
$totalIncome  = 0.0;
$totalExpense = 0.0;

foreach ($rows as $row) {
    // แสดงผลวันที่: มี doc_date ใช้ doc_date, ไม่มีก็ใช้ created_at
    $dateStr = $row['doc_date'] ?: $row['created_at'];
    $typeStr = ((int)$row['type_cashflow'] === 1) ? 'รายได้' : 'รายจ่าย';
    $signedAmount = ((int)$row['type_cashflow'] === 1) ? (float)$row['amount'] : -(float)$row['amount'];

    if ((int)$row['type_cashflow'] === 1) {
        $totalIncome  += (float)$row['amount'];
    } else {
        $totalExpense += (float)$row['amount'];
    }

    $machineCode = (string)($row['machine_code'] ?? '');
    $brandName   = (string)($row['brand_name']   ?? '');
    $modelName   = (string)($row['model_name']   ?? '');
    $machine     = $machineCode !== '' ? $machineCode : '-';
    $bm = trim($brandName . ' ' . $modelName);
    if ($bm !== '') $machine .= ' ' . $bm;

    $sheet->setCellValue("A{$r}", $dateStr);
    $sheet->setCellValue("B{$r}", $typeStr);
    $sheet->setCellValue("C{$r}", $row['type2_name'] ?? '-');
    $sheet->setCellValue("D{$r}", $row['doc_no'] ?: '-');
    $sheet->setCellValue("E{$r}", $machine);
    $sheet->setCellValue("F{$r}", $signedAmount);
    $sheet->setCellValue("G{$r}", $row['remark'] ?? '');
    $r++;
}

/* สรุปยอด (แถว 3) */
$sheet->setCellValue('A3', 'รวมรายได้');
$sheet->setCellValue('B3', $totalIncome);
$sheet->setCellValue('C3', 'รวมรายจ่าย');
$sheet->setCellValue('D3', $totalExpense);

/* รูปแบบตัวเลข */
$sheet->getStyle('B3:D3')->getNumberFormat()
      ->setFormatCode('[$฿-409]#,##0.00;[Red]-[$฿-409]#,##0.00');
$sheet->getStyle('A3:C3')->getFont()->setBold(true);

$sheet->getStyle("F" . ($headerRow + 1) . ":F" . ($r - 1))
      ->getNumberFormat()->setFormatCode('[$฿-409]#,##0.00;[Red]-[$฿-409]#,##0.00');

/* ปรับความกว้าง */
foreach (range('A', 'G') as $c) {
    $sheet->getColumnDimension($c)->setAutoSize(true);
}

/* ---------- ส่งออก ---------- */
$file = 'cashflow';
if ($d1 || $d2) $file .= '_' . ($d1 ?: 'start') . '_' . ($d2 ?: 'end');
$file .= '.xlsx';

if (ob_get_length()) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$file.'"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
