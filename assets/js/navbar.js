// assets/js/navbar.js
// Handles sidebar toggling (desktop collapse + mobile overlay), overlay, active nav highlighting,
// sidebar search, theme toggle, tooltips, and avatar refresh.

document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.getElementById('sidebar');
  const main = document.getElementById('main') || document.querySelector('.main');
  const menuBtn = document.getElementById('menuBtn');
  const overlayId = 'appOverlay';
  let overlay = document.getElementById(overlayId);

  // Create overlay if missing (safe-guard)
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = overlayId;
    overlay.className = 'app-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    document.body.appendChild(overlay);
  }

  // Theme toggle (persist)
  const themeToggle = document.getElementById('themeToggle');
  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
    if (themeToggle) themeToggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
  }
  try {
    const saved = localStorage.getItem('appTheme');
    applyTheme(saved === 'dark' ? 'dark' : 'light');
  } catch (e) {}
  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
      const next = cur === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      try { localStorage.setItem('appTheme', next); } catch (e) {}
    });
    themeToggle.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); themeToggle.click(); }});
  }

  // Restore desktop collapsed state
  try {
    const collapsed = localStorage.getItem('sidebarState') === 'collapsed';
    if (collapsed) {
      document.documentElement.classList.add('sidebar-collapsed');
      sidebar?.classList.add('collapsed');
      if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
    }
  } catch (e) {}

  // Toggle helpers
  function openSidebarMobile() {
    sidebar?.classList.add('active');
    overlay.classList.add('active');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    if (menuBtn) menuBtn.setAttribute('aria-expanded', 'true');
  }
  function closeSidebarMobile() {
    sidebar?.classList.remove('active');
    overlay.classList.remove('active');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
  }
  function toggleSidebarDesktop() {
    const isCollapsed = sidebar?.classList.toggle('collapsed');
    document.documentElement.classList.toggle('sidebar-collapsed');
    if (menuBtn) menuBtn.setAttribute('aria-expanded', String(!isCollapsed));
    try { localStorage.setItem('sidebarState', isCollapsed ? 'collapsed' : 'expanded'); } catch (e) {}
  }

  // Menu button behavior: mobile overlay vs desktop collapse
  if (menuBtn) {
    menuBtn.addEventListener('click', (ev) => {
      if (window.innerWidth <= 992) {
        // mobile: open/close overlay sidebar
        if (sidebar?.classList.contains('active')) closeSidebarMobile(); else openSidebarMobile();
      } else {
        // desktop: collapse/uncollapse
        toggleSidebarDesktop();
      }
    });
  }

  // overlay click closes mobile sidebar
  overlay.addEventListener('click', closeSidebarMobile);

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeSidebarMobile();
  });

  // clicking nav links: set active and close mobile sidebar
  document.querySelectorAll('.sidebar .nav-link').forEach((link) => {
    link.addEventListener('click', (ev) => {
      document.querySelectorAll('.sidebar .nav-link').forEach((l) => l.classList.remove('active'));
      link.classList.add('active');
      if (window.innerWidth <= 992) closeSidebarMobile();
    });
  });

  // Click outside (on body) closes mobile sidebar (but not clicks on sidebar or toggle)
  document.addEventListener('click', (e) => {
    const target = e.target;
    if (window.innerWidth <= 992 && sidebar?.classList.contains('active')) {
      if (!sidebar.contains(target) && menuBtn && !menuBtn.contains(target) && !overlay.contains(target)) {
        closeSidebarMobile();
      }
    }
  });

  // Resize handling: avoid overlay/stuck scroll
  window.addEventListener('resize', () => {
    if (window.innerWidth > 992) {
      overlay.classList.remove('active');
      overlay.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    } else {
      // reset desktop-only collapsed state so mobile shows full nav
      sidebar?.classList.remove('collapsed');
      document.documentElement.classList.remove('sidebar-collapsed');
    }
  });

  // Avatar refresh (topbar only) - optional: keep if you have profile/get_photo.php endpoint
  const navbarImg = document.getElementById('navbarProfileImg');
  async function fetchPhoto() {
    if (!navbarImg) return;
    try {
      const res = await fetch((window.APP_BASE_URL ?? '') + '/profile/get_photo.php', { cache: 'no-store' });
      const data = await res.json();
      if (data && data.photo) navbarImg.src = data.photo + '?t=' + Date.now();
      else navbarImg.src = (window.APP_BASE_URL ?? '') + '/assets/images/default_user.png';
    } catch (err) {
      navbarImg.src = (window.APP_BASE_URL ?? '') + '/assets/images/default_user.png';
    }
  }
  fetchPhoto();
  setInterval(fetchPhoto, 5 * 60 * 1000);

  // Initialize bootstrap tooltips when collapsed OR on small screens
  function initTooltips(enable = true) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
      try { const inst = bootstrap.Tooltip.getInstance(el); if (inst) inst.dispose(); } catch (e) {}
      if (enable) new bootstrap.Tooltip(el, { trigger: 'hover focus', boundary: 'window' });
    });
  }
  function shouldEnableTooltips() {
    return sidebar?.classList.contains('collapsed') || window.innerWidth <= 992;
  }
  initTooltips(shouldEnableTooltips());

  const observer = new MutationObserver(() => {
    const collapsed = sidebar?.classList.contains('collapsed');
    initTooltips(collapsed || window.innerWidth <= 992);
    document.querySelectorAll('.sidebar .nav-link').forEach((a) => a.setAttribute('aria-expanded', collapsed ? 'false' : 'true'));
  });
  if (sidebar) observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });

  // Search filtering + clear
  const searchInput = document.getElementById('sidebarSearch');
  const searchClear = document.getElementById('sidebarSearchClear');
  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      const q = (e.target.value || '').trim().toLowerCase();
      document.querySelectorAll('.sidebar .nav-link').forEach((link) => {
        const label = (link.querySelector('.nav-label')?.textContent || '').trim().toLowerCase();
        link.style.display = q === '' || label.indexOf(q) !== -1 ? '' : 'none';
      });
    });
  }
  if (searchClear) {
    searchClear.addEventListener('click', () => {
      if (!searchInput) return;
      searchInput.value = '';
      searchInput.dispatchEvent(new Event('input'));
      searchInput.focus();
    });
  }

  // Keyboard shortcut 'm' to toggle (when not typing)
  document.addEventListener('keydown', (e) => {
    const active = document.activeElement;
    const tag = active?.tagName?.toLowerCase?.();
    if (tag === 'input' || tag === 'textarea' || active?.isContentEditable) return;
    if (e.key.toLowerCase() === 'm') {
      if (window.innerWidth <= 992) {
        if (sidebar?.classList.contains('active')) closeSidebarMobile(); else openSidebarMobile();
      } else {
        toggleSidebarDesktop();
      }
    }
  });

  // Active link detection from URL
  (function setActiveFromUrl() {
    try {
      const path = (location.pathname || '').toLowerCase();
      const links = Array.from(document.querySelectorAll('.sidebar .nav-link'));
      if (!links.length) return;
      let best = null, bestLen = 0;
      links.forEach((a) => {
        try {
          const href = (a.getAttribute('href') || '').toLowerCase();
          if (!href) return;
          const url = new URL(href, window.location.origin);
          const p = url.pathname;
          if (p === path) { best = a; bestLen = Infinity; }
          else {
            if (path.indexOf(p) !== -1 && p.length > bestLen) { best = a; bestLen = p.length; }
          }
        } catch (e) {}
      });
      if (best) { document.querySelectorAll('.sidebar .nav-link').forEach((l) => l.classList.remove('active')); best.classList.add('active'); }
    } catch (e) {}
  })();

});
