<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$user = $_SESSION['user'] ?? null;
$isAdmin = ($user && ($user['role'] === 2 || $user['role'] === '2' || $user['role'] === 'admin'));
$active = basename($_SERVER['PHP_SELF']);
function active($file)
{
  global $active;
  return $active === $file ? 'active' : '';
}
?>
<aside class="sidebar">
  <div class="side-head">
    <span class="side-title">เมนู</span>
  </div>
  <ul class="menu">
    <li><a class="<?= active('dashboard.php') ?>" href="dashboard.php">
        <span class="icon">🏠</span><span class="item-label">แดชบอร์ด</span></a></li>

    <li><a class="<?= active('machines.php') ?>" href="machines.php">
        <span class="icon">🚜</span><span class="item-label">ทะเบียนรถ</span></a></li>

    <li><a class="<?= active('customers.php') ?>" href="customers.php">
        <span class="icon">👷‍♂️</span><span class="item-label">ผู้ซื้อ</span></a></li>

    <li><a class="<?= active('suppliers.php') ?>" href="suppliers.php">
        <span class="icon">🏷️</span><span class="item-label">ผู้ขาย</span></a>
    </li>

    <li><a class="<?= active('acquisitions.php') ?>" href="acquisitions.php">
        <span class="icon">🧾</span><span class="item-label">ซื้อเข้า</span></a></li>

    <li><a class="<?= active('expenses.php') ?>" href="expenses.php">
        <span class="icon">🛠️</span><span class="item-label">ค่าใช้จ่าย</span></a></li>

    <li><a class="<?= active('sales.php') ?>" href="sales.php">
        <span class="icon">💸</span><span class="item-label">ขายออก</span></a></li>

    <li><a class="<?= active('reports.php') ?>" href="reports.php">
        <span class="icon">📈</span><span class="item-label">รายงาน</span></a></li>

    <!-- <?php if ($isAdmin): ?> -->
    <li class="sep"></li>
    <li><a class="<?= active('users.php') ?>" href="users.php">
        <span class="icon">👤</span><span class="item-label">ผู้ใช้งาน</span></a>
    </li>
    <li><a class="<?= active('manage.php') ?>" href="manage.php">
        <span class="icon">🗒️</span><span class="item-label">จัดการข้อมูล</span></a>
    </li>
    <li><a class="<?= active('models.php') ?>" href="models.php">
        <span class="icon">🗒️</span><span class="item-label">รุ่น</span></a>
    </li>
    <li><a class="<?= active('status_history.php') ?>" href="status_history.php">
        <span class="icon">🕘</span><span class="item-label">ประวัติสถานะรถ</span>
      </a></li>
    <!-- <li><a class="<?= active('acquisitions.php') ?>" href="acquisitions.php">
        <span class="icon">🕘</span><span class="item-label">acquisitions</span>
      </a></li> -->
    <li><a class="<?= active('sales.php') ?>" href="sales.php">
        <span class="icon">🕘</span><span class="item-label">sales</span>
      </a></li>
    <li><a class="<?= active('receipts.php') ?>" href="receipts.php">
        <span class="icon">💳</span><span class="item-label">ใบรับเงิน</span>
      </a></li>
    <li><a class="<?= active('expenses.php') ?>" href="expenses.php">
        <span class="icon">💳</span><span class="item-label">สถานะค่าใช้จ่าย</span>
      </a></li>

    <!-- <?php endif; ?> -->
  </ul>
</aside>