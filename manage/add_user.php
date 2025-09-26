<?php
// add_user.php
require __DIR__ . '/config.php';

// อนุญาตเฉพาะผู้ดูแลระบบ
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit;
}

// เตรียม CSRF
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$errors = [];
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ตรวจ CSRF
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  $f_name  = trim($_POST['f_name'] ?? '');
  $l_name  = trim($_POST['l_name'] ?? '');
  $email   = trim($_POST['email'] ?? '');
  $pwd     = $_POST['password'] ?? '';
  $pwd2    = $_POST['password_confirm'] ?? '';
  $urole   = (int)($_POST['urole'] ?? 1);      // 1=ผู้ใช้งาน, 2=ผู้ดูแลระบบ, 3=ผู้เยี่ยมชม

  if ($f_name === '') $errors[] = 'กรอกชื่อจริง';
  if ($l_name === '') $errors[] = 'กรอกนามสกุล';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'อีเมลไม่ถูกต้อง';
  if (!in_array($urole, [1,2,3], true)) $urole = 1;

  if ($pwd === '' || strlen($pwd) < 8) $errors[] = 'รหัสผ่านอย่างน้อย 8 ตัวอักษร';
  if ($pwd !== $pwd2) $errors[] = 'ยืนยันรหัสผ่านไม่ตรงกัน';

  // อีเมลซ้ำ?
  if (!$errors) {
    $s = $pdo->prepare('SELECT 1 FROM users WHERE email=? LIMIT 1');
    $s->execute([$email]);
    if ($s->fetch()) $errors[] = 'อีเมลนี้ถูกใช้แล้ว';
  }

  if (!$errors) {
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    $i = $pdo->prepare('INSERT INTO users (f_name,l_name,email,password,urole) VALUES (?,?,?,?,?)');
    $i->bindValue(1, $f_name, PDO::PARAM_STR);
    $i->bindValue(2, $l_name, PDO::PARAM_STR);
    $i->bindValue(3, $email, PDO::PARAM_STR);
    $i->bindValue(4, $hash, PDO::PARAM_STR);
    $i->bindValue(5, $urole, PDO::PARAM_INT);
    $i->execute();

    header('Location: users.php?ok=' . urlencode('เพิ่มผู้ใช้ใหม่เรียบร้อย')); exit;
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

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul style="margin:0 0 0 18px;">
          <?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <section class="card">
      <form method="post" id="regForm" novalidate>
        <input type="hidden" name="csrf" value="<?= $csrf ?>">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div>
            <label>ชื่อจริง</label>
            <input class="input" type="text" name="f_name" required value="<?=htmlspecialchars($_POST['f_name'] ?? '')?>">
          </div>
          <div>
            <label>นามสกุล</label>
            <input class="input" type="text" name="l_name" required value="<?=htmlspecialchars($_POST['l_name'] ?? '')?>">
          </div>
        </div>

        <label style="margin-top:10px;">อีเมล</label>
        <input class="input" type="email" name="email" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>">

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:10px;">
          <div>
            <label>รหัสผ่าน <span class="muted">(อย่างน้อย 8 ตัวอักษร)</span></label>
            <div class="input-group" style="position:relative;">
              <input class="input" type="password" id="pwd" name="password" minlength="8" required>
              <button type="button" class="toggle" id="togglePwdReg">แสดง</button>
            </div>
          </div>
          <div>
            <label>ยืนยันรหัสผ่าน</label>
            <div class="input-group" style="position:relative;">
              <input class="input" type="password" id="pwd2" name="password_confirm" minlength="8" required>
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

<script src="assets/script.js"></script>
</body>
</html>
