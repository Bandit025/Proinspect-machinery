<?php
require __DIR__ . '/config.php';

// อนุญาตเฉพาะแอดมิน
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { header('Location: user.php?error=' . urlencode('ไม่พบผู้ใช้ที่ต้องการแก้ไข')); exit; }

// ดึงข้อมูลเดิม
$stm = $pdo->prepare('SELECT user_id,f_name,l_name,email,urole FROM users WHERE user_id=? LIMIT 1');
$stm->execute([$id]);
$userRow = $stm->fetch();
if (!$userRow) { header('Location: user.php?error=' . urlencode('ผู้ใช้ไม่อยู่ในระบบ')); exit; }

$errors = [];
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $f_name = trim($_POST['f_name'] ?? '');
  $l_name = trim($_POST['l_name'] ?? '');
  $email  = trim($_POST['email'] ?? '');
  $urole  = (int)($_POST['urole'] ?? 1);
  $newpwd = $_POST['new_password'] ?? '';
  $newpwd2= $_POST['new_password_confirm'] ?? '';

  if ($f_name === '') $errors[] = 'กรอกชื่อจริง';
  if ($l_name === '') $errors[] = 'กรอกนามสกุล';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'อีเมลไม่ถูกต้อง';
  if (!in_array($urole, [1,2,3], true)) $urole = 1;
  if ($newpwd !== '' && strlen($newpwd) < 8) $errors[] = 'รหัสผ่านใหม่อย่างน้อย 8 ตัวอักษร';
  if ($newpwd !== $newpwd2) $errors[] = 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน';

  // อีเมลซ้ำกับคนอื่น?
  if (!$errors) {
    $s = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
    $s->execute([$email, $id]);
    if ($s->fetch()) $errors[] = 'อีเมลนี้ถูกใช้แล้ว';
  }

  if (!$errors) {
    // อัปเดต
    if ($newpwd !== '') {
      $hash = password_hash($newpwd, PASSWORD_DEFAULT);
      $sql = 'UPDATE users SET f_name=?, l_name=?, email=?, urole=?, password=? WHERE user_id=?';
      $p = $pdo->prepare($sql);
      $p->execute([$f_name,$l_name,$email,$urole,$hash,$id]);
    } else {
      $sql = 'UPDATE users SET f_name=?, l_name=?, email=?, urole=? WHERE user_id=?';
      $p = $pdo->prepare($sql);
      $p->execute([$f_name,$l_name,$email,$urole,$id]);
    }

    header('Location: user.php?ok=' . urlencode('แก้ไขข้อมูลสำเร็จ'));
    exit;
  }
}

// map
function role_selected($v,$cur){ return (int)$v===(int)$cur ? 'selected' : ''; }
function role_name(int $r): string {
  return [1=>'ผู้ใช้งาน',2=>'ผู้ดูแลระบบ',3=>'ผู้เยี่ยมชม'][$r] ?? 'ผู้ใช้งาน';
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>แก้ไขผู้ใช้ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>

<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">แก้ไขผู้ใช้ #<?= (int)$userRow['user_id'] ?></h2>
      <div class="page-sub"><?= htmlspecialchars($userRow['f_name'].' '.$userRow['l_name']) ?> · <?= role_name((int)$userRow['urole']) ?></div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul style="margin:0 0 0 18px;">
          <?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <section class="card">
      <form method="post">
        <input type="hidden" name="id" value="<?= (int)$userRow['user_id'] ?>">
        <div class="row">
          <div>
            <label>ชื่อจริง</label>
            <input class="input" type="text" name="f_name" required value="<?=htmlspecialchars($_POST['f_name'] ?? $userRow['f_name'])?>">
          </div>
          <div>
            <label>นามสกุล</label>
            <input class="input" type="text" name="l_name" required value="<?=htmlspecialchars($_POST['l_name'] ?? $userRow['l_name'])?>">
          </div>
        </div>

        <label>อีเมล</label>
        <input class="input" type="email" name="email" required value="<?=htmlspecialchars($_POST['email'] ?? $userRow['email'])?>">

        <label>สิทธิ์ผู้ใช้</label>
        <select class="select" name="urole">
          <option value="1" <?=role_selected(1, $_POST['urole'] ?? $userRow['urole'])?>>ผู้ใช้งาน</option>
          <option value="2" <?=role_selected(2, $_POST['urole'] ?? $userRow['urole'])?>>ผู้ดูแลระบบ</option>
          <option value="3" <?=role_selected(3, $_POST['urole'] ?? $userRow['urole'])?>>ผู้เยี่ยมชม</option>
        </select>

        <div class="row">
          <div>
            <label>รหัสผ่านใหม่ <span class="small">(ปล่อยว่างเพื่อไม่เปลี่ยน)</span></label>
            <input class="input" type="password" name="new_password" id="pwd">
          </div>
          <div>
            <label>ยืนยันรหัสผ่านใหม่</label>
            <input class="input" type="password" name="new_password_confirm" id="pwd2">
          </div>
        </div>

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
