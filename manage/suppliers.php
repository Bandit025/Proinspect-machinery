<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) { header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน')); exit; }
$roleVal = (int)($_SESSION['user']['role'] ?? 1);
$isAdmin = ($roleVal === 2);

$ok  = $_GET['ok']    ?? '';
$err = $_GET['error'] ?? '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$q = trim($_GET['q'] ?? '');
$params = [];
$sql = "SELECT supplier_id, supplier_name, tax_id, phone, email, address FROM suppliers";
if ($q !== '') {
  $sql .= " WHERE (supplier_name LIKE :q OR phone LIKE :q OR email LIKE :q OR tax_id LIKE :q)";
  $params[':q'] = "%{$q}%";
}
$sql .= " ORDER BY supplier_id DESC";
$stm = $pdo->prepare($sql);
$stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ซัพพลายเออร์ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=10">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">ซัพพลายเออร์ (Suppliers)</h2>
      <div class="page-sub">แสดง/เพิ่ม/แก้ไข/ลบข้อมูลผู้ขาย</div>
    </div>

    <?php if ($ok): ?><div class="alert alert-success"><?=htmlspecialchars($ok)?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif; ?>

    <section class="card mt-20">
      <div class="card-head">
        <h3 class="h5">รายการซัพพลายเออร์</h3>
        <div style="display:flex;gap:8px;align-items:center;">
          <form method="get" action="suppliers.php" style="display:flex;gap:8px;">
            <input class="input" type="text" name="q" placeholder="ค้นหา: ชื่อ/โทร/อีเมล/ภาษี" value="<?=htmlspecialchars($q)?>">
            <button class="btn btn-outline sm" type="submit">ค้นหา</button>
          </form>
          <?php if ($isAdmin): ?>
            <a class="btn btn-brand sm" href="supplier_add.php">เพิ่มผู้ขาย</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:70px;">#</th>
              <th>ชื่อผู้ขาย</th>
              <th style="width:140px;">เลขภาษี</th>
              <th style="width:140px;">โทร</th>
              <th style="width:220px;">อีเมล</th>
              <th>ที่อยู่</th>
              <th class="tr" style="width:160px;">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php $i=1; foreach($rows as $r): $id=(int)$r['supplier_id']; ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($r['supplier_name']) ?></td>
                <td><?= htmlspecialchars($r['tax_id'] ?? '-') ?></td>
                <td><?= htmlspecialchars($r['phone']  ?? '-') ?></td>
                <td><?= htmlspecialchars($r['email']  ?? '-') ?></td>
                <td><?= htmlspecialchars($r['address']?? '-') ?></td>
                <td class="tr">
                  <a class="link" href="supplier_edit.php?id=<?= $id ?>">แก้ไข</a>
                  <?php if ($isAdmin): ?>
                    ·
                    <form action="supplier_delete.php" method="post"
                          class="js-del-sup" data-name="<?= htmlspecialchars($r['supplier_name']) ?>"
                          style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= $csrf ?>">
                      <input type="hidden" name="id"   value="<?= $id ?>">
                      <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                      <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="7" class="muted">ไม่พบข้อมูล</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>

<script src="assets/script.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-del-sup').forEach(form => {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const name = form.dataset.name || '';
      Swal.fire({
        icon:'warning', title:'ยืนยันการลบ?',
        text: name ? `ลบผู้ขาย: "${name}"` : 'ลบรายการนี้',
        showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก',
        reverseButtons:true, confirmButtonColor:'#fec201'
      }).then(res => { if (res.isConfirmed) form.submit(); });
    });
  });

  <?php if ($ok): ?>
    Swal.fire({icon:'success', title:'สำเร็จ', text:'<?= htmlspecialchars($ok, ENT_QUOTES) ?>', confirmButtonColor:'#fec201'});
  <?php elseif ($err): ?>
    Swal.fire({icon:'error', title:'ไม่สำเร็จ', text:'<?= htmlspecialchars($err, ENT_QUOTES) ?>', confirmButtonColor:'#fec201'});
  <?php endif; ?>
});
</script>
</body>
</html>
