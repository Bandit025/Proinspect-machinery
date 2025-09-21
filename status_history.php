<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
  exit;
}
$isAdmin = ((int)($_SESSION['user']['role'] ?? 1) === 2);

$ok  = $_GET['ok']    ?? '';
$err = $_GET['error'] ?? '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function status_label_th(int $x): string {
  // ปรับตามระบบจริงถ้าใช้ status > 5
  return [
    1 => 'รับเข้า',
    2 => 'พร้อมขาย',
    3 => 'ติดจอง',
    4 => 'ขายแล้ว',
    5 => 'ซ่อมบำรุง'
  ][$x] ?? 'รับเข้า';
}

/* ===== Filters ===== */
$q   = trim($_GET['q'] ?? '');          // ค้นหา code/brand/model/note/user_changed
$mid = (int)($_GET['machine_id'] ?? 0); // กรองตามคัน
$st  = (int)($_GET['status'] ?? 0);     // กรองสถานะ

/* ===== Load machines for filter ===== */
$machines = $pdo->query("
  SELECT m.machine_id, m.code, b.brand_name, mo.model_name
  FROM machines m
  JOIN models mo ON mo.model_id = m.model_id
  JOIN brands b ON b.brand_id = mo.brand_id
  ORDER BY m.machine_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===== Query ===== */
$sql = "SELECT h.history_id, h.machine_id, h.status, h.changed_at, h.user_changed, h.note,
               m.code, b.brand_name, mo.model_name
        FROM machine_status_history h
        JOIN machines m ON m.machine_id = h.machine_id
        JOIN models mo  ON mo.model_id  = m.model_id
        JOIN brands b   ON b.brand_id   = mo.brand_id
        WHERE 1=1";
$params = [];
if ($q !== '') {
  $sql .= " AND (m.code LIKE :q OR b.brand_name LIKE :q OR mo.model_name LIKE :q OR h.note LIKE :q OR h.user_changed LIKE :q)";
  $params[':q'] = "%{$q}%";
}
if ($mid > 0) {
  $sql .= " AND h.machine_id = :mid";
  $params[':mid'] = $mid;
}
if ($st  > 0) {
  $sql .= " AND h.status = :st";
  $params[':st']  = $st;
}
$sql .= " ORDER BY h.changed_at DESC, h.history_id DESC";

$stm = $pdo->prepare($sql);
$stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ประวัติสถานะรถ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=16">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">ประวัติการเปลี่ยนสถานะรถ</h2>
        <div class="page-sub">บันทึก/ค้นหา/แก้ไข/ลบ</div>
      </div>

      <?php if ($ok): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

      <section class="card">
        <div class="card-head">
          <h3 class="h5">รายการ</h3>

          <!-- ใช้ .toolbar + .search-form เพื่อให้ CSS จัดเรียงบน→ล่างอัตโนมัติบนมือถือ -->
          <div class="toolbar">
            <form method="get" action="status_history.php" class="search-form">
              <input class="input sm" type="text" name="q"
                     placeholder="ค้นหา: รหัส/ยี่ห้อ/รุ่น/หมายเหตุ/ผู้เปลี่ยน"
                     value="<?= h($q) ?>">

              <select class="select sm" name="machine_id">
                <option value="0">ทุกคัน</option>
                <?php foreach ($machines as $m): ?>
                  <option value="<?= (int)$m['machine_id'] ?>" <?= $mid === (int)$m['machine_id'] ? 'selected' : ''; ?>>
                    <?= h($m['code'].' — '.$m['brand_name'].' '.$m['model_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <select class="select sm" name="status">
                <option value="0">ทุกสถานะ</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <option value="<?= $i ?>" <?= $st === $i ? 'selected' : ''; ?>><?= h(status_label_th($i)) ?></option>
                <?php endfor; ?>
              </select>

              <button class="btn btn-outline sm" type="submit">ค้นหา</button>
            </form>

            <?php if ($isAdmin): ?>
              <a class="btn btn-brand sm add-btn" href="status_history_add.php">เพิ่มประวัติ</a>
            <?php endif; ?>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:120px;">เมื่อเวลา</th>
                <th>รถ</th>
                <th style="width:120px;">สถานะ</th>
                <th style="width:180px;">ผู้เปลี่ยน</th>
                <th>หมายเหตุ</th>
                <!--<th class="tr" style="width:160px;">จัดการ</th>-->
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?= h($r['changed_at']) ?></td>
                  <td>
                    <div><strong><?= h($r['code']) ?></strong></div>
                    <div class="muted"><?= h($r['brand_name'].' '.$r['model_name']) ?></div>
                  </td>
                  <td><?= h(status_label_th((int)$r['status'])) ?></td>
                  <td><?= h($r['user_changed']) ?></td>
                  <td><?= h($r['note'] ?? '-') ?></td>
                  <!--<td class="tr">-->
                  <!--  <a class="link" href="status_history_edit.php?id=<?= (int)$r['history_id'] ?>">แก้ไข</a>-->
                  <!--  <?php if ($isAdmin): ?>-->
                  <!--    ·-->
                  <!--    <form action="status_history_delete.php" method="post"-->
                  <!--          class="js-del"-->
                  <!--          data-info="<?= h($r['code'].' / '.status_label_th((int)$r['status'])) ?>"-->
                  <!--          style="display:inline;">-->
                  <!--      <input type="hidden" name="csrf" value="<?= $csrf ?>">-->
                  <!--      <input type="hidden" name="id" value="<?= (int)$r['history_id'] ?>">-->
                  <!--      <input type="hidden" name="return" value="<?= h($_SERVER['REQUEST_URI']) ?>">-->
                  <!--      <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>-->
                  <!--    </form>-->
                  <!--  <?php endif; ?>-->
                  <!--</td>-->
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr><td colspan="6" class="muted">ไม่พบข้อมูล</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <!-- ให้ปุ่ม Hamburger/Sidebar ทำงานแน่ ๆ -->
  <script src="assets/script.js?v=7"></script>
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
