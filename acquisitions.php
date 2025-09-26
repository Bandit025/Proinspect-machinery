<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
  exit;
}

$ok  = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$q  = trim($_GET['q']  ?? '');
$d1 = trim($_GET['d1'] ?? '');
$d2 = trim($_GET['d2'] ?? '');

/* ===== DEBUG =====
error_reporting(E_ALL);
ini_set('display_errors', 1);
*/

$params = [];
$sql = "SELECT a.*, m.code, b.model_name, c.brand_name, s.supplier_name
        FROM acquisitions a
        JOIN machines  m  ON m.machine_id  = a.machine_id
        JOIN models    b  ON b.model_id    = m.model_id
        JOIN brands    c  ON b.brand_id    = c.brand_id
        JOIN suppliers s  ON s.supplier_id = a.supplier_id
        WHERE 1=1";
if ($q !== '') {
  $sql .= " AND (a.doc_no LIKE :q OR m.code LIKE :q OR c.brand_name LIKE :q OR s.supplier_name LIKE :q)";
  $params[':q'] = "%{$q}%";
}
if ($d1 !== '') { $sql .= " AND a.acquired_at >= :d1"; $params[':d1'] = $d1; }
if ($d2 !== '') { $sql .= " AND a.acquired_at <= :d2"; $params[':d2'] = $d2; }

$sql .= " ORDER BY a.acquired_at DESC, a.acquisition_id DESC";
$stm = $pdo->prepare($sql);
$stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);

function thb($n){ return $n !== null ? '฿' . number_format((float)$n, 2) : '-'; }
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>ซื้อรถเข้า — ProInspect Machinery</title>
  <!-- ใช้พาธแบบ absolute กันพลาด -->
  <link rel="stylesheet" href="assets/style.css?v=9">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">รายการซื้อรถเข้า (Acquisitions)</h2>
        <div class="page-sub">แสดง/ค้นหา/จัดการเอกสารซื้อ</div>
      </div>
      <section class="card">
        <div class="card-head">
          <h3 class="h5">รายการ</h3>

          <!-- ✅ ใช้ toolbar + search-form เพื่อ stack บนมือถือ -->
          <div class="toolbar">
            <form method="get" action="acquisitions.php" class="search-form">
              <input class="input sm" type="text" name="q"
                     placeholder="เลขเอกสาร/รหัสรถ/ยี่ห้อ/ผู้ขาย"
                     value="<?= htmlspecialchars($q) ?>">

              <input class="input sm" type="date" name="d1" value="<?= htmlspecialchars($d1) ?>">
              <input class="input sm" type="date" name="d2" value="<?= htmlspecialchars($d2) ?>">

              <button class="btn btn-outline sm" type="submit">ค้นหา</button>
            </form>

            <a class="btn btn-brand sm add-btn" href="acquisition_add.php">เพิ่มเอกสาร</a>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:120px;">วันที่ซื้อ</th>
                <th style="width:120px;">เลขเอกสาร</th>
                <th style="width:240px;">รถ</th>
                <th style="width:140px;">ราคา</th>
                <th class="tr" style="width:160px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): $id = (int)$r['acquisition_id']; ?>
                <tr>
                  <td><?= htmlspecialchars($r['acquired_at']) ?></td>
                  <td><?= htmlspecialchars($r['doc_no'] ?: '-') ?></td>
                  <td>
                    <div><strong><?= htmlspecialchars($r['code']) ?></strong></div>
                    <div class="muted"><?= htmlspecialchars($r['brand_name'].' '.$r['model_name']) ?></div>
                  </td>
                  <td><strong><?= thb($r['total_amount']) ?></strong></td>
                  <td class="tr">
                    <a class="link" href="acquisition_edit.php?id=<?= $id ?>">แก้ไข</a>

                    <form action="acquisition_delete.php" method="post" class="js-del"
                          data-info="<?= htmlspecialchars(($r['doc_no'] ?: '-') . ' / ' . $r['code']) ?>"
                          style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= $csrf ?>">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                      <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr><td colspan="5" class="muted">ไม่พบข้อมูล</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <!-- สคริปต์ควบคุม sidebar/hamburger (ใช้ตัวเดียวทุกหน้า) -->
  <script src="/assets/script.js?v=4"></script>

  <?php if ($err): ?>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
      icon: 'error',
      title: 'ไม่สำเร็จ',
      text: <?= json_encode($err, JSON_UNESCAPED_UNICODE) ?>,
      confirmButtonText: 'OK',
      confirmButtonColor: '#fec201',
      allowOutsideClick: false,
      allowEscapeKey: false
    }).then(function () {
      if (window.history.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.delete('error');
        const q = url.searchParams.toString();
        window.history.replaceState({}, document.title, url.pathname + (q ? '?' + q : '') + url.hash);
      }
    });
  });
  </script>
  <?php endif; ?>

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
  });
  </script>
</body>
</html>
