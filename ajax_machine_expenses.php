<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ob_start();

try {
    // ถ้ามีระบบล็อกอิน ให้เปิดใช้ตามต้องการ
    // if (empty($_SESSION['user'])) { http_response_code(401); ob_clean(); echo json_encode(['ok'=>false,'error'=>'Unauthorized'], JSON_UNESCAPED_UNICODE); exit; }

    $mid = (int)($_GET['machine_id'] ?? $_POST['machine_id'] ?? 0);
    if ($mid <= 0) {
        ob_clean();
        echo json_encode(['ok' => true, 'rows' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ดึงรายการค่าใช้จ่าย (alias ชื่อคอลัมน์ให้ตรงกับที่หน้าเว็บใช้)
    $stm = $pdo->prepare("
        SELECT
            me.expense_id,
            DATE(me.occurred_at)                                   AS expense_date,  -- วันที่เกิด
            me.category,
            me.description,
            me.qty,
            me.unit_cost                                           AS unit_price,    -- ราคา/หน่วย
            COALESCE(me.total_cost, me.expenses, me.qty*me.unit_cost) AS line_total  -- รวม
        FROM machine_expenses me
        WHERE me.machine_id = :mid
        ORDER BY me.occurred_at DESC, me.expense_id DESC
        LIMIT 500
    ");
    $stm->execute([':mid' => $mid]);
    $rows = $stm->fetchAll(PDO::FETCH_ASSOC);

    // map ประเภท (กำหนดตามรหัสที่คุณใช้จริง)
    $catMap = [
        '2' => 'บำรุงรักษา',
        '3' => 'อะไหล่',
        '9' => 'ค่าแรง',
        'repair' => 'บำรุงรักษา',
        'maintenance' => 'บำรุงรักษา',
        'parts' => 'อะไหล่',
        'labor' => 'ค่าแรง',
        'transport' => 'ขนส่ง',
        'registration' => 'จดทะเบียน',
        'inspection' => 'ตรวจสภาพ',
        'brokerage' => 'ค่านายหน้า',
        'selling' => 'ค่าใช้จ่ายการขาย',
        'other' => 'อื่น ๆ',
    ];

    $out = [];
    $sum = 0.0;

    foreach ($rows as $r) {
        $qty   = isset($r['qty']) ? (float)$r['qty'] : 1.0;
        $unit  = isset($r['unit_price']) ? (float)$r['unit_price'] : 0.0;
        $total = isset($r['line_total']) && $r['line_total'] !== null ? (float)$r['line_total'] : $qty * $unit;
        $sum  += $total;

        $cat = (string)($r['category'] ?? '');

        $out[] = [
            'expense_id'   => (int)$r['expense_id'],
            'expense_date' => $r['expense_date'] ?? '',                 // << ส่งออกให้หน้าเว็บใช้
            'category'     => $cat,
            'category_th'  => $catMap[$cat] ?? $cat,
            'description'  => (string)($r['description'] ?? ''),
            'qty'          => $qty,
            'unit_price'   => $unit,                                     // << ชื่อคีย์ถูกต้อง
            'line_total'   => $total,                                    // << ชื่อคีย์ถูกต้อง
        ];
    }

    ob_clean();
    echo json_encode(['ok' => true, 'rows' => $out, 'sum_total' => $sum], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
