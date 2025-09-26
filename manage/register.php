<?php
require __DIR__ . '/config.php';
if (!empty($_SESSION['user'])) {
  header('Location: dashboard.php'); exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $f_name  = trim($_POST['f_name'] ?? '');
  $l_name  = trim($_POST['l_name'] ?? '');
  $email   = trim($_POST['email'] ?? '');
  $pwd     = $_POST['password'] ?? '';
  $pwd2    = $_POST['password_confirm'] ?? '';
  $urole   = 1; // บังคับเป็นเลข 1 (admin)

  if ($f_name === '') $errors[] = 'กรอกชื่อจริง';
  if ($l_name === '') $errors[] = 'กรอกนามสกุล';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'อีเมลไม่ถูกต้อง';
  if (strlen($pwd) < 8) $errors[] = 'รหัสผ่านอย่างน้อย 8 ตัวอักษร';
  if ($pwd !== $pwd2) $errors[] = 'ยืนยันรหัสผ่านไม่ตรงกัน';

  // ✖️ ลบบรรทัดนี้ทิ้ง (ไม่ใช้แล้ว เพราะ urole เป็นตัวเลข)
  // if (!in_array($urole,['admin','user'], true)) $urole = 'user';

  if (!$errors) {
    // กันอีเมลซ้ำ
    $s = $pdo->prepare('SELECT 1 FROM users WHERE email=? LIMIT 1');
    $s->execute([$email]);
    if ($s->fetch()) $errors[] = 'อีเมลนี้ถูกใช้แล้ว';
  }

  if (!$errors) {
    $hash = password_hash($pwd, PASSWORD_DEFAULT);

    $sql = 'INSERT INTO users (f_name,l_name,email,password,urole) VALUES (?,?,?,?,?)';
    $i = $pdo->prepare($sql);
    $i->bindValue(1, $f_name, PDO::PARAM_STR);
    $i->bindValue(2, $l_name, PDO::PARAM_STR);
    $i->bindValue(3, $email, PDO::PARAM_STR);
    $i->bindValue(4, $hash, PDO::PARAM_STR);
    $i->bindValue(5, (int)$urole, PDO::PARAM_INT); // ✅ ใส่เป็นตัวเลข
    $i->execute();

    header('Location: index.php?ok=' . urlencode('สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ'));
    exit;
  }
}

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>สมัครสมาชิก — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="header">
      <div class="brand-badge">PI</div>
      <div>
        <h1 class="h4">สมัครสมาชิก</h1>
        <div class="muted">ProInspect Machinery</div>
      </div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul style="margin:0 0 0 18px;">
          <?php foreach($errors as $e): ?>
            <li><?=htmlspecialchars($e)?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" id="regForm" novalidate>
      <div class="row">
        <div>
          <label>ชื่อจริง</label>
          <input class="input" type="text" name="f_name" required value="<?=htmlspecialchars($_POST['f_name'] ?? '')?>">
        </div>
        <div>
          <label>นามสกุล</label>
          <input class="input" type="text" name="l_name" required value="<?=htmlspecialchars($_POST['l_name'] ?? '')?>">
        </div>
      </div>

      <label>อีเมล</label>
      <input class="input" type="email" name="email" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>">

      <div class="row">
        <div>
          <label>รหัสผ่าน <span class="small">(อย่างน้อย 8 ตัวอักษร)</span></label>
          <div class="input-group">
            <input class="input" type="password" id="pwd" name="password" minlength="8" required>
            <button type="button" class="toggle" id="togglePwdReg">แสดง</button>
          </div>
        </div>
        <div>
          <label>ยืนยันรหัสผ่าน</label>
          <div class="input-group">
            <input class="input" type="password" id="pwd2" name="password_confirm" minlength="8" required>
            <button type="button" class="toggle" id="togglePwdReg2">แสดง</button>
          </div>
        </div>
      </div>

      <!--<label>สิทธิ์ผู้ใช้</label>
      <select class="select" name="urole">
        <option value="user" <?= (($_POST['urole'] ?? 'user')==='user')?'selected':''; ?>>ผู้ใช้ทั่วไป</option>
        <option value="admin" <?= (($_POST['urole'] ?? '')==='admin')?'selected':''; ?>>ผู้ดูแลระบบ</option>
      </select>-->

      <div style="margin-top:14px;">
        <button class="btn btn-brand" type="submit">สมัครสมาชิก</button>
      </div>

      <div class="actions">
        <span class="small">มีบัญชีแล้ว?</span>
        <a class="link" href="index.php">เข้าสู่ระบบ</a>
      </div>
    </form>

    <div class="footer">© <?=date('Y')?> ProInspect Machinery</div>
  </div>
</div>
<script src="assets/script.js"></script>
</body>
</html>
