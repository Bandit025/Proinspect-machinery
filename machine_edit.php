<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function status_options()
{
  return [1 => 'รับเข้า', 2 => 'พร้อมขาย', 3 => 'จอง', 4 => 'ขายแล้ว', 5 => 'ตัดจำหน่าย'];
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: machines.php?error=' . urlencode('ไม่พบข้อมูลรถ'));
  exit;
}

/* โหลดข้อมูลรถ + ยี่ห้อ + ชื่อสถานที่ (ถ้ามี) */
$stm = $pdo->prepare("
  SELECT m.*,
         mo.model_name,
         b.brand_name,
         l.location_name
  FROM machines m
  JOIN models mo    ON mo.model_id = m.model_id
  JOIN brands b     ON b.brand_id  = mo.brand_id
  LEFT JOIN locations l ON l.location_id = m.location
  WHERE m.machine_id = ?
  LIMIT 1
");
$stm->execute([$id]);
$m = $stm->fetch(PDO::FETCH_ASSOC);
if (!$m) {
  header('Location: machines.php?error=' . urlencode('ไม่พบข้อมูลรถ'));
  exit;
}

/* โหลดรายการรุ่น + ยี่ห้อ (ใช้เพื่อแปลง model_id -> brand_id ตอนบันทึก) */
$models = $pdo->query("
  SELECT mo.model_id, mo.model_name, mo.brand_id, b.brand_name
  FROM models mo
  JOIN brands b ON b.brand_id = mo.brand_id
  ORDER BY b.brand_name, mo.model_name
")->fetchAll(PDO::FETCH_ASSOC);

/* โหลดสถานที่ */
$locs = $pdo->query("SELECT location_id, location_name FROM locations ORDER BY location_name")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[] = 'CSRF token ไม่ถูกต้อง';

  // รับค่า
  $code        = trim($_POST['code'] ?? '');
  if ($code === '') $code = '-';

  $model_id_in = (int)($_POST['model_id'] ?? 0);   // รุ่นที่ผู้ใช้เลือก (อาจว่าง = ไม่เปลี่ยน)
  $model_year  = ($_POST['model_year'] !== '') ? (int)$_POST['model_year'] : null;
  $serial_no   = trim($_POST['serial_no'] ?? '');
  $engine_no   = trim($_POST['engine_no'] ?? '');
  $hour_meter  = ($_POST['hour_meter'] !== '') ? (int)$_POST['hour_meter'] : null;
  $color       = trim($_POST['color'] ?? '');
  $weight      = ($_POST['weight_class_ton'] !== '') ? (float)$_POST['weight_class_ton'] : null;
  $status      = (int)($_POST['status'] ?? 1);
  $location    = ($_POST['location'] !== '') ? (int)$_POST['location'] : null;

  // hidden ตัวเลขจริง (ไม่มีคอมมา)
  $purchase    = ($_POST['purchase_price'] !== '') ? (float)$_POST['purchase_price'] : null;
  $asking      = ($_POST['asking_price']   !== '') ? (float)$_POST['asking_price']   : null;
  $notes       = trim($_POST['notes'] ?? '');
  $image_path  = $m['image_path'];

  // กัน code ซ้ำ (ยกเว้น '-')
  if ($code !== '-') {
    $chk = $pdo->prepare("SELECT 1 FROM machines WHERE code = ? AND machine_id <> ? LIMIT 1");
    $chk->execute([$code, $id]);
    if ($chk->fetch()) $errors[] = 'เลขทะเบียน/รหัสรถนี้มีอยู่แล้วในระบบ';
  }

  // ตัดสินใจ model_id ที่จะบันทึก (ถ้าไม่เลือก ถือว่าใช้ค่าปัจจุบัน)
  $model_id_to_save = (int)$m['model_id'];
  if ($model_id_in > 0) {
    $stm2 = $pdo->prepare("SELECT 1 FROM models WHERE model_id=? LIMIT 1");
    $stm2->execute([$model_id_in]);
    if (!$stm2->fetch()) $errors[] = 'ไม่พบรุ่นที่เลือก';
    else $model_id_to_save = $model_id_in;
  }

  // อัปโหลดรูป (เดิม)
  if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
      $errors[] = 'อัปโหลดรูปไม่สำเร็จ';
    } else {
      $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
        $errors[] = 'ชนิดไฟล์รูปไม่รองรับ';
      } else {
        $dir = __DIR__ . '/uploads/machines';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $fname = 'm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $full  = $dir . '/' . $fname;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $full)) {
          $errors[] = 'บันทึกรูปไม่สำเร็จ';
        } else {
          if (!empty($image_path)) {
            $old = __DIR__ . '/' . ltrim($image_path, '/');
            if (is_file($old)) @unlink($old);
          }
          $image_path = 'uploads/machines/' . $fname;
        }
      }
    }
  }

  if (!$errors) {
    // อัปเดต: ใช้ model_id (ไม่ใช่ brand_id)
    $upd = $pdo->prepare("UPDATE machines SET
      code=?, model_id=?, model_year=?, serial_no=?, engine_no=?, hour_meter=?, color=?, weight_class_ton=?,
      status=?, location=?, purchase_price=?, asking_price=?, notes=?, image_path=?, updated_at=NOW()
      WHERE machine_id=?");
    $ok = $upd->execute([
      $code,
      $model_id_to_save,
      $model_year,
      $serial_no,
      $engine_no,
      $hour_meter,
      $color,
      $weight,
      $status,
      $location,
      $purchase,
      $asking,
      $notes,
      $image_path,
      $id
    ]);

    if ($ok) {
      header('Location: machines.php?ok=' . urlencode('บันทึกการแก้ไขเรียบร้อย'));
      exit;
    }
    $errors[] = 'บันทึกไม่สำเร็จ';
  }
}

