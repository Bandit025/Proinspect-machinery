<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user'])) {
  echo json_encode(['ok'=>false,'error'=>'กรุณาเข้าสู่ระบบ']); exit;
}
$isAdmin = ((int)($_SESSION['user']['role'] ?? 1) === 2);
// ถ้าต้องการจำกัดสิทธิ์เฉพาะแอดมินในการเปลี่ยนสถานะ ให้เปิดบรรทัดต่อไป:
// if (!$isAdmin) { echo json_encode(['ok'=>false,'error'=>'จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok'=>false,'error'=>'วิธีเรียกไม่ถูกต้อง']); exit;
}
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  echo json_encode(['ok'=>false,'error'=>'CSRF ไม่ถูกต้อง']); exit;
}

$machine_id = (int)($_POST['id'] ?? 0);
$status     = (int)($_POST['status'] ?? 0);
if ($machine_id<=0) { echo json_encode(['ok'=>false,'error'=>'ไม่พบรหัสรถ']); exit; }
if ($status<1 || $status>5) { echo json_encode(['ok'=>false,'error'=>'สถานะไม่ถูกต้อง']); exit; }

function status_label_th($x){ return [1=>'รับเข้า',2=>'พร้อมขาย',3=>'จอง',4=>'ขายแล้ว',5=>'ซ่อมบำรุง'][$x] ?? 'รับเข้า'; }

try {
  // ตรวจว่ารถมีจริง + สถานะเดิม
  $stm = $pdo->prepare("SELECT status FROM machines WHERE machine_id=? LIMIT 1");
  $stm->execute([$machine_id]);
  $cur = $stm->fetch(PDO::FETCH_ASSOC);
  if (!$cur) { echo json_encode(['ok'=>false,'error'=>'ไม่พบรถที่เลือก']); exit; }
  $oldStatus = (int)$cur['status'];

  $user_changed = (function() {
    if (function_exists('current_fullname')) return current_fullname();
    $fn = trim((string)($_SESSION['user']['f_name'] ?? ''));
    $ln = trim((string)($_SESSION['user']['l_name'] ?? ''));
    $nm = trim($fn.' '.$ln);
    return $nm !== '' ? $nm : (string)($_SESSION['user']['email'] ?? 'ผู้ใช้');
  })();

  $pdo->beginTransaction();

  // อัปเดตสถานะปัจจุบันใน machines
  $upd = $pdo->prepare("UPDATE machines SET status=?, updated_at=NOW() WHERE machine_id=?");
  $upd->execute([$status, $machine_id]);

  // บันทึกประวัติ
  $note = "เปลี่ยนจาก '".status_label_th($oldStatus)."' เป็น '".status_label_th($status)."'";
  $ins = $pdo->prepare("INSERT INTO machine_status_history (machine_id,status,changed_at,user_changed,note)
                        VALUES (?,?,?,?,?)");
  $ins->execute([$machine_id,$status,date('Y-m-d H:i:s'),$user_changed,$note]);

  $pdo->commit();

  echo json_encode(['ok'=>true,'label'=>status_label_th($status)]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>'บันทึกไม่สำเร็จ: '.$e->getMessage()]);
}
    exit;