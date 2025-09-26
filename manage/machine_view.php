<?php
// machine_view.php
require __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน')); exit;
}
$user    = $_SESSION['user'];
$isAdmin = ((int)($user['role'] ?? 1) === 2);

// เตรียม CSRF สำหรับปุ่มลบ
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// รับ id
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  header('Location: machines.php?error=' . urlencode('ไม่พบข้อมูลรถ')); exit;
}

// ดึงข้อมูลรถ + brand/model
$sql = "SELECT
          m.*, b.brand_name, mo.model_name
        FROM machines m
        JOIN brands b ON b.brand_id = m.brand_id
        JOIN models mo ON mo.model_id = m.model_id
        WHERE m.machine_id = ?
        LIMIT 1";
$stm = $pdo->prepare($sql);
$stm->execute([$id]);
$machine = $stm->fetch(PDO::FETCH_ASSOC);

if (!$machine) {
  header('Location: machines.php?error=' . urlencode('ไม่พบข้อมูลรถ')); exit;
}

function status_name(int $c): string {
  return [1=>'inbound',2=>'available',3=>'reserved',4=>'sold',5=>'retired'][$c] ?? 'inbound';
}
function thb($n){ return $n!==null ? '฿'.number_format((float)$n, 0) : '-'; }

// เตรียม path รูป
$hasPhoto  = !empty($machine['photo_main']) && file_exists(__DIR__ . '/' . $machine['photo_main']);
$photoPath = $hasPhoto ? $machine['photo_main'] : null;
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>รายละเอียดรถ — <?= htmlspecialchars($machine['code']) ?> | ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=11">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .mv-grid{ display:grid; grid-template-columns: 360px 1fr; gap:16px; }
    .mv-photo{ width:100%; aspect-ratio: 4/3; object-fit:cover; border-radius:12px; border:1px solid #eee; background:#f7f7f7; }
    .mv-meta{ display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:10px 16px; }
    .mv-item .k{ color:var(--muted); font-size:.92rem; }
    .mv-item .v{ font-weight:600; }
    @media (max-width: 900px){
      .mv-grid{ grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>

<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">รายละเอียดรถ</h2>
      <div class="page-sub">
        รหัส: <strong><?= htmlspecialchars($machine['code']) ?></strong>
        · <?= htmlspecialchars($machine['brand_name'].' '.$machine['model_name']) ?>
      </div>
    </div>

    <section class="card">
      <div class="mv-grid">
        <div>
          <?php if ($photoPath): ?>
            <img class="mv-photo" src="<?= htmlspecialchars($photoPath) ?>" alt="">
          <?php else: ?>
            <div class="mv-photo" style="display:grid;place-items:center;color:#bbb;">ไม่มีรูป</div>
          <?php endif; ?>
        </div>

        <div>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;">
            <h3 class="h5" style="margin:0;">ข้อมูลหลัก</h3>
            <div style="display:flex; gap:8px;">
              <span class="badge gray"><?= status_label_th((int)$machine['status_code']) ?></span>

              <?php if ($isAdmin): ?>
                <a class="btn btn-outline sm" href="machine_edit.php?id=<?= (int)$machine['machine_id'] ?>">แก้ไข</a>
                <form action="machine_delete.php" method="post" class="js-del" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="id"   value="<?= (int)$machine['machine_id'] ?>">
                  <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                  <button type="submit" class="btn btn-outline sm" style="color:#a40000;">ลบ</button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div class="mv-meta">
            <div class="mv-item"><div class="k">ยี่ห้อ</div><div class="v"><?= htmlspecialchars($machine['brand_name']) ?></div></div>
            <div class="mv-item"><div class="k">รุ่น</div><div class="v"><?= htmlspecialchars($machine['model_name']) ?></div></div>

            <div class="mv-item"><div class="k">ปีรุ่น</div><div class="v"><?= htmlspecialchars($machine['model_year'] ?? '-') ?></div></div>
            <div class="mv-item"><div class="k">ชั่วโมงใช้งาน</div><div class="v"><?= $machine['hour_meter']!==null?number_format($machine['hour_meter']):'-' ?></div></div>

            <div class="mv-item"><div class="k">เลขตัวถัง (Serial)</div><div class="v"><?= htmlspecialchars($machine['serial_no'] ?? '-') ?></div></div>
            <div class="mv-item"><div class="k">เลขเครื่อง (Engine)</div><div class="v"><?= htmlspecialchars($machine['engine_no'] ?? '-') ?></div></div>

            <div class="mv-item"><div class="k">สี</div><div class="v"><?= htmlspecialchars($machine['color'] ?? '-') ?></div></div>
            <div class="mv-item"><div class="k">น้ำหนัก (ตัน)</div><div class="v"><?= $machine['weight_class_ton']!==null?number_format($machine['weight_class_ton'],2):'-' ?></div></div>

            <div class="mv-item"><div class="k">สถานที่เก็บ</div><div class="v"><?= htmlspecialchars($machine['location'] ?? '-') ?></div></div>
            <div class="mv-item"><div class="k">ราคาตั้ง</div><div class="v"><?= thb($machine['asking_price']) ?></div></div>

            <div class="mv-item" style="grid-column:1/-1;">
              <div class="k">หมายเหตุ</div><div class="v"><?= htmlspecialchars($machine['notes'] ?? '-') ?></div>
            </div>

            <div class="mv-item"><div class="k">บันทึกเมื่อ</div><div class="v"><?= htmlspecialchars($machine['created_at'] ?? '-') ?></div></div>
            <div class="mv-item"><div class="k">แก้ไขล่าสุด</div><div class="v"><?= htmlspecialchars($machine['updated_at'] ?? '-') ?></div></div>
          </div>

          <div style="margin-top:14px; display:flex; gap:8px;">
            <a class="btn btn-outline" href="machines.php">กลับไปหน้ารายการ</a>
            <?php if ($isAdmin): ?>
              <a class="btn btn-brand" href="machine_edit.php?id=<?= (int)$machine['machine_id'] ?>">แก้ไขข้อมูล</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>
  </main>
</div>

<script>
// SweetAlert2: ยืนยันก่อนลบ
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-del').forEach(form => {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const code = "<?= htmlspecialchars($machine['code'], ENT_QUOTES) ?>";
      Swal.fire({
        icon:'warning', title:'ยืนยันการลบ?', text:`ลบคัน: "${code}"`,
        showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก',
        reverseButtons:true, confirmButtonColor:'#fec201'
      }).then(res => { if (res.isConfirmed) form.submit(); });
    });
  });
});
</script>
</body>
</html>
