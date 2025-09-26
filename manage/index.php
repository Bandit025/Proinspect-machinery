<?php
require __DIR__ . '/config.php';
if (!empty($_SESSION['user'])) {
  header('Location: dashboard.php'); exit;
}
$err = isset($_GET['error']) ? $_GET['error'] : '';
$ok  = isset($_GET['ok']) ? $_GET['ok'] : '';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เข้าสู่ระบบ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=5">
  <style>
    /* จัดกึ่งกลางหน้า login แบบไม่พึ่ง .wrap ใน style.css */
    .auth {
      min-height: 100dvh;
      display: grid;
      place-items: center;
      padding: 24px;
    }
    .auth .card { max-width: 480px; width: 100%; }
  </style>
</head>
<body>
<div class="auth">
  <div class="card">
    <div class="header">
      <div class="brand-badge">PI</div>
      <div>
        <h1 class="h4">ProInspect Machinery</h1>
        <div class="muted">เข้าสู่ระบบ</div>
      </div>
    </div>

    <?php if ($err): ?>
      <div class="alert alert-danger"><?=htmlspecialchars($err)?></div>
    <?php elseif ($ok): ?>
      <div class="alert alert-success"><?=htmlspecialchars($ok)?></div>
    <?php endif; ?>

    <form action="login.php" method="post" id="loginForm" novalidate>
      <label for="email">อีเมล</label>
      <input class="input" type="email" id="email" name="email" required>

      <label for="password">รหัสผ่าน</label>
      <div class="input-group" style="position:relative;">
        <input class="input" type="password" id="password" name="password" minlength="8" required>
        <button type="button" class="toggle" id="togglePwd">แสดง</button>
      </div>

      <div style="margin-top:14px;">
        <button class="btn btn-brand" type="submit">เข้าสู่ระบบ</button>
      </div>

      <div class="actions">
        <span class="small">ลืมรหัสผ่าน?</span>
        <span class="muted small">ติดต่อผู้ดูแลระบบ</span>
      </div>
    </form>

    <div class="footer">© <?=date('Y')?> ProInspect Machinery</div>
  </div>
</div>
<script src="assets/script.js"></script>
</body>
</html>
