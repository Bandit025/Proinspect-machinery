<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
  exit;
}
$isAdmin = ((int)($_SESSION['user']['role'] ?? 1) === 2);

$ok = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$q   = trim($_GET['q'] ?? '');
$s   = (int)($_GET['status'] ?? 0);
$bid = (int)($_GET['brand_id'] ?? 0);

function status_label($x)
{
  return [1 => 'รับเข้า', 2 => 'พร้อมขาย', 3 => 'ติดจอง', 4 => 'ขายแล้ว', 5 => 'ซ่อมบำรุง'][$x] ?? 'รับเข้า';
}
function thb($n)
{
  return $n !== null ? '฿' . number_format((float)$n, 2) : '-';
}

/* โหลดยี่ห้อสำหรับฟิลเตอร์ */
$brands = $pdo->query("SELECT brand_id, brand_name FROM brands ORDER BY brand_name")->fetchAll(PDO::FETCH_ASSOC);

/* ตรวจว่ามีคอลัมน์ model_id ใน machines ไหม เพื่อ join models แบบปลอดภัย */
$hasModelId = false;
try {
  $chk = $pdo->query("SHOW COLUMNS FROM machines LIKE 'model_id'");
  $hasModelId = (bool)$chk->fetch();
} catch (Throwable $e) {
  $hasModelId = false;
}

/* สร้าง SQL โดยต่อ JOIN models เฉพาะเมื่อมี model_id */
$params = [];
$sql = "SELECT 
          m.*,
          mo.model_name,
          b.brand_name,
          l.location_name
        FROM machines m
        JOIN models   mo ON m.model_id = mo.model_id
        JOIN brands   b  ON mo.brand_id = b.brand_id
        LEFT JOIN locations l ON l.location_id = m.location
        WHERE 1=1";

if ($q !== '') {
  $sql .= " AND (
            m.code LIKE :q
         OR b.brand_name LIKE :q
         OR mo.model_name LIKE :q
         OR m.engine_no LIKE :q
         OR m.serial_no LIKE :q
         OR l.location_name LIKE :q
         )";
  $params[':q'] = "%{$q}%";
}
if ($bid > 0) {
  $sql .= " AND b.brand_id = :bid";
  $params[':bid'] = $bid;
}
if ($s > 0) {
  $sql .= " AND m.status = :st";
  $params[':st'] = $s;
}

$sql .= " ORDER BY m.updated_at DESC, m.machine_id DESC";

$stm = $pdo->prepare($sql);
$stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);


?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>ทะเบียนรถ — ProInspect Machinery</title>
  <!-- ใน <head> -->
