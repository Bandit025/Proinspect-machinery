// assets/script.js

// helper: toggle password visibility by button with data-target
function wireToggle(btnId, inputId) {
  const btn = document.getElementById(btnId);
  const input = document.getElementById(inputId);
  if (!btn || !input) return;
  btn.addEventListener('click', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'ซ่อน' : 'แสดง';
  });
}

// Register page: confirm password check (client-side)
function wireConfirmPassword(p1Id, p2Id, formId) {
  const p1 = document.getElementById(p1Id);
  const p2 = document.getElementById(p2Id);
  const form = document.getElementById(formId);
  if (!p1 || !p2 || !form) return;

  const validate = () => {
    if (p1.value !== p2.value) {
      p2.setCustomValidity('รหัสผ่านไม่ตรงกัน');
    } else {
      p2.setCustomValidity('');
    }
  };
  p1.addEventListener('input', validate);
  p2.addEventListener('input', validate);

  form.addEventListener('submit', (e) => {
    validate();
    if (!form.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
    }
  });
}

// Auto-wire elements that exist on the current page
document.addEventListener('DOMContentLoaded', () => {
  wireToggle('togglePwd', 'password');      // login
  wireToggle('togglePwdReg', 'pwd');        // register (main)
  wireToggle('togglePwdReg2', 'pwd2');      // register (confirm)
  wireConfirmPassword('pwd', 'pwd2', 'regForm');
});

// === Sidebar Toggle (Desktop = collapse, Mobile = overlay) ===
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('btnSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');

  if (btn) {
    btn.addEventListener('click', () => {
      if (window.innerWidth < 992) {
        document.body.classList.toggle('sidebar-open');
      } else {
        document.body.classList.toggle('sidebar-collapsed');
      }
    });
  }

  if (backdrop) {
    backdrop.addEventListener('click', () => {
      document.body.classList.remove('sidebar-open');
    });
  }

  // ปิด overlay เมื่อ resize จากมือถือกลับไปเดสก์ทอป
  window.addEventListener('resize', () => {
    if (window.innerWidth >= 992) {
      document.body.classList.remove('sidebar-open');
    }
  });
});


// assets/script.js
document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('.layout');
  const btn = document.getElementById('btnSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');

  function openSidebar() {
    if (!root) return;
    root.classList.add('sidebar-open');
    if (btn) btn.setAttribute('aria-expanded', 'true');
  }
  function closeSidebar() {
    if (!root) return;
    root.classList.remove('sidebar-open');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }
  function isMobile() { return window.innerWidth <= 1024; }

  // เริ่มต้น: ปิดไว้ถ้าเป็นมือถือ
  if (isMobile()) closeSidebar();

  // ปุ่มแฮมเบอร์เกอร์
  if (btn) btn.addEventListener('click', () => {
    if (!root) return;
    root.classList.toggle('sidebar-open');
    btn.setAttribute('aria-expanded', root.classList.contains('sidebar-open') ? 'true' : 'false');
  });

  // คลิกพื้นหลังเพื่อปิด
  if (backdrop) backdrop.addEventListener('click', closeSidebar);

  // ปิดด้วย ESC
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeSidebar();
  });

  // คลิกลิงก์เมนูแล้วปิด (บนจอเล็ก)
  document.querySelectorAll('.sidebar a').forEach(a => {
    a.addEventListener('click', () => { if (isMobile()) closeSidebar(); });
  });

  // เมื่อปรับขนาดหน้าจอ: เดสก์ท็อป = แสดง sidebar เสมอ, มือถือ = ซ่อน
  let resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (isMobile()) closeSidebar();
      else {
        // ให้ sidebar โผล่และไม่มีแบ็คดรอปบนเดสก์ท็อป
        if (root) root.classList.remove('sidebar-open');
      }
    }, 120);
  });
});

// Sidebar toggle + backdrop + Escape
(function () {
  const btn = document.getElementById('btnSidebar') || document.getElementById('sidebarToggle');
  const layout = document.querySelector('.layout');
  const backdrop = document.getElementById('sidebarBackdrop');

  if (!btn || !layout) return;

  function toggle(open) {
    const willOpen = (typeof open === 'boolean') ? open : !layout.classList.contains('sidebar-open');
    layout.classList.toggle('sidebar-open', willOpen);
    btn.setAttribute('aria-expanded', String(willOpen));
  }

  btn.addEventListener('click', () => toggle());
  if (backdrop) backdrop.addEventListener('click', () => toggle(false));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') toggle(false);
  });
})();
// assets/script.js
(function () {
  const btn = document.getElementById('btnSidebar') || document.getElementById('sidebarToggle');
  const layout = document.querySelector('.layout');
  const backdrop = document.getElementById('sidebarBackdrop');
  if (!btn || !layout) return;

  function toggle(open){
    const willOpen = (typeof open === 'boolean') ? open : !layout.classList.contains('sidebar-open');
    layout.classList.toggle('sidebar-open', willOpen);
    btn.setAttribute('aria-expanded', String(willOpen));
  }
  btn.addEventListener('click', () => toggle());
  if (backdrop) backdrop.addEventListener('click', () => toggle(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') toggle(false); });
})();


