<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$ok  = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';

/* โหลดรายชื่อยี่ห้อ */
$brands = $pdo->query("SELECT brand_id, brand_name FROM brands ORDER BY brand_name")->fetchAll(PDO::FETCH_ASSOC);

/* บันทึก */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  $brand_id   = (int)($_POST['brand_id'] ?? 0);
  $model_name = trim($_POST['model_name'] ?? '');
  // บีบช่องว่างหลายอันให้เหลือช่องว่างเดียว
  $model_name = preg_replace('/\s+/u', ' ', $model_name);

  if ($brand_id <= 0)           $errors[] = 'เลือกยี่ห้อ';
  if ($model_name === '')       $errors[] = 'กรอกชื่อรุ่น';

  // กันชื่อซ้ำ (brand_id, model_name)
  if (!$errors) {
    $chk = $pdo->prepare("SELECT 1 FROM models WHERE brand_id=? AND model_name=? LIMIT 1");
    $chk->execute([$brand_id, $model_name]);
    if ($chk->fetch()) $errors[] = 'มีรุ่นนี้ในยี่ห้อนี้แล้ว';
  }

  if (!$errors) {
    try {
      $ins = $pdo->prepare("INSERT INTO models (brand_id, model_name) VALUES (?, ?)");
      $ins->execute([$brand_id, $model_name]);
      header('Location: models.php?ok=' . urlencode('เพิ่มรุ่นเรียบร้อย'));
      exit;
    } catch (PDOException $e) {
      // ดัก duplicate index เผื่อกรณี race condition
      if (($e->errorInfo[1] ?? null) == 1062) {
        $errors[] = 'มีรุ่นนี้ในยี่ห้อนี้แล้ว';
      } else {
        $errors[] = 'บันทึกไม่สำเร็จ: ' . $e->getCode();
      }
    }
  }
}
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>เพิ่มรุ่น — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=1">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">เพิ่มรุ่น</h2>
        <div class="page-sub">ผูกกับยี่ห้อ — หลีกเลี่ยงชื่อซ้ำในยี่ห้อเดียวกัน</div>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <section class="card">
        <form method="post" id="addForm" novalidate>
          <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <label>ยี่ห้อ</label>
          <select class="select" name="brand_id" required>
            <option value="">— เลือก —</option>
            <?php foreach ($brands as $b): ?>
              <option value="<?= $b['brand_id'] ?>" <?= (($_POST['brand_id'] ?? '') == $b['brand_id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($b['brand_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label style="margin-top:10px;">ชื่อรุ่น</label>
          <input class="input" type="text" name="model_name" required
            value="<?= htmlspecialchars($_POST['model_name'] ?? '') ?>">

          <div style="margin-top:14px;display:flex;gap:8px;">
            <button class="btn btn-brand" type="submit">บันทึก</button>
            <a class="btn btn-outline" href="manage.php">ยกเลิก</a>
          </div>
        </form>
      </section>
    </main>
  </div>

  <script>
    // เตือนสวย ๆ ตอน submit (กันลืมเลือก/กรอก)
    document.getElementById('addForm').addEventListener('submit', (e) => {
      const brand = document.querySelector('[name="brand_id"]').value;
      const name = (document.querySelector('[name="model_name"]').value || '').trim();
      if (!brand) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'เลือกยี่ห้อ',
          confirmButtonColor: '#fec201'
        });
        return;
      }
      if (!name) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'กรอกชื่อรุ่น',
          confirmButtonColor: '#fec201'
        });
        return;
      }
    });
  </script>
</body>

</html>