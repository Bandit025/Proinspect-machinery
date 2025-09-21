<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: expenses.php?error=' . urlencode('วิธีการเรียกไม่ถูกต้อง')); exit;
}
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  header('Location: expenses.php?error=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
}

/** สร้าง URL สำหรับย้อนกลับ โดย “คงไว้” query เดิมถ้ามี */
function back_url(): string {
  foreach (['return' => $_POST['return'] ?? null, 'referer' => $_SERVER['HTTP_REFERER'] ?? null] as $src => $val) {
    if (!$val) continue;
    $u = parse_url($val);
    if (!empty($u['path'])) {
      $path = $u['path'];
      $qs   = $u['query'] ?? '';
      return $qs ? ($path . '?' . $qs) : $path;
    }
  }
  // fallback
  $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/expenses.php';
  return $base;
}

/** รวมพารามิเตอร์เดิม + พารามิเตอร์ใหม่ แล้ว redirect */
function redirect_with(string $url, array $params): never {
  $parts = parse_url($url);
  $base  = $parts['path'] ?? $url;
  parse_str($parts['query'] ?? '', $existing);
  $query = array_merge($existing, $params);
  header('Location: ' . $base . (empty($query) ? '' : '?' . http_build_query($query)));
  exit;
}

$back = back_url();
$id   = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id || $id <= 0) {
  redirect_with($back, ['error' => 'พารามิเตอร์ไม่ถูกต้อง']);
}

try {
  // แนะนำให้เปิด ERRMODE_EXCEPTION ไว้ใน config.php อยู่แล้ว
  $pdo->beginTransaction();

  // ตรวจว่ามีรายการนี้จริงก่อน (ล็อกแถวกันลบซ้อน)
  $chk = $pdo->prepare('SELECT expense_id FROM machine_expenses WHERE expense_id = ? FOR UPDATE');
  $chk->execute([$id]);
  if (!$chk->fetchColumn()) {
    $pdo->rollBack();
    redirect_with($back, ['error' => 'ไม่พบรายการที่ต้องการลบ']);
  }

$sql_cashflow = "DELETE FROM cashflow WHERE expense_id=?";
$stm = $pdo->prepare($sql_cashflow);
$stm->execute([$id]);

// echo "ลบ cashflow ออกแล้ว";
// echo "ลบค่าใช้จ่ายออกแล้ว";
//   exit();
  // ลบจริง
  $del = $pdo->prepare('DELETE FROM machine_expenses WHERE expense_id = ? LIMIT 1');
  $del->execute([$id]);

  $pdo->commit();

  if ($del->rowCount() > 0) {
    redirect_with($back, ['ok' => 'ลบค่าใช้จ่ายเรียบร้อย']);
  }
  // กรณีแถวถูกลบไปแล้วโดยคนอื่น
  redirect_with($back, ['error' => 'ไม่พบแถวสำหรับลบ หรือถูกลบไปแล้ว']);
} catch (PDOException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();

  // จับกรณี Foreign Key constraint (MySQL รหัส 1451)
  $driverCode = (int)($e->errorInfo[1] ?? 0);
  if ($e->getCode() === '23000' || $driverCode === 1451) {
    redirect_with($back, ['error' => 'ลบไม่ได้: รายการถูกอ้างอิงอยู่ในที่อื่น']);
  }

  // ข้อผิดพลาดอื่น ๆ
  redirect_with($back, ['error' => 'เกิดข้อผิดพลาดระหว่างลบ: ' . $e->getMessage()]);
}
