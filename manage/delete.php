<?php
require __DIR__ . '/config.php';

// อนุญาตเฉพาะแอดมิน
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
    header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ'));
    exit;
}

// ต้องเป็น POST เท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: user.php?error=' . urlencode('วิธีการเรียกไม่ถูกต้อง'));
    exit;
}

// ตรวจ CSRF
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
    header('Location: user.php?error=' . urlencode('CSRF token ไม่ถูกต้อง'));
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: user.php?error=' . urlencode('ไม่พบผู้ใช้'));
    exit;
}

// กันลบตัวเอง
if ($id === (int)($_SESSION['user']['id'] ?? 0)) {
    header('Location: user.php?error=' . urlencode('ไม่สามารถลบผู้ใช้ของตนเองได้'));
    exit;
}

// ลบ
$stm = $pdo->prepare('DELETE FROM users WHERE user_id=? LIMIT 1');
$stm->execute([$id]);

header('Location: user.php?ok=' . urlencode('ลบผู้ใช้เรียบร้อย'));
exit;
