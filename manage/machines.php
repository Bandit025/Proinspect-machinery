<?php
// machines.php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
  exit;
}
$user = $_SESSION['user'];
$isAdmin = ((int)($user['role'] ?? 1) === 2);

// flash
$ok  = $_GET['ok']    ?? '';
$err = $_GET['error'] ?? '';

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ===== helper functions ===== */
function column_exists(PDO $pdo, string $table, string $col): bool
{
  $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $q->execute([$table, $col]);
  return (int)$q->fetchColumn() > 0;
}
function status_label_th(int $c): string
{
  // 1..5 -> ไทย
  return [1 => 'รับเข้า', 2 => 'พร้อมขาย', 3 => 'จอง', 4 => 'ขายแล้ว', 5 => 'ตัดจำหน่าย'][$c] ?? 'รับเข้า';
}
function resolve_status_info(PDO $pdo): array
{
  // หา column ที่ใช้เก็บสถานะ + ชนิดข้อมูล
  $info = ['col' => null, 'is_enum' => false, 'is_numeric' => false];
  foreach (['status_code', 'status'] as $c) {
    $q = $pdo->prepare("SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='machines' AND COLUMN_NAME=? LIMIT 1");
    $q->execute([$c]);
    if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
      $info['col'] = $c;
      $dt = strtolower($row['DATA_TYPE'] ?? '');
      $ct = strtolower($row['COLUMN_TYPE'] ?? '');
      $info['is_enum']    = (strpos($ct, 'enum(') === 0);
      $info['is_numeric'] = in_array($dt, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'], true);
      break;
    }
  }
  // ถ้าไม่พบ ให้สมมติเป็น status (numeric)
  if (!$info['col']) {
    $info = ['col' => 'status', 'is_enum' => false, 'is_numeric' => true];
  }
  return $info;
}

$hasPhoto = column_exists($pdo, 'machines', 'photo_main');
$stInfo   = resolve_status_info($pdo);

// expression สถานะสำหรับ SELECT/WHERE
if ($stInfo['is_enum']) {
  // แปลง ENUM (คาดว่าค่าเดิมเป็นภาษาอังกฤษ) -> 1..5
  $statusExpr = "CASE {$stInfo['col']}
      WHEN 'inbound'   THEN 1
      WHEN 'available' THEN 2
      WHEN 'reserved'  THEN 3
      WHEN 'sold'      THEN 4
      WHEN 'retired'   THEN 5
      ELSE 1 END";
} else {
  // numeric อยู่แล้ว (status หรือ status_code)
  $statusExpr = "m.`{$stInfo['col']}`";
}

/* ===== query params ===== */
$q  = trim($_GET['q'] ?? '');
$st = (int)($_GET['status'] ?? 0);

$params = [];
$select = "m.machine_id,m.code,m.model_year,{$statusExpr} AS status_val,m.asking_price";
if ($hasPhoto) $select .= ",m.photo_main";
$select .= ",b.brand_name,mo.model_name";

$sql = "SELECT {$select}
        FROM machines m
        JOIN brands b ON b.brand_id = m.brand_id
        JOIN models mo ON mo.model_id = m.model_id
        WHERE 1=1";

if ($q !== '') {
  $sql .= " AND (m.code LIKE :q OR b.brand_name LIKE :q OR mo.model_name LIKE :q OR m.serial_no LIKE :q)";
  $params[':q'] = "%{$q}%";
}
if ($st > 0) {
  // ใช้ expression เดิมในการกรอง
  $sql .= " AND ({$statusExpr}) = :st";
  $params[':st'] = $st;
}
$sql .= " ORDER BY m.machine_id DESC";

