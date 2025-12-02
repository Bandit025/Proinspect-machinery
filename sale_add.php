<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$machines = $pdo->query("
  SELECT m.machine_id, m.code, b.brand_name, mo.model_name, ms.status_name
  FROM machines m
  JOIN models mo ON mo.model_id = m.model_id
  LEFT JOIN brands b ON b.brand_id = mo.brand_id
  JOIN machine_status ms ON m.status = ms.status_id
  WHERE m.status = 2
  ORDER BY m.machine_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$customers = $pdo->query("
  SELECT customer_id, customer_name
  FROM customers
  ORDER BY customer_name
")->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        $errors[] = 'CSRF token ไม่ถูกต้อง';
    }

    $machine_id      = $_POST['machine_id']      ?? '';
    $customer_id     = $_POST['customer_id']     ?? '';
    $sold_at         = $_POST['sold_at']         ?? date('Y-m-d'); // YYYY-MM-DD
    $type_value      = $_POST['type_value']      ?? '';
    $base_price      = $_POST['base_price']      ?? '0.00';
    $vat_rate_pct    = $_POST['vat_rate_pct']    ?? '0.00';
    $total_amount    = $_POST['total_amount']    ?? '0.00';
    $remark          = $_POST['remark']          ?? '';
    $discount_amt    = $_POST['discount_amt']    ?? '0.00';
    $commission_amt  = $_POST['commission_amt']  ?? '0.00';
    $transport_cost  = $_POST['transport_cost']  ?? '0.00'; // ✅ ใหม่: ค่าขนส่ง

    // --- คำนวณกำไร/ขาดทุน (ใช้ตอนใส่ pl_status / pl_amount) ---
    // 1) ราคาซื้อ
    $stm = $pdo->prepare("SELECT COALESCE(purchase_price,0) FROM machines WHERE machine_id=? LIMIT 1");
    $stm->execute([$machine_id]);
    $purchase_price = (float)$stm->fetchColumn();

    // 2) รวมค่าใช้จ่ายทั้งหมดของรถ (แก้ query ให้เหลือคอลัมน์รวมคอลัมน์เดียว)
    $stm = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM machine_expenses WHERE machine_id=?");
    $stm->execute([$machine_id]);
    $expenses_total = (float)$stm->fetchColumn();

    $sell_total  = (float)$total_amount;
    $commission  = (float)$commission_amt;
    $transport   = (float)$transport_cost; // ✅ ใหม่
    $cost_core   = $purchase_price + $expenses_total;
    $profit      = $sell_total - ($cost_core + $commission + $transport); // ✅ หักค่าขนส่งด้วย

    $pl_status = $profit > 0 ? 1 : ($profit < 0 ? -1 : 0);    // ✅ แก้ -1 แทน 2
    $pl_amount = abs($profit);

    // --- เพิ่มบันทึกการขาย (ใส่ transport_cost + pl_status/pl_amount ด้วย) ---
    $sql_sale = "INSERT INTO sales(
        machine_id, customer_id, type_value, sold_at,
        sale_price, discount_amt, vat_rate_pct, total_amount,
        commission_amt, transport_cost, remark,
        pl_status, pl_amount, created_at
    ) VALUES (
        :machine_id, :customer_id, :type_value, :sold_at,
        :sale_price, :discount_amt, :vat_rate_pct, :total_amount,
        :commission_amt, :transport_cost, :remark,
        :pl_status, :pl_amount, NOW()
    )";
    $stmt = $pdo->prepare($sql_sale);
    $stmt->execute([
        ':machine_id'      => $machine_id,
        ':customer_id'     => $customer_id,
        ':type_value'      => $type_value,
        ':sold_at'         => $sold_at,
        ':sale_price'      => $base_price,
        ':discount_amt'    => $discount_amt,
        ':vat_rate_pct'    => $vat_rate_pct,
        ':total_amount'    => $total_amount,
        ':commission_amt'  => $commission_amt,
        ':transport_cost'  => $transport_cost, // ✅ bind ค่าขนส่ง
        ':remark'          => $remark,
        ':pl_status'       => $pl_status,
        ':pl_amount'       => number_format($pl_amount, 2, '.', ''),
    ]);

    $sale_id = $pdo->lastInsertId();

    // เลขเอกสารขาย
    $finalDocNo = '';
    if ($type_value === '1' || $type_value === '2') {
        $datePart   = date('Ymd', strtotime($sold_at ?: 'now'));
        $finalDocNo = sprintf('TS%s%03d', $datePart, $sale_id);
    }
    $pdo->prepare("UPDATE sales SET doc_no=? WHERE sale_id=?")->execute([$finalDocNo, $sale_id]);

    // เปลี่ยนสถานะรถเป็น ขายแล้ว (3)
    $pdo->prepare("UPDATE machines SET status=4 WHERE machine_id=?")->execute([$machine_id]);

    // บันทึก cashflow รายรับจากการขาย (ไม่รวมค่าขนส่ง — ถือเป็นต้นทุน)
    $sqlCf = "INSERT INTO cashflow
        (type_cashflow, type2_cashflow, machine_id, sale_id, acquisition_id, doc_no, doc_date, amount, remark)
        VALUES
        (:t1, :t2, :mid, :sid, :acq, :doc_no, :doc_date, :amt, :rem)";
    $cf = $pdo->prepare($sqlCf);
    $cf->execute([
        ':t1'       => 1,               // รายรับ
        ':t2'       => 2,               // ขายรถออก
        ':mid'      => $machine_id,
        ':sid'      => $sale_id,
        ':acq'      => null,
        ':doc_no'   => $finalDocNo,
        ':doc_date' => $sold_at,
        ':amt'      => $total_amount,
        ':rem'      => ($remark !== '' ? $remark : 'ขายรถออก'),
    ]);

    header('Location: sales.php?ok=' . urlencode('เพิ่มเอกสารขายเรียบร้อย'));
    exit;
}
?>
<!doctype html>
<html lang="th">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>เพิ่มเอกสารขายรถ — ProInspect Machinery</title>
    <link rel="stylesheet" href="assets/style.css?v=30">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- ✅ Override เฉพาะหน้านี้ เพื่อแก้ปัญหา CSS เดิม -->
    <style>
        /* ให้ใช้กับหน้านี้เท่านั้น เพื่อไม่ไปรบกวนหน้าอื่น */
        .sale-page .card {
            width: 100% !important;
            max-width: none !important;
        }

        /* 2 คอลัมน์: ซ้าย=ฟอร์ม (ยืดได้), ขวา=สรุป (420px) */
        .sale-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 420px;
            gap: 16px;
            align-items: start;
        }

        /* ให้สรุปการขายเหนียวด้านบนเวลาเลื่อน */
        #summaryCard {
            position: sticky;
            top: 12px;
        }

        /* จอแคบ: เป็นคอลัมน์เดียว */
        @media (max-width: 1200px) {
            .sale-grid {
                grid-template-columns: 1fr;
            }

            #summaryCard {
                position: static;
            }
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/navbar.php'; ?>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <!-- ใส่คลาส sale-page เพื่อให้ CSS ด้านบน scope เฉพาะหน้านี้ -->
        <main class="content sale-page">
            <div class="page-head">
                <h2 class="page-title">เพิ่มเอกสารขายรถ</h2>
                <div class="page-sub">กรอกรายละเอียดการขาย ระบบคำนวณ VAT/ยอดรวมอัตโนมัติ</div>
            </div>

            <!-- แถวสองคอลัมน์: ซ้ายฟอร์ม / ขวาสรุป -->
            <div class="sale-grid">
                <!-- ซ้าย: ฟอร์ม -->
                <section class="card">
                    <form method="post" id="addForm" novalidate>
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">

                        <label>รถ (รหัส/ยี่ห้อ/สถานะ)</label>
                        <select class="select" name="machine_id" id="machine_id" required>
                            <option value="">— เลือก —</option>
                            <?php foreach ($machines as $m): ?>
                                <option value="<?= $m['machine_id'] ?>">
                                    <?= htmlspecialchars($m['code'] . ' — ' . $m['brand_name'] . ' - ' . $m['model_name'] . ' (' . $m['status_name'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label style="margin-top:10px;">ลูกค้า</label>
                        <select class="select" name="customer_id" required>
                            <option value="">— เลือก —</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['customer_id'] ?>"><?= htmlspecialchars($c['customer_name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div class="row" style="margin-top:10px;">
                            <div>
                                <label>วันที่ขาย</label>
                                <input class="input" type="date" name="sold_at" required
                                    value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                            </div>
                            <div>
                                <label>ประเภทราคา</label>
                                <select class="select" name="type_value" id="type_value" required>
                                    <option value="" selected>— เลือก —</option>
                                    <option value="1">รวมภาษี</option>
                                    <option value="2">ไม่รวมภาษี</option>
                                    <option value="3">นอกระบบ</option>
                                    <option value="4">มัดจำ</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label id="price_label">ราคา</label>
                            <input class="input money-mask" type="text" inputmode="decimal"
                                id="base_price_view" placeholder="0.00" value="0.00">
                            <input type="hidden" name="base_price" id="base_price" value="0.00">
                            <div id="price_hint" class="muted" style="font-size:.85rem;">
                                เลือกประเภทราคาเพื่อให้ระบบคำนวณภาษี
                            </div>
                        </div>
                        <div class="row" style="margin-top:10px;">
                            <div>
                                <label>ส่วนลด</label>
                                <input class="input money-mask tr" type="text" inputmode="decimal"
                                    id="discount_amt_view" placeholder="0.00" value="0.00">
                                <input type="hidden" name="discount_amt" id="discount_amt" value="0.00">
                            </div>
                            <div>
                                <label>ค่านายหน้า</label>
                                <input class="input money-mask tr" type="text" inputmode="decimal"
                                    id="commission_amt_view" placeholder="0.00" value="0.00">
                                <input type="hidden" name="commission_amt" id="commission_amt" value="0.00">
                            </div>
                        </div>

                        <input type="hidden" name="vat_rate_pct" id="vat_rate_pct" value="7.00">

                        <div class="row" style="margin-top:10px;">
                            <div>
                               
                                <label>ค่าขนส่ง</label>
                                <input class="input money-mask tr" type="text" inputmode="decimal"
                                    id="transport_cost_view" placeholder="0.00" value="0.00">
                                <input type="hidden" name="transport_cost" id="transport_cost" value="0.00">
                           
                                <input class="input money-mask" type="hidden" id="vat_amount_view" readonly value="0.00">
                                <input type="hidden" name="vat_amount" id="vat_amount" value="0.00">
                            </div>
                            <div>
                                <label>ยอดรวม (คำนวณอัตโนมัติ)</label>
                                <input class="input money-mask" type="text" id="total_amount_view" readonly value="0.00">
                                <input type="hidden" name="total_amount" id="total_amount" value="0.00">
                            </div>
                            
                        </div>

                        <label style="margin-top:10px;">หมายเหตุ</label>
                        <input class="input" type="text" name="remark" value="">

                        <div style="margin-top:14px;display:flex;gap:8px;">
                            <button class="btn btn-brand" type="submit">บันทึก</button>
                            <a class="btn btn-outline" href="sales.php">ยกเลิก</a>
                        </div>
                    </form>
                </section>

                <!-- ขวา: สรุปการขาย -->
                <section class="card" id="summaryCard" style="display:none;">
                    <div class="page-head" style="padding:12px 16px 0;">
                        <h3 class="page-title" style="margin:0;">สรุปการขาย</h3>
                    </div>
                    <div id="summaryBody" style="padding:12px 16px 16px;"></div>
                </section>
            </div>

            <!-- ประวัติซื้อ -->
            <section class="card" id="historyCard" style="margin-top:12px; display:none;">
                <div class="page-head" style="padding:12px 16px 0;">
                    <h3 class="page-title" style="margin:0;">ประวัติการซื้อของเครื่องนี้</h3>
                    <div class="page-sub" style="margin-top:4px;">ดึงจากตาราง acquisitions (ล่าสุด 50 รายการ)</div>
                </div>
                <div id="historyBody" class="table-responsive" style="padding:12px 16px 16px;"></div>
            </section>

            <!-- ค่าใช้จ่าย -->
            <section class="card" id="expCard" style="margin-top:12px; display:none;">
                <div class="page-head" style="padding:12px 16px 0;">
                    <h3 class="page-title" style="margin:0;">ค่าใช้จ่ายของรถ</h3>
                    <div class="page-sub" style="margin-top:4px;">ดึงจากตาราง machine_expenses</div>
                </div>
                <div id="expBody" class="table-responsive" style="padding:12px 16px 16px;"></div>
            </section>
        </main>
    </div>

    <script>
        (() => {
            let purchaseTotal = 0;
            let expensesTotal = 0;

            const api = p => new URL(p, window.location.href).toString();
            const toNum = v => {
                v = (v || '').toString().replace(/,/g, '').trim();
                const n = parseFloat(v);
                return isFinite(n) ? n : 0;
            };
            const fmt = n => new Intl.NumberFormat('th-TH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(Number(n || 0));
            const esc = s => (s ?? '').toString()
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

            async function fetchJSON(url) {
                const res = await fetch(url, {
                    credentials: 'same-origin'
                });
                const t = await res.text();
                try {
                    return {
                        ok: true,
                        data: JSON.parse(t)
                    };
                } catch {
                    return {
                        ok: false,
                        error: 'Server did not return JSON',
                        preview: t.slice(0, 800)
                    };
                }
            }

            // --------- DOM refs
            const sel = document.getElementById('machine_id');
            const typeSel = document.getElementById('type_value');

            const priceView = document.getElementById('base_price_view'); // ช่องให้ผู้ใช้กรอก
            const priceHidden = document.getElementById('base_price'); // ราคาก่อน VAT (ส่ง server)

            const vatRateEl = document.getElementById('vat_rate_pct'); // ปกติ 7
            const vatAmtView = document.getElementById('vat_amount_view');
            const vatAmtHidden = document.getElementById('vat_amount');

            const totalView = document.getElementById('total_amount_view');
            const totalHidden = document.getElementById('total_amount');

            const histCard = document.getElementById('historyCard');
            const histBody = document.getElementById('historyBody');
            const expCard = document.getElementById('expCard');
            const expBody = document.getElementById('expBody');
            const summaryCard = document.getElementById('summaryCard');
            const summaryBody = document.getElementById('summaryBody');

            const discView = document.getElementById('discount_amt_view');
            const discHidden = document.getElementById('discount_amt');
            const commView = document.getElementById('commission_amt_view');
            const commHidden = document.getElementById('commission_amt');

            const transView = document.getElementById('transport_cost_view'); // ใหม่
            const transHidden = document.getElementById('transport_cost');

            // ===== คำนวณตาม "ประเภทราคา"
            // ===== คำนวณตาม "ประเภทราคา" + ส่วนลด/ค่านายหน้า
            function recalc() {
                const t = String(typeSel?.value || ''); // 1=รวมภาษี, 2=ไม่รวม, 3=นอกระบบ
                const inp = toNum(priceView.value);
                const r = toNum(vatRateEl.value || '7');
                const disc = Math.max(0, toNum(discView?.value)); // ส่วนลด
                const comm = Math.max(0, toNum(commView?.value)); // ค่านายหน้า
                const trans = Math.max(0, toNum(transView?.value)); // ค่าขนส่ง (ใหม่)

                // sync hidden → ส่งไปเซิร์ฟเวอร์
                if (discHidden) discHidden.value = disc.toFixed(2);
                if (commHidden) commHidden.value = comm.toFixed(2);
                if (transHidden) transHidden.value = trans.toFixed(2); // ใหม่

                let base = 0,
                    vat = 0,
                    tot = 0;
                if (t === '1') {
                    const grossAfterDiscount = Math.max(inp - disc, 0);
                    vat = grossAfterDiscount * r / (100 + r);
                    base = grossAfterDiscount; // base=total สำหรับ type 1
                    tot = grossAfterDiscount;
                } else if (t === '2') {
                    const baseAfterDiscount = Math.max(inp - disc, 0);
                    vat = baseAfterDiscount * r / 100;
                    base = baseAfterDiscount;
                    tot = baseAfterDiscount + vat;
                } else { // t === '3' (นอกระบบ)
                    base = Math.max(inp - disc, 0);
                    vat = 0;
                    tot = base;
                }

                priceHidden.value = base.toFixed(2);
                vatAmtHidden.value = vat.toFixed(2);
                totalHidden.value = tot.toFixed(2);

                vatAmtView.value = fmt(vat);
                totalView.value = fmt(tot);

                updateSummary();
            }



            // ฟอร์แมตราคาเมื่อ blur
            priceView.addEventListener('input', recalc);

            priceView.addEventListener('blur', () => {
                priceView.value = fmt(toNum(priceView.value));
                recalc();
            });
            [discView, commView, transView].forEach(el => { // เพิ่ม transView
                if (!el) return;
                el.addEventListener('input', recalc);
                el.addEventListener('blur', () => {
                    el.value = fmt(toNum(el.value));
                    recalc();
                });
            });
            // เปลี่ยนประเภทราคา -> คำนวณใหม่
            if (typeSel) typeSel.addEventListener('change', recalc);

            // คำนวณครั้งแรก
            recalc();

            // ===== ช่วยแสดงผลตารางประวัติ/ค่าใช้จ่าย (ของเดิม)
            const typeBuyText = v => ({
                '1': 'รวมภาษี',
                '2': 'ไม่รวมภาษี',
                '3': 'นอกระบบ'
            } [String(v)] || '-');

            async function loadHistory(id) {
                if (!id) {
                    histCard.style.display = 'none';
                    histBody.innerHTML = '';
                    purchaseTotal = 0;
                    updateSummary();
                    return;
                }
                histBody.innerHTML = '<div class="muted">กำลังโหลด…</div>';

                const r = await fetchJSON(api('ajax_acq_history.php') + '?machine_id=' + encodeURIComponent(id));
                if (!r.ok || !r.data || r.data.ok === false) {
                    histBody.innerHTML =
                        `<div class="alert alert-danger">เกิดข้อผิดพลาด: ${esc((r.data&&r.data.error)||r.error||'โหลดข้อมูลล้มเหลว')}${
          r.preview?`<pre class="muted" style="white-space:pre-wrap;max-height:160px;overflow:auto;">${esc(r.preview)}</pre>`:''}</div>`;
                    histCard.style.display = 'block';
                    purchaseTotal = 0;
                    updateSummary();
                    return;
                }

                const rows = r.data.rows || [];
                if (rows.length === 0) {
                    histBody.innerHTML = '<div class="muted">ไม่มีประวัติการซื้อของเครื่องนี้</div>';
                    histCard.style.display = 'block';
                    purchaseTotal = 0;
                    updateSummary();
                    return;
                }

                purchaseTotal = Number(rows[0].total_amount || 0);

                const vatPct = toNum(vatRateEl.value || '7');
                let html = `<table class="table"><thead><tr>
      <th>#</th><th>เลขเอกสาร</th><th>วันที่ซื้อ</th><th>ผู้ขาย</th>
      <th>ยี่ห้อ/รุ่น</th><th>ประเภท</th>
      <th class="text-end">ราคา</th><th class="text-end">VAT</th><th class="text-end">รวม</th><th>หมายเหตุ</th>
    </tr></thead><tbody>`;

                rows.forEach((row, i) => {
                    let vatShow = (row.vat_amount != null) ? Number(row.vat_amount) : 0;
                    if (String(row.type_buy) === '1' && !row.vat_amount) {
                        const grand = Number(row.total_amount || 0);
                        const base = grand / (1 + vatPct / 100);
                        vatShow = grand - base;
                    }
                    if (String(row.type_buy) === '3') vatShow = 0;

                    html += `<tr>
        <td>${i+1}</td>
        <td>${esc(row.doc_no||'')}</td>
        <td>${esc(row.acquired_at||'')}</td>
        <td>${esc(row.supplier_name||'')}</td>
        <td>${esc([row.brand_name,row.model_name].filter(Boolean).join(' '))} <span class="muted">(${esc(row.code||'')})</span></td>
        <td>${typeBuyText(row.type_buy)}</td>
        <td class="text-end">${fmt(row.base_price)}</td>
        <td class="text-end">${fmt(vatShow)}</td>
        <td class="text-end"><strong>${fmt(row.total_amount)}</strong></td>
        <td>${esc(row.remark||'')}</td>
      </tr>`;
                });
                html += '</tbody></table>';

                histBody.innerHTML = html;
                histCard.style.display = 'block';
                updateSummary();
            }

            async function loadExpenses(id) {
                if (!id) {
                    expCard.style.display = 'none';
                    expBody.innerHTML = '';
                    expensesTotal = 0;
                    updateSummary();
                    return;
                }
                expBody.innerHTML = '<div class="muted">กำลังโหลด…</div>';

                const r = await fetchJSON(api('ajax_machine_expenses.php') + '?machine_id=' + encodeURIComponent(id));
                if (!r.ok || !r.data || r.data.ok === false) {
                    expBody.innerHTML =
                        `<div class="alert alert-danger">เกิดข้อผิดพลาด: ${esc((r.data&&r.data.error)||r.error||'โหลดข้อมูลล้มเหลว')}${
          r.preview?`<pre class="muted" style="white-space:pre-wrap;max-height:160px;overflow:auto;">${esc(r.preview)}</pre>`:''}</div>`;
                    expCard.style.display = 'block';
                    expensesTotal = 0;
                    updateSummary();
                    return;
                }

                const rows = r.data.rows || [];
                if (rows.length === 0) {
                    expBody.innerHTML = '<div class="muted">ยังไม่มีรายการค่าใช้จ่าย</div>';
                    expCard.style.display = 'block';
                    expensesTotal = 0;
                    updateSummary();
                    return;
                }

                let sum = 0;
                rows.forEach(x => sum += Number(x.line_total || 0));
                expensesTotal = sum;

                let html = `<table class="table"><thead><tr>
      <th>วันที่เกิด</th><th>ประเภท</th><th>คำอธิบาย</th>
      <th class="text-end">Qty</th><th class="text-end">ราคา/หน่วย</th><th class="text-end">รวม</th>
    </tr></thead><tbody>`;
                rows.forEach(rw => {
                    html += `<tr>
        <td>${esc(rw.expense_date||'')}</td>
        <td>${esc(rw.category_th||rw.category||'-')}</td>
        <td>${esc(rw.description||'')}</td>
        <td class="text-end">${fmt(rw.qty)}</td>
        <td class="text-end">฿${fmt(rw.unit_price)}</td>
        <td class="text-end"><strong>฿${fmt(rw.line_total)}</strong></td>
      </tr>`;
                });
                html += `</tbody><tfoot><tr><th colspan="5" class="text-end">รวมทั้งหมด</th><th class="text-end">฿${fmt(sum)}</th></tr></tfoot></table>`;

                expBody.innerHTML = html;
                expCard.style.display = 'block';
                updateSummary();
            }

            function updateSummary() {
                const sellTotal = Number(totalHidden.value || 0); // ยอดขายสุทธิ (รวม VAT ถ้า type 1/2)
                const vatNow = Number(vatAmtHidden.value || 0);
                const disc = Number(discHidden?.value || 0);
                const comm = Number(commHidden?.value || 0);
                const trans = Number(transHidden?.value || 0); // ใหม่: ค่าขนส่ง

                // ต้นทุนจากฝั่งซื้อ + ค่าใช้จ่ายรถ
                const costCore = Number(purchaseTotal || 0) + Number(expensesTotal || 0);

                // กำไรหลังหัก ค่านายหน้า + ค่าขนส่ง
                const profit = sellTotal - (costCore + comm + trans);

                if (!sel.value) {
                    summaryCard.style.display = 'none';
                    summaryBody.innerHTML = '';
                    return;
                }

                const color = profit > 0 ? '#177500' : (profit < 0 ? '#A30000' : '#444');

                const t = String(typeSel?.value || '');
                const priceTitle = (t === '1') ? 'ราคาตั้งขาย (รวม VAT) ที่กรอก' :
                    (t === '2') ? 'ราคาตั้งขาย (ไม่รวม VAT) ที่กรอก' :
                    'ราคาตั้งขายที่กรอก';
                const inputPrice = toNum(priceView.value);

                summaryBody.innerHTML = `
    <table class="sumtable"><tbody>
      <tr><td>ราคารถจากเอกสารซื้อ</td><td>฿${fmt(purchaseTotal)}</td></tr>
      <tr><td>รวมค่าใช้จ่ายของรถ</td><td>฿${fmt(expensesTotal)}</td></tr>
      <tr><th>ราคาต้นทุนรวม (ซื้อ+ค่าใช้จ่าย)</th><th>฿${fmt(costCore)}</th></tr>

      <tr><td>${priceTitle}</td><td>฿${fmt(inputPrice)}</td></tr>
      <tr><td>ส่วนลด</td><td>- ฿${fmt(disc)}</td></tr>
      <tr><td>VAT ปัจจุบัน</td><td>฿${fmt(vatNow)}</td></tr>
      <tr><th>ราคาขายสุทธิ</th><th>฿${fmt(sellTotal)}</th></tr>

      <tr><td>ค่านายหน้า</td><td>- ฿${fmt(comm)}</td></tr>
      <tr><td>ค่าขนส่ง</td><td>- ฿${fmt(trans)}</td></tr>  <!-- แสดงค่าขนส่ง -->
      <tr><th>กำไร/ขาดทุน (หลังค่านายหน้า+ขนส่ง)</th>
          <th style="color:${color};">฿${fmt(profit)}</th></tr>
    </tbody></table>
  `;
                summaryCard.style.display = 'block';
            }


            // เมื่อเลือกเครื่อง → โหลดประวัติ + ค่าใช้จ่าย
            sel.addEventListener('change', e => {
                const id = e.target.value;
                loadHistory(id);
                loadExpenses(id);
            });
            if (sel.value) {
                loadHistory(sel.value);
                loadExpenses(sel.value);
            }
        })();
    </script>

    <script src="assets/script.js?v=3"></script>
</body>

</html>