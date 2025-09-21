<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
    header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ'));
    exit;
}
$back = $_POST['return'] ?? 'locations.php';

if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
    header('Location: ' . $back . '?error=' . urlencode('CSRF ไม่ถูกต้อง'));
    exit;
}
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . $back . '?error=' . urlencode('ไม่พบรายการ'));
    exit;
}

try {
    // ถ้ามีตารางอื่นอ้างอิง location_id ด้วย FK ให้เพิ่ม try-catch/แจ้งเตือนตรงนี้
    $del = $pdo->prepare("DELETE FROM locations WHERE location_id=?");
    $del->execute([$id]);
    header('Location: ' . $back . '?ok=' . urlencode('ลบสถานที่เรียบร้อย'));
    exit;
} catch (Throwable $e) {
    header('Location: ' . $back . '?error=' . urlencode('ลบไม่สำเร็จ: ' . $e->getMessage()));
    exit;
}
?>