<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน')); exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  header('Location: machines.php?error=' . urlencode('ไม่พบข้อมูลรถ')); exit;
}

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function thb($n){ return $n !== null ? '฿' . number_format((float)$n, 2) : '-'; }
function status_label_th(int $x): string {
  return [1=>'รับเข้า',2=>'พร้อมขาย',3=>'จอง',4=>'ขายแล้ว',5=>'ตัดจำหน่าย'][$x] ?? 'รับเข้า';
}
function status_badge_class(int $x): string {
  return match($x){
    2 => 'success', 3 => 'warn', 4 => 'gray', default => 'gray'
  };
}

/* ===== Query: ดึงรุ่น/ยี่ห้อ + ชื่อสถานที่ (ถ้ามี) ===== */
$stm = $pdo->prepare("
  SELECT
    m.*,
    mo.model_name,
    b.brand_name,
    l.location_name
  FROM machines m
  LEFT JOIN models mo    ON mo.model_id   = m.model_id
  LEFT JOIN brands b     ON b.brand_id    = mo.brand_id
  LEFT JOIN locations l  ON l.location_id = m.location
  WHERE m.machine_id = ?
  LIMIT 1
");
$stm->execute([$id]);
$m = $stm->fetch(PDO::FETCH_ASSOC);

if (!$m) {
  header('Location: machines.php?error=' . urlencode('ไม่พบข้อมูลรถ')); exit;
}

/* แปลงค่าบางช่องให้อ่านง่าย */
$locationDisp = $m['location_name'] ?: ($m['location'] ?: '-');
$brandModel   = trim(($m['brand_name'] ?? '') . ' ' . ($m['model_name'] ?? '')) ?: '-';
$statusInt    = (int)($m['status'] ?? 0);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>รายละเอียดรถ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=26">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* Layout รายละเอียด */
    .detail-grid{ display:grid; grid-template-columns: 1fr 1fr; gap:14px; align-items:start; }
    @media(max-width:900px){ .detail-grid{ grid-template-columns: 1fr; } }

    /* กล่องรูป: คุมอัตราส่วน 16:9 + ครอบภาพสวย ๆ */
    .photo{ border:1px solid #eee; border-radius:14px; overflow:hidden; background:#fff; }
    .photo .ph{ display:grid; place-items:center; height:280px; color:#999; }
    .photo .ratio{ position:relative; width:100%; aspect-ratio:16/9; background:#fafafa; }
    .photo .ratio img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }

    /* Key–Value */
    .kv{ display:grid; grid-template-columns: 160px 1fr; gap:8px; padding:8px 0; border-bottom:1px dashed var(--line); }
    .kv:last-child{ border-bottom:0; }
    .kv .k{ color:var(--muted); }

    /* ปุ่มด้านบนขวา */
    .actions{ display:flex; gap:8px; flex-wrap:wrap; }

    /* เงินชิดขวาเล็กน้อย */
    .money{ font-weight:700; text-align:left; }

    @media (max-width:560px){
      .kv{ grid-template-columns: 120px 1fr; }
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-end;gap:10px;flex-wrap:wrap;">
        <div>
          <h2 class="page-title" style="margin-bottom:2px;">รายละเอียดรถ</h2>
          <div class="page-sub"><?= h($m['code']) ?> · <?= h($brandModel) ?></div>
        </div>
        <div class="actions">
          <a class="btn btn-outline sm" href="machines.php">← กลับรายการ</a>
          <a class="btn btn-brand sm" href="machine_edit.php?id=<?= (int)$m['machine_id'] ?>">แก้ไข</a>
        </div>
      </div>

      <section class="card">
        <div class="detail-grid">
          <div class="photo">
            <?php if (!empty($m['image_path'])): ?>
              <div class="ratio">
                <img src="<?= h($m['image_path']) ?>" alt="Machine photo">
              </div>
            <?php else: ?>
              <div class="ph">ไม่มีรูป</div>
            <?php endif; ?>
          </div>

          <div>
            <div class="kv">
              <div class="k">รหัสรถ</div>
              <div><strong><?= h($m['code']) ?></strong></div>
            </div>
            <div class="kv">
              <div class="k">ยี่ห้อ / รุ่น</div>
              <div><?= h($brandModel) ?></div>
            </div>
            <div class="kv">
              <div class="k">ปีรุ่น</div>
              <div><?= h($m['model_year'] ?? '-') ?></div>
            </div>
            <div class="kv">
              <div class="k">เลขตัวถัง</div>
              <div><?= h($m['serial_no'] ?? '-') ?></div>
            </div>
            <div class="kv">
              <div class="k">เลขเครื่อง</div>
              <div><?= h($m['engine_no'] ?? '-') ?></div>
            </div>
            <div class="kv">
              <div class="k">ชั่วโมงใช้งาน</div>
              <div><?= h($m['hour_meter'] ?? '-') ?></div>
            </div>
            <div class="kv">
              <div class="k">น้ำหนัก (ตัน)</div>
              <div><?= h($m['weight_class_ton'] ?? '-') ?></div>
            </div>
            <div class="kv">
              <div class="k">สถานะ</div>
              <div>
                <span class="badge <?= status_badge_class($statusInt) ?>">
                  <?= h(status_label_th($statusInt)) ?>
                </span>
              </div>
            </div>
            <div class="kv">
              <div class="k">สถานที่</div>
              <div><?= h($locationDisp) ?></div>
            </div>
            <div class="kv">
              <div class="k">ราคาตั้งขาย</div>
              <div class="money"><?= thb($m['asking_price']) ?></div>
            </div>
            <div class="kv">
              <div class="k">หมายเหตุ</div>
              <div><?= nl2br(h($m['notes'] ?? '-')) ?></div>
            </div>
            <div class="kv">
              <div class="k">บันทึกเมื่อ</div>
              <div><?= h($m['created_at']) ?></div>
            </div>
            <div class="kv">
              <div class="k">อัปเดตล่าสุด</div>
              <div><?= h($m['updated_at']) ?></div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <!-- สำคัญสำหรับ Sidebar/Hamburger -->
  <script src="assets/script.js?v=4"></script>
</body>
</html>
