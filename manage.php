<?php
require __DIR__ . '/config.php';

$ok  = $_GET['ok']    ?? '';
$err = $_GET['error'] ?? '';

// สร้าง CSRF token
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// รับคำค้นหา
$q = trim($_GET['q'] ?? '');

// ดึงรายการแบรนด์ (รองรับค้นหา)
$params = [];
$sql = "SELECT brand_id, brand_name FROM brands ORDER BY brand_name ASC";

$stm = $pdo->prepare($sql);
$stm->execute($params);
$brands = $stm->fetchAll(PDO::FETCH_ASSOC);

$sql_models = "SELECT * FROM models a INNER JOIN brands b ON a.model_id = b.brand_id";
$stm_models = $pdo->prepare($sql_models);
$stm_models->execute($params);
$models = $stm_models->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ยี่ห้อ (Brands) — ProInspect Machinery</title>
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
        <h2 class="page-title">จัดการข้อมูล</h2>
        <div class="page-sub">จัดการแบรนด์และรุ่นของรถแม็คโคร</div>
      </div>
      <?php if ($ok){ ?>
        <div class="alert alert-success"><?= $ok ?></div>
      <?php }else if($err){?>
        <div class="alert alert-danger"><?= $err ?></div>
      <?php } ?>
        <div class="row">
          <div class="col-2">
            <h3 class="h5">แบรนด์</h3>
            <a class="btn btn-brand sm add-btn" href="add_brand.php">เพิ่มแบรนด์</a>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>ชื่อแบรนด์</th>
                    <th>จัดการ</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($brands)): ?>
                    <?php foreach ($brands as $r): 
                      $brand_id = (int)$r['brand_id'];
                      $brand_name = htmlspecialchars($r['brand_name'], ENT_QUOTES);
                    ?>
                      <tr>
                        <td><?= $brand_id ?></td>
                        <td><?= $brand_name ?></td>
                        <td>
                          <a class="link" href="brand_edit.php?id=<?= $brand_id ?>">แก้ไข</a>
                          <form action="brand_delete.php" method="post"
                                class="js-del-brand" data-name="<?= $brand_name ?>"
                                style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                            <input type="hidden" name="id" value="<?= $brand_id ?>">
                            <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES) ?>">
                            <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="4" class="muted">ไม่พบข้อมูล</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="col-2">
            <h3 class="h5">รุ่น</h3>
            <a class="btn btn-brand sm add-btn" href="model_add.php">เพิ่มรุ่น</a>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th style="width:70px;">#</th>
                    <th>ชื่อรุ่น</th>
                    <th>ชื่อแบรนด์</th>
                    <th>จัดการ</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($models)): ?>
                    <?php $i = 1; foreach ($models as $r):
                      $model_id = $r['model_id'];
                      $model_name = $r['model_name'];
                      $brand_name = $r['brand_name'];
                    ?>
                      <tr>
                        <td><?= $i++ ?></td>
                        <td><?= $model_name ?></td>
                        <td><?= $brand_name ?></td>
                        <td>
                          <a class="link" href="model_edit.php?id=<?= $model_id ?>">แก้ไข</a>
                          
                          <form action="model_delete.php" method="post"
                                class="js-del-brand" data-name="<?= $model_name ?>"
                                style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                            <input type="hidden" name="id" value="<?= $model_id ?>">
                            <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES) ?>">
                            <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="5" class="muted">ไม่พบข้อมูล</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
          
        </div>


  <!-- </section> -->
</main>
  </div>

  <script src="assets/script.js?v=4"></script>
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