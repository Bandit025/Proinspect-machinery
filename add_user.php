<?php
require __DIR__ . '/config.php';

if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ตรวจ CSRF
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  // รับค่าฟอร์ม
  $f_name = trim($_POST['f_name'] ?? '');
  $l_name = trim($_POST['l_name'] ?? '');
  $email  = trim($_POST['email'] ?? '');
  $pwd    = (string)($_POST['password'] ?? '');
  $pwd2   = (string)($_POST['password_confirm'] ?? '');
  $urole  = (int)($_POST['urole'] ?? 1);

  // ตรวจข้อมูล
  if ($f_name === '') $errors[] = 'กรอกชื่อจริง';
  if ($l_name === '') $errors[] = 'กรอกนามสกุล';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'อีเมลไม่ถูกต้อง';
  if ($pwd === '') $errors[] = 'กรอกรหัสผ่าน';
  elseif (mb_strlen($pwd) < 8) $errors[] = 'รหัสผ่านอย่างน้อย 8 ตัวอักษร';
  if ($pwd !== $pwd2) $errors[] = 'ยืนยันรหัสผ่านไม่ตรงกัน';

  // ตรวจอีเมลซ้ำ
  if (empty($errors)) {
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) $errors[] = 'อีเมลนี้ถูกใช้แล้ว';
  }

  // ถ้าไม่มี error ให้บันทึก
  if (empty($errors)) {
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (f_name, l_name, email, password, urole) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$f_name, $l_name, $email, $hash, $urole]);

    header('Location: users.php?ok=' . urlencode('เพิ่มผู้ใช้ใหม่เรียบร้อย'));
    exit;
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เพิ่มผู้ใช้ใหม่ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .alert-danger { background:#fdecea; color:#b71c1c; padding:10px 15px; border-radius:8px; margin-bottom:15px; }
    .field-error { color:#b71c1c; font-size:13px; margin-top:4px; }
  </style>
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>

<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">เพิ่มผู้ใช้ใหม่</h2>
      <div class="page-sub">สร้างบัญชีผู้ใช้สำหรับเข้าระบบ</div>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <strong>พบข้อผิดพลาด:</strong>
        <ul>
          <?php foreach ($errors as $e): ?>
            <li><?= h($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <section class="card">
      <form method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div>
            <label>ชื่อจริง</label>
            <input class="input" type="text" name="f_name" value="<?= h($_POST['f_name'] ?? '') ?>">
          </div>
          <div>
            <label>นามสกุล</label>
            <input class="input" type="text" name="l_name" value="<?= h($_POST['l_name'] ?? '') ?>">
          </div>
        </div>

        <label style="margin-top:10px;">อีเมล</label>
        <input class="input" type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:10px;">
          <div>
            <label>รหัสผ่าน <span class="muted">(อย่างน้อย 8 ตัวอักษร)</span></label>
            <div class="input-group" style="position:relative;">
              <input class="input" type="password" id="pwd" name="password" minlength="8">
              <button type="button" class="toggle" id="togglePwdReg">แสดง</button>
            </div>
          </div>
          <div>
            <label>ยืนยันรหัสผ่าน</label>
            <div class="input-group" style="position:relative;">
              <input class="input" type="password" id="pwd2" name="password_confirm" minlength="8">
              <button type="button" class="toggle" id="togglePwdReg2">แสดง</button>
            </div>
          </div>
        </div>

        <label style="margin-top:10px;">สิทธิ์ผู้ใช้</label>
        <select class="select" name="urole">
          <option value="1" <?= (($_POST['urole'] ?? '1')==='1')?'selected':''; ?>>ผู้ใช้งาน</option>
          <option value="2" <?= (($_POST['urole'] ?? '')==='2')?'selected':''; ?>>ผู้ดูแลระบบ</option>
          <option value="3" <?= (($_POST['urole'] ?? '')==='3')?'selected':''; ?>>ผู้เยี่ยมชม</option>
        </select>

        <div style="margin-top:14px; display:flex; gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึก</button>
          <a class="btn btn-outline" href="users.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script>
document.getElementById('togglePwdReg').addEventListener('click', function() {
  const input = document.getElementById('pwd');
  input.type = input.type === 'password' ? 'text' : 'password';
  this.textContent = input.type === 'password' ? 'แสดง' : 'ซ่อน';
});
document.getElementById('togglePwdReg2').addEventListener('click', function() {
  const input = document.getElementById('pwd2');
  input.type = input.type === 'password' ? 'text' : 'password';
  this.textContent = input.type === 'password' ? 'แสดง' : 'ซ่อน';
});
</script>

</body>
</html>
