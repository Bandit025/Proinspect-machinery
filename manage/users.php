<?php
require __DIR__ . '/config.php';

// อนุญาตเฉพาะแอดมิน (role = 2)
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ'));
  exit;
}

$ok = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';

// สร้าง CSRF token ไว้ใช้กับการลบ
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// ค้นหาแบบง่าย (ออปชัน)
$q = trim($_GET['q'] ?? '');
$params = [];
$sql = "SELECT user_id,f_name,l_name,email,urole,created_at FROM users";
if ($q !== '') {
  $sql .= " WHERE (f_name LIKE :q OR l_name LIKE :q OR email LIKE :q)";
  $params[':q'] = "%{$q}%";
}
$sql .= " ORDER BY user_id DESC";
$stm = $pdo->prepare($sql);
$stm->execute($params);
$rows = $stm->fetchAll();

// map ชื่อสิทธิ์
function role_name(int $r): string
{
  return [
    1 => 'ผู้ใช้งาน',
    2 => 'ผู้ดูแลระบบ',
    3 => 'ผู้เยี่ยมชม',
  ][$r] ?? 'ผู้ใช้งาน';
}
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ผู้ใช้งาน — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css">
</head>

<body>

  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="content-inner">
        <div class="page-head">
          <h2 class="page-title">ผู้ใช้งาน</h2>
          <div class="page-sub">จัดการบัญชีผู้ใช้ในระบบ</div>
        </div>

        <?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <section class="card mt-20">
          <div class="card-head">
            <h3 class="h5">รายการผู้ใช้</h3>
            <div style="display:flex;gap:8px;align-items:center;">
              <form method="get" action="user.php" style="display:flex;gap:8px;">
                <input class="input" type="text" name="q" placeholder="ค้นหา: ชื่อ/อีเมล" value="<?= htmlspecialchars($q) ?>">
                <button class="btn btn-outline sm" type="submit">ค้นหา</button>
              </form>
              <a class="btn btn-brand sm" href="add_user.php">เพิ่มผู้ใช้</a>
            </div>
          </div>

          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th style="width:70px;">#</th>
                  <th>ชื่อ-นามสกุล</th>
                  <th>อีเมล</th>
                  <th>สิทธิ์</th>
                  <th>สร้างเมื่อ</th>
                  <th class="tr" style="width:160px;">การทำงาน</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td><?= (int)$r['user_id'] ?></td>
                    <td><?= htmlspecialchars($r['f_name'] . ' ' . $r['l_name']) ?></td>
                    <td><?= htmlspecialchars($r['email']) ?></td>
                    <td><?= role_name((int)$r['urole']) ?></td>
                    <td><?= htmlspecialchars($r['created_at']) ?></td>
                    <td class="tr">
                      <a class="link" href="edit.php?id=<?= (int)$r['user_id'] ?>">แก้ไข</a>
                      <?php if ((int)$r['user_id'] !== (int)$_SESSION['user']['id']): ?>
                        <!-- ป้องกันลบตัวเอง -->
                        <form action="delete.php" method="post" style="display:inline;" onsubmit="return confirm('ยืนยันการลบผู้ใช้นี้?');">
                          <input type="hidden" name="csrf" value="<?= $csrf ?>">
                          <input type="hidden" name="id" value="<?= (int)$r['user_id'] ?>">
                          <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                  <tr>
                    <td colspan="6" class="muted">ไม่พบข้อมูล</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </main>
  </div>

  <script src="assets/script.js"></script>
</body>

</html>