<link rel="stylesheet" href="/assets/style.css?v=40">

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">ทะเบียนรถ</h2>
        <div class="page-sub">ค้นหา/กรอง/จัดการ · เปลี่ยนสถานะได้ทันที</div>
      </div>

      <?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <section class="card">
        <div class="card-head">
          <h3 class="h5">รายการ</h3>
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <!-- ฟอร์มค้นหา -->
            <form method="get" action="machines.php" class="search-form">
              <input class="input sm" type="text" name="q" placeholder="ค้นหา..." value="<?= htmlspecialchars($q) ?>">
              <select class="select sm" name="brand_id">
                <option value="0">ทุกยี่ห้อ</option>
                <?php foreach ($brands as $b): ?>
                  <option value="<?= $b['brand_id'] ?>" <?= $bid === (int)$b['brand_id'] ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($b['brand_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <select class="select sm" name="status">
                <option value="0">ทุกสถานะ</option>
                <?php foreach ([1, 2, 3, 4, 5] as $opt): ?>
                  <option value="<?= $opt ?>" <?= $s === $opt ? 'selected' : ''; ?>><?= status_label($opt) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-outline sm" type="submit">ค้นหา</button>
            </form>

            <!-- ปุ่มเพิ่มรถ -->
            <a class="btn btn-brand sm" href="machine_add.php">เพิ่มรถ</a>
          </div>

        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:100px;">รูป</th>
                <th style="width:120px;">เลขทะเบียน</th>
                <th style="width: 140px;">ยี่ห้อ/รุ่น</th>
                <th style="width: 140px;">ชั่วโมงการใช้งาน</th>
                <th style="width:140px;" class="tr">ราคาซื้อ</th>
                <th style="width:140px;" class="tr">ราคาตั้งขาย</th>
                <th style="width:130px;">สถานะ</th>
                <th class="tr" style="width:160px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r) {
                $id = (int)$r['machine_id']; ?>
                <tr>
                  <td class="td-thumb">
                    <?php if (!empty($r['image_path'])) { ?>
                      <a href="<?= htmlspecialchars($r['image_path']) ?>" target="_blank" rel="noopener">
                        <img style="width: 80px; height: 60px;" class="thumb" src="<?= htmlspecialchars($r['image_path']) ?>" alt="machine">
                      </a>
                    <?php } else { ?>
                      <span class="thumb ph">ไม่มีรูป</span>
                    <?php } ?>
                  </td>
                  <td><a class="link" href="machine_view.php?id=<?= $id ?>"><strong><?= htmlspecialchars($r['code']) ?></strong></a></td>
                  <td><?= htmlspecialchars($r['brand_name'] . "-") . $r['model_name'] ?></td>
                  <td><?= $r['hour_meter'] ?></td>
                  <td><?= thb($r['purchase_price']) ?></td>
                  <td><?= thb($r['asking_price']) ?></td>
                  <td>
                    <select class="select js-status" data-id="<?= $id ?>" data-prev="<?= (int)$r['status'] ?>">
                      <?php foreach ([1, 2, 3, 4, 5] as $opt) { ?>
                        <option value="<?= $opt ?>" <?= $opt == (int)$r['status'] ? 'selected' : '' ?>>
                          <?= status_label($opt) ?>
                        </option>
                      <?php } ?>
                    </select>
                    <div class="muted" style="font-size:.85rem;">เปลี่ยนแล้วบันทึกอัตโนมัติ</div>
                  </td>


                  <td>
                    <a class="link" href="machine_edit.php?id=<?= $id ?>">แก้ไข</a>
                    
                      <form action="machine_delete.php" method="post" class="js-del" data-info="<?= htmlspecialchars($r['code'] . ' / ' . $r['brand_name']) ?>" style="display:inline;">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                        <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>
                      </form>

                  </td>
                </tr>
              <?php } ?>
              <?php if (!$rows): ?><tr>
                  <td colspan="8" class="muted">ไม่พบข้อมูล</td>
                </tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>
    <script src="assets/script.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // ลบ (SweetAlert)
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
          }).then(res => {
            if (res.isConfirmed) f.submit();
          });
        });
      });

      // เปลี่ยนสถานะแบบ inline
      document.querySelectorAll('.js-status').forEach(sel => {
        sel.addEventListener('change', () => {
          const machineId = sel.dataset.id;
          const newStatus = sel.value;
          const prev = sel.dataset.prev;
          sel.disabled = true;

          fetch('machine_status_update.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
              },
              body: new URLSearchParams({
                csrf: '<?= $csrf ?>',
                id: machineId,
                status: newStatus

              })
            })
            .then(r => r.json())
            .then(j => {
              if (j && j.ok) {
                sel.dataset.prev = newStatus;
                Swal.fire({
                  icon: 'success',
                  title: 'บันทึกแล้ว',
                  text: 'สถานะ: ' + (j.label || ''),
                  timer: 1200,
                  showConfirmButton: false
                });
              } else {
                sel.value = prev;
                Swal.fire({
                  icon: 'error',
                  title: 'ไม่สำเร็จ',
                  text: (j && j.error) ? j.error : 'เกิดข้อผิดพลาด',
                  confirmButtonColor: '#fec201'
                });
              }
            })
            .catch(() => {
              sel.value = prev;
              Swal.fire({
                icon: 'error',
                title: 'ไม่สำเร็จ',
                text: 'เครือข่ายผิดพลาด',
                confirmButtonColor: '#fec201'
              });
            })
            .finally(() => sel.disabled = false);
        });
      });
    });
  </script>
</body>

</html>