$stm = $pdo->prepare($sql);
$stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ทะเบียนรถ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=14">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .thumb {
      width: 72px;
      height: 54px;
      object-fit: cover;
      border-radius: 8px;
      border: 1px solid #eee;
      background: #f7f7f7;
    }

    .badge.gray {
      background: #f2f2f2;
      border: 1px solid #e6e6e6;
      padding: 3px 8px;
      border-radius: 10px;
      font-size: .85rem;
    }

    .table td .muted {
      color: var(--muted);
      font-size: .92rem;
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
        <h2 class="page-title">ทะเบียนรถ</h2>
        <div class="page-sub">แสดง/ค้นหา/กรองสถานะ และจัดการข้อมูลรถ</div>
      </div>

      <?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <section class="card">
        <div class="card-head">
          <h3 class="h5">รายการรถ</h3>
          <div style="display:flex;gap:8px;align-items:center;">
            <form method="get" action="machines.php" style="display:flex;gap:8px;align-items:center;">
              <input class="input" type="text" name="q" placeholder="ค้นหา: รหัส/ยี่ห้อ/รุ่น/เลขตัวถัง" value="<?= htmlspecialchars($q) ?>">
              <select class="select" name="status" style="width:180px;">
                <option value="0">ทุกสถานะ</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <option value="<?= $i ?>" <?= $st === $i ? 'selected' : ''; ?>><?= status_label_th($i) ?></option>
                <?php endfor; ?>
              </select>
              <button class="btn btn-outline sm" type="submit">ค้นหา</button>
            </form>
            <?php if ($isAdmin): ?>
              <a class="btn btn-brand sm" href="machine_add.php">เพิ่มคันใหม่</a>
            <?php endif; ?>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:84px;">รูป</th>
                <th>รหัส / ยี่ห้อรุ่น</th>
                <th style="width:80px;">ปี</th>
                <th style="width:120px;">สถานะ</th>
                <th class="tr" style="width:140px;">ราคาตั้ง</th>
                <th class="tr" style="width:240px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): $id = (int)$r['machine_id']; ?>
                <tr>
                  <td>
                    <?php if (!empty($r['photo_main']) && file_exists(__DIR__ . '/' . $r['photo_main'])): ?>
                      <img class="thumb" src="<?= htmlspecialchars($r['photo_main']) ?>" alt="">
                    <?php else: ?>
                      <div class="thumb" style="display:grid;place-items:center;color:#bbb;">—</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div><strong><?= htmlspecialchars($r['code']) ?></strong></div>
                    <div class="muted"><?= htmlspecialchars($r['brand_name'] . ' ' . $r['model_name']) ?></div>
                  </td>
                  <td><?= htmlspecialchars($r['model_year'] ?? '-') ?></td>
                  <td><span class="badge gray"><?= status_label_th((int)$r['status_val']) ?></span></td>
                  <td class="tr"><?= $r['asking_price'] !== null ? '฿' . number_format($r['asking_price'], 0) : '-' ?></td>
                  <td class="tr">
                    <a class="btn btn-outline sm" href="machine_view.php?id=<?= $id ?>">รายละเอียด</a>

                    <a class="btn btn-outline sm" href="machine_edit.php?id=<?= $id ?>">แก้ไข</a>
                    <form action="machine_delete.php" method="post"
                      class="js-del" data-info="<?= htmlspecialchars($r['code'] . ' — ' . $r['brand_name'] . ' ' . $r['model_name']) ?>"
                      style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= $csrf ?>">
                      <input type="hidden" name="id" value="<?= $r['machine_id'] ?>">
                      <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                      <button type="submit" class="link" style="border:none;background:none;color:#a40000;">
                        ลบ
                      </button>
                    </form>

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
    </main>
  </div>

  <script src="assets/script.js"></script>
  <script>
    // ยืนยันก่อนลบด้วย SweetAlert2 และโชว์ flash จาก ?ok= / ?error=
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('.js-del').forEach(form => {
        form.addEventListener('submit', (e) => {
          e.preventDefault();
          const name = form.dataset.name || '';
          Swal.fire({
            icon: 'warning',
            title: 'ยืนยันการลบ?',
            text: name ? `ลบคัน: "${name}"` : 'ลบรายการนี้',
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true,
            confirmButtonColor: '#fec201'
          }).then(res => {
            if (res.isConfirmed) form.submit();
          });
        });
      });

      <?php if ($ok): ?>
        Swal.fire({
          icon: 'success',
          title: 'สำเร็จ',
          text: '<?= htmlspecialchars($ok, ENT_QUOTES) ?>',
          confirmButtonColor: '#fec201'
        });
      <?php elseif ($err): ?>
        Swal.fire({
          icon: 'error',
          title: 'ไม่สำเร็จ',
          text: '<?= htmlspecialchars($err, ENT_QUOTES) ?>',
          confirmButtonColor: '#fec201'
        });
      <?php endif; ?>
    });
  </script>
</body>

</html>