<?php
require __DIR__ . '/config.php';

// อนุญาตเฉพาะแอดมิน (role = 2)
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ'));
  exit;
}

$ok  = $_GET['ok']    ?? '';
$err = $_GET['error'] ?? '';

// สร้าง CSRF token
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// ดึงรายการแบรนด์
$sql = "SELECT brand_id, brand_name FROM brands ORDER BY brand_id DESC";
$result  = $pdo->query($sql);
$brands  = $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];

//ดึงรีายการรุ่น
$sql = "SELECT * FROM models ORDER BY model_id DESC";
$result  = $pdo->query($sql);
$models  = $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];
?>


<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ยี่ห้อ (Brands) — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=8">
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">ยี่ห้อ (Brands)</h2>
        <div class="page-sub">จัดการยี่ห้อสำหรับใช้งานกับรุ่นและรถ</div>
      </div>

      <?php if ($ok): ?>
        <div class="alert alert-success"><?= htmlspecialchars($ok) ?></div>
      <?php endif; ?>
      <?php if ($err): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
      <?php endif; ?>

      <section class="card mt-20">
        <div class="card-head">
          <h3 class="h5">รายการยี่ห้อ</h3>
          <div style="display:flex;gap:8px;align-items:center;">
            <a class="btn btn-brand sm" href="add_brand.php">เพิ่มแบรนด์</a>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:70px;">#</th>
                <th>ชื่อแบรนด์</th>
                <th class="tr" style="width:160px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php $i = 1;
              foreach ($brands as $r): $id = (int)$r['brand_id']; ?>
                <tr>
                  <td><?= $i++ ?></td>
                  <td><?= htmlspecialchars($r['brand_name']) ?></td>
                  <td class="tr">
                    <a class="link" href="brand_edit.php?id=<?= $id ?>">แก้ไข</a>
                    ·
                    <form action="brand_delete.php" method="post"
                      class="js-del-brand" data-name="<?= htmlspecialchars($r['brand_name']) ?>"
                      style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= $csrf ?>">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                      <button type="submit" class="link" style="border:none;background:none;color:#a40000;">
                        ลบ
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$brands): ?>
                <tr>
                  <td colspan="3" class="muted">ไม่พบข้อมูล</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
      
      
        

  <script src="assets/script.js"></script>
  <script>
    // SweetAlert2: ยืนยันก่อนลบ
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('.js-del-brand').forEach(form => {
        form.addEventListener('submit', (e) => {
          e.preventDefault();
          const name = form.dataset.name || '';
          Swal.fire({
            icon: 'warning',
            title: 'ยืนยันการลบ?',
            text: name ? `ลบยี่ห้อ: "${name}"` : 'ลบรายการนี้',
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true,
            confirmButtonColor: '#fec201'
          }).then((res) => {
            if (res.isConfirmed) form.submit();
          });
        });
      });
    });
  </script>
</body>

</html>