<?php
/** sales_delete.php */
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: sales.php?error=' . urlencode('วิธีเรียกไม่ถูกต้อง'));
  exit;
}

if (empty($_SESSION['csrf']) || empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
  header('Location: sales.php?error=' . urlencode('CSRF token ไม่ถูกต้อง'));
  exit;
}

$sale_id = (int)($_POST['sale_id'] ?? 0);
if ($sale_id <= 0) {
  header('Location: sales.php?error=' . urlencode('ไม่พบเลขที่เอกสารขาย'));
  exit;
}

try {
  $pdo->beginTransaction();

  // หาเครื่องที่เกี่ยวข้อง
  $stm = $pdo->prepare("SELECT machine_id FROM sales WHERE sale_id=? LIMIT 1");
  $stm->execute([$sale_id]);
  $machine_id = (int)$stm->fetchColumn();
  if (!$machine_id) {
    throw new Exception('ไม่พบรายการขาย');
  }

  // ลบ cashflow ที่อ้างถึง sale นี้
  $pdo->prepare("DELETE FROM cashflow WHERE sale_id=?")->execute([$sale_id]);

  // ลบเอกสารขาย
  $pdo->prepare("DELETE FROM sales WHERE sale_id=?")->execute([$sale_id]);

  // คืนสถานะเครื่องเป็นพร้อมขาย (2) เฉพาะถ้าเดิมเป็นขายแล้ว (3)
  $pdo->prepare("UPDATE machines SET status=2 WHERE machine_id=? AND status=3")->execute([$machine_id]);

  $pdo->commit();
  header('Location: sales.php?ok=' . urlencode('ลบเอกสารขายเรียบร้อย'));
  exit;
} catch (Exception $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: sales.php?error=' . urlencode('ลบไม่สำเร็จ: ' . $e->getMessage()));
  exit;
}
