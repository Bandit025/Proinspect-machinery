<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0'); // กัน error HTML โผล่ใน output
ob_start();

try {
  // ถ้ามีระบบล็อกอิน ให้ตรวจที่นี่ (และตอบเป็น JSON)
  if (empty($_SESSION['user'])) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['ok'=>false,'error'=>'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $mid = (int)($_GET['machine_id'] ?? 0);
  if ($mid <= 0) {
    ob_clean();
    echo json_encode(['ok'=>true,'rows'=>[]], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $sql = "SELECT 
            a.acquisition_id, a.doc_no, a.acquired_at, a.type_buy,
            a.base_price, a.vat_amount, a.total_amount, a.remark,
            m.code, b.model_name, c.brand_name, s.supplier_name
          FROM acquisitions a
          JOIN machines  m ON m.machine_id  = a.machine_id
          JOIN models    b ON b.model_id    = m.model_id
          JOIN brands    c ON b.brand_id    = c.brand_id
          JOIN suppliers s ON s.supplier_id = a.supplier_id
          WHERE a.machine_id = ?
          ORDER BY a.acquired_at DESC, a.acquisition_id DESC
          LIMIT 50";
  $stm = $pdo->prepare($sql);
  $stm->execute([$mid]);
  $rows = $stm->fetchAll(PDO::FETCH_ASSOC);

  ob_clean(); // ล้าง output แปลกปลอมทั้งหมดก่อนส่ง JSON
  echo json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  ob_clean();
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
