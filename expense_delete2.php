<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: expenses.php?error=' . urlencode('วิธีการเรียกไม่ถูกต้อง')); exit;
}

if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  header('Location: expenses.php?error=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
}

$id   = (int)($_POST['id'] ?? 0);
$ajax = (isset($_POST['ajax']) && $_POST['ajax'] === '1');

if ($id <= 0) {
  if ($ajax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'พารามิเตอร์ไม่ถูกต้อง']); exit; }
  header('Location: expenses.php?error=' . urlencode('พารามิเตอร์ไม่ถูกต้อง')); exit;
}

// try {
//   $pdo->beginTransaction();

  // ลบ cashflow ที่อ้างถึงรายการค่าใช้จ่ายนี้ (ถ้ามี)
  $delCF = $pdo->prepare('DELETE FROM cashflow WHERE expense_id = ?');
  $delCF->execute([$id]);

  // ลบรายการค่าใช้จ่ายหลัก
  $delME = $pdo->prepare('DELETE FROM machine_expenses WHERE expense_id = ?');
  $delME->execute([$id]);

  if ($delME->rowCount() < 1) {
    throw new RuntimeException('ไม่พบรายการค่าใช้จ่ายที่ต้องการลบ');
  }

  $pdo->commit();

  if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
  }

  header('Location: expenses.php?ok=' . urlencode('ลบค่าใช้จ่ายเรียบร้อย')); 
  exit;

// } catch (Throwable $e) {
//   if ($pdo->inTransaction()) $pdo->rollBack();

//   if ($ajax) {
//     header('Content-Type: application/json; charset=utf-8');
//     echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
//     exit;
//   }

//   header('Location: expenses.php?error=' . urlencode('ลบไม่สำเร็จ: ' . $e->getMessage()));
//   exit;
// }
