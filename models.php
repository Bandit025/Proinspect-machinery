<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
  exit;
}
$isAdmin = ((int)($_SESSION['user']['role'] ?? 1) === 2);

$ok  = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$q   = trim($_GET['q'] ?? '');
$bid = (int)($_GET['brand_id'] ?? 0);

/* โหลดแบรนด์ไว้ทำฟิลเตอร์ */
$brands = $pdo->query("
  SELECT brand_id, brand_name
  FROM brands
  ORDER BY brand_name
")->fetchAll(PDO::FETCH_ASSOC);

/* ดึงรายการรุ่น + กรอง */
$params = [];
$sql = "SELECT mo.model_id, mo.model_name, mo.brand_id, b.brand_name
        FROM models mo
        LEFT JOIN brands b ON b.brand_id = mo.brand_id
        WHERE 1=1";
if ($q !== '') {
  $sql .= " AND (mo.model_name LIKE :q OR b.brand_name LIKE :q)";
  $params[':q'] = "%{$q}%";
}
if ($bid > 0) {
  $sql .= " AND mo.brand_id = :bid";
  $params[':bid'] = $bid;
}
$sql .= " ORDER BY b.brand_name, mo.model_name";

$stm = $pdo->prepare($sql);
$stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>รุ่นรถ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=24">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">รุ่นรถ (Models)</h2>
        <div class="page-sub">จัดการรุ่นรถและผูกกับยี่ห้อ</div>
      </div>

      <?php if ($ok): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

      <section class="card">
        <div class="card-head">
          <h3 class="h5">รายการ</h3>

          <!-- ใช้ .toolbar + .search-form เพื่อให้มือถือเรียงบน→ล่างอัตโนมัติ -->
          <div class="toolbar">
            <form method="get" action="models.php" class="search-form">
              <input class="input sm" type="text" name="q"
                     placeholder="ค้นหา รุ่น/ยี่ห้อ"
                     value="<?= h($q) ?>">

              <select class="select sm" name="brand_id">
                <option value="0">ทุกยี่ห้อ</option>
                <?php foreach ($brands as $b): ?>
                  <option value="<?= (int)$b['brand_id'] ?>" <?= $bid === (int)$b['brand_id'] ? 'selected' : '' ?>>
                    <?= h($b['brand_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <button class="btn btn-outline sm" type="submit">ค้นหา</button>
            </form>
              <a class="btn btn-brand sm add-btn" href="model_add.php">เพิ่มรุ่น</a>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:80px;">#</th>
                <th>แบรนด์</th>
                <th>รุ่น</th>
                <th class="tr" style="width:180px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php $i=1; foreach ($rows as $r): $id=(int)$r['model_id']; ?>
                <tr>
                  <td><?= $i++ ?></td>
                  <td><?= h($r['brand_name'] ?? '—') ?></td>
                  <td><?= h($r['model_name']) ?></td>
                  <td class="tr">
                    <a class="link" href="model_edit.php?id=<?= $id ?>">แก้ไข</a>
                    <?php if ($isAdmin): ?>
                      ·
                      <form action="model_delete.php" method="post"
                            class="js-del"
                            data-info="<?= h(($r['brand_name'] ?? '-') . ' / ' . $r['model_name']) ?>"
                            style="display:inline;">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                        <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr><td colspan="4" class="muted">ไม่พบข้อมูล</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <!-- ให้ Hamburger / Sidebar ทำงานแน่ ๆ -->
  <script src="assets/script.js?v=4"></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('.js-del').forEach(f => {
        f.addEventListener('submit', (e) => {
          e.preventDefault();
          const info = f.dataset.info || 'รายการนี้';
          Swal.fire({
            icon: 'warning',
            title: 'ยืนยันการลบ?',
            text: info,
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true,
            confirmButtonColor: '#fec201'
          }).then(res => { if (res.isConfirmed) f.submit(); });
        });
      });

      <?php if ($ok): ?>Swal.fire({icon:'success',title:'สำเร็จ',text:'<?= h($ok) ?>',confirmButtonColor:'#fec201'});<?php endif; ?>
      <?php if ($err): ?>Swal.fire({icon:'error',title:'ไม่สำเร็จ',text:'<?= h($err) ?>',confirmButtonColor:'#fec201'});<?php endif; ?>
    });
  </script>
</body>
</html>