?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>แก้ไขรถ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=31">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">แก้ไขรถ #<?= (int)$m['machine_id'] ?></h2>
        <div class="page-sub">
          <?= htmlspecialchars($m['code']) ?>
          · ยี่ห้อ/รุ่น: <?= htmlspecialchars($m['brand_name'] . ' — ' . $m['model_name']) ?>
          <?= $m['location_name'] ? ' · สถานที่: ' . htmlspecialchars($m['location_name']) : '' ?>
        </div>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <section class="card">
        <form method="post" enctype="multipart/form-data" id="editForm" novalidate>
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="id" value="<?= $m['machine_id'] ?>">

          <label>เลขทะเบียน/รหัสรถ</label>
          <input class="input" type="text" name="code" value="<?= htmlspecialchars($_POST['code'] ?? $m['code']) ?>"
            placeholder="เว้นว่างได้ ระบบจะตั้งเป็น - ให้อัตโนมัติ">

          <!-- รุ่น (เลือกเพื่อเปลี่ยนยี่ห้อ) -->
          <label style="margin-top:10px;">รุ่น (ยี่ห้อ — รุ่น) <span class="muted">(เลือกเพื่อเปลี่ยนยี่ห้อของรถ)</span></label>
          <select class="select" name="model_id">
            <option value="">— ไม่เปลี่ยนรุ่น/ยี่ห้อ —</option>
            <?php foreach ($models as $mo): ?>
              <option value="<?= $mo['model_id'] ?>" <?= (($_POST['model_id'] ?? '') == $mo['model_id']) ? 'selected' : ''; ?>>
                <?= htmlspecialchars($mo['brand_name'] . ' — ' . $mo['model_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <div class="row" style="margin-top:10px;">
            <div>
              <label>ปีรุ่น</label>
              <select class="select" name="model_year">
                <option value="">— เลือกปี —</option>
                <?php
                $currentYear = (int)date('Y');
                $cur = (int)($m['model_year'] ?? 0);
                $in = (int)($_POST['model_year'] ?? 0);
                $sel = $in ?: $cur;
                for ($y = $currentYear; $y >= 1990; $y--) {
                  $s = ($sel === $y) ? 'selected' : '';
                  echo "<option value=\"$y\" $s>$y</option>";
                }
                ?>
              </select>
            </div>
            <div>
              <label>ชั่วโมงใช้งาน</label>
              <input class="input" type="number" name="hour_meter" value="<?= htmlspecialchars($_POST['hour_meter'] ?? $m['hour_meter']) ?>">
            </div>
          </div>

          <div class="row" style="margin-top:10px;">
            <div><label>เลขตัวถัง</label><input class="input" type="text" name="serial_no" value="<?= htmlspecialchars($_POST['serial_no'] ?? $m['serial_no']) ?>"></div>
            <div><label>เลขเครื่อง</label><input class="input" type="text" name="engine_no" value="<?= htmlspecialchars($_POST['engine_no'] ?? $m['engine_no']) ?>"></div>
          </div>

          <div class="row" style="margin-top:10px;">
            <div><label>สี</label><input class="input" type="text" name="color" value="<?= htmlspecialchars($_POST['color'] ?? $m['color']) ?>"></div>
            <div><label>น้ำหนัก (ตัน)</label><input class="input" type="number" step="0.01" name="weight_class_ton" value="<?= htmlspecialchars($_POST['weight_class_ton'] ?? $m['weight_class_ton']) ?>"></div>
          </div>

          <div class="row" style="margin-top:10px;">
            <div>
              <label>สถานะ</label>
              <select class="select" name="status">
                <?php foreach (status_options() as $k => $v): ?>
                  <option value="<?= $k ?>" <?= (int)($_POST['status'] ?? $m['status']) === $k ? 'selected' : ''; ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>สถานที่ปัจจุบัน</label>
              <select class="select" name="location">
                <option value="">— เลือก —</option>
                <?php
                $curLoc = (int)($m['location'] ?? 0);
                $inLoc  = (int)($_POST['location'] ?? 0);
                $selLoc = $inLoc ?: $curLoc;
                foreach ($locs as $loc): ?>
                  <option value="<?= $loc['location_id'] ?>" <?= ($selLoc === $loc['location_id']) ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($loc['location_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- ราคาพร้อมคอมม่า (ช่องมองเห็น + hidden ค่าจริง) -->
          <?php
          $pp_post = $_POST['purchase_price'] ?? '';
          $ap_post = $_POST['asking_price'] ?? '';
          $pp_view = $pp_post !== '' ? number_format((float)$pp_post, 2) : ($m['purchase_price'] !== null ? number_format((float)$m['purchase_price'], 2) : '');
          $ap_view = $ap_post !== '' ? number_format((float)$ap_post, 2) : ($m['asking_price']  !== null ? number_format((float)$m['asking_price'], 2)   : '');
          ?>
          <div class="row" style="margin-top:10px;">
            <div>
              <label>ราคาซื้อ</label>
              <input class="input" type="text" inputmode="decimal" id="purchase_price_view" value="<?= htmlspecialchars($pp_view) ?>" placeholder="0.00">
              <input type="hidden" name="purchase_price" id="purchase_price" value="<?= htmlspecialchars($pp_post !== '' ? $pp_post : ($m['purchase_price'] ?? '')) ?>">
            </div>
            <div>
              <label>ราคาตั้งขาย</label>
              <input class="input" type="text" inputmode="decimal" id="asking_price_view" value="<?= htmlspecialchars($ap_view) ?>" placeholder="0.00">
              <input type="hidden" name="asking_price" id="asking_price" value="<?= htmlspecialchars($ap_post !== '' ? $ap_post : ($m['asking_price'] ?? '')) ?>">
            </div>
          </div>

          <label style="margin-top:10px;">หมายเหตุ</label>
          <input class="input" type="text" name="notes" value="<?= htmlspecialchars($_POST['notes'] ?? $m['notes']) ?>">

          <label style="margin-top:10px;">รูปหลัก (ออปชัน)</label>
          <?php if (!empty($m['image_path'])): ?>
            <div class="muted" style="margin:4px 0 6px;">รูปปัจจุบัน: <a class="link" href="<?= htmlspecialchars($m['image_path']) ?>" target="_blank" rel="noopener">เปิดดู</a></div>
          <?php endif; ?>
          <input class="input" type="file" name="image" accept=".jpg,.jpeg,.png,.webp,.gif">

          <div style="margin-top:14px;display:flex;gap:8px;">
            <button class="btn btn-brand" type="submit">บันทึก</button>
            <a class="btn btn-outline" href="machines.php">ยกเลิก</a>
          </div>
        </form>
      </section>
    </main>
  </div>
    <script src="assets/script.js?v=3"></script>
  <script>
    document.getElementById('editForm').addEventListener('submit', (e) => {
      e.preventDefault();
      Swal.fire({
          icon: 'question',
          title: 'ยืนยันการบันทึกการแก้ไข?',
          showCancelButton: true,
          confirmButtonText: 'บันทึก',
          cancelButtonText: 'ยกเลิก',
          reverseButtons: true,
          confirmButtonColor: '#fec201'
        })
        .then(res => {
          if (res.isConfirmed) e.target.submit();
        });
    });
  </script>

  <!-- มาสก์ตัวเลขให้มีคอมม่า (เหมือนหน้า add) -->
  <script>
    (function() {
      const fmt = new Intl.NumberFormat('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });

      function onlyNumberDot(s) {
        s = (s || '').toString().replace(/[^0-9.]/g, '');
        const parts = s.split('.');
        if (parts.length > 2) s = parts[0] + '.' + parts.slice(1).join('');
        return s;
      }

      function toNumber(s) {
        const n = parseFloat(onlyNumberDot((s || '').toString().replace(/,/g, '')));
        return isNaN(n) ? '' : n;
      }

      function addMask(viewId, hiddenId) {
        const v = document.getElementById(viewId);
        const h = document.getElementById(hiddenId);
        if (!v || !h) return;

        if (h.value !== '') {
          const n = toNumber(h.value);
          if (n !== '') v.value = fmt.format(n);
        }

        v.addEventListener('input', () => {
          const start = v.selectionStart;
          const before = v.value;
          const raw = onlyNumberDot(before.replace(/,/g, ''));
          let [int = '', dec = ''] = raw.split('.');
          dec = dec.slice(0, 2);
          int = int.replace(/^0+(?=\d)/, '');
          const withCommas = int.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (dec ? '.' + dec : '');
          v.value = withCommas;
          const n = toNumber(v.value);
          h.value = n === '' ? '' : n.toFixed(2);

          const diff = withCommas.length - before.length;
          const pos = Math.max(0, (start || 0) + diff);
          requestAnimationFrame(() => v.setSelectionRange(pos, pos));
        });

        v.addEventListener('blur', () => {
          const n = toNumber(v.value);
          if (n === '') {
            v.value = '';
            h.value = '';
            return;
          }
          v.value = fmt.format(n);
          h.value = n.toFixed(2);
        });
      }

      addMask('purchase_price_view', 'purchase_price');
      addMask('asking_price_view', 'asking_price');

      document.getElementById('editForm').addEventListener('submit', () => {
        ['purchase_price', 'asking_price'].forEach(id => {
          const h = document.getElementById(id),
            v = document.getElementById(id + '_view');
          if (h && v) {
            const n = toNumber(v.value);
            h.value = n === '' ? '' : n.toFixed(2);
          }
        });
      });
    })();
  </script>
</body>

</html>