/**
 * Shared Reports module navbar — synced with RDOMMS.html sidebar Reports menu.
 * All report pages use PHP as the canonical route; legacy .html URLs redirect to .php.
 */
(function () {
  const REPORT_PAGE_KEYS = ['generate-reports', 'variance-analysis'];

  function redirectLegacyReportsHtml() {
    const path = window.location.pathname.replace(/\\/g, '/');
    const match = path.match(/\/reports\/([a-z-]+)\.html$/i);
    if (!match) return false;
    const slug = match[1].toLowerCase();
    if (!REPORT_PAGE_KEYS.includes(slug)) return false;
    const target = path.replace(/\.html$/i, '.php') + window.location.search + window.location.hash;
    window.location.replace(target);
    return true;
  }

  if (redirectLegacyReportsHtml()) {
    return;
  }

  const PAGES = {
    'generate-reports': {
      label: 'Generate Reports',
      meta: 'Build and export system reports',
      href: 'generate-reports.php',
      icon: 'generatereport.png'
    },
    'variance-analysis': {
      label: 'Variance Analysis',
      meta: 'Budget vs actual performance',
      href: 'variance-analysis.php',
      icon: 'varianceanalysis.png'
    }
  };

  const SECTIONS = [
    { label: 'Reports Module', keys: ['generate-reports', 'variance-analysis'] }
  ];

  function getReportAccess() {
    const pageAccess = window.RDOMMS_REPORTS_PAGE?.reportAccess
      || window.RDOMMS_VARIANCE_PAGE?.reportAccess
      || window.RDOMMS_REPORT_ACCESS
      || {};

    return {
      canAccessGenerateReports: !!pageAccess.canAccessGenerateReports,
      canAccessVarianceAnalysis: !!pageAccess.canAccessVarianceAnalysis,
      role: String(pageAccess.role || '').toLowerCase()
    };
  }

  function getReportsRole() {
    const access = getReportAccess();
    if (access.role) return access.role;

    if (window.RDOMMSRBAC && typeof window.RDOMMSRBAC.getCurrentRole === 'function') {
      return window.RDOMMSRBAC.getCurrentRole();
    }

    try {
      const user = JSON.parse(localStorage.getItem('currentUser') || '{}');
      return String(user.role || '').toLowerCase();
    } catch (_) {
      return '';
    }
  }

  function getVisibleReportKeys() {
    const access = getReportAccess();
    const role = getReportsRole();
    const keys = [];

    if (access.canAccessGenerateReports || role === 'admin') {
      keys.push('generate-reports');
    } else if (!access.role && ['head_of_organization_center', 'head_of_department', 'human_resource_manager', 'accountant'].includes(role)) {
      keys.push('generate-reports');
    }

    if (access.canAccessVarianceAnalysis || role === 'admin' || role === 'accountant') {
      keys.push('variance-analysis');
    }

    return keys;
  }

  function getVisibleSections() {
    const visible = new Set(getVisibleReportKeys());
    return SECTIONS
      .map((section) => ({
        ...section,
        keys: section.keys.filter((key) => visible.has(key))
      }))
      .filter((section) => section.keys.length > 0);
  }

  function getReportsHomeKey() {
    const keys = getVisibleReportKeys();
    if (keys.includes('generate-reports')) return 'generate-reports';
    return keys[0] || 'generate-reports';
  }

  function getContext() {
    const path = window.location.pathname.replace(/\\/g, '/').toLowerCase();
    const inReports = path.includes('/reports/');

    return {
      assetRoot: inReports ? '../assets' : 'assets',
      dashboardHref: inReports ? '../RDOMMS.html' : 'RDOMMS.html',
      reportsPrefix: inReports ? '.' : 'reports'
    };
  }

  function resolvePageHref(ctx, pageKey) {
    const page = PAGES[pageKey];
    if (!page) return '#';
    if (ctx.reportsPrefix === '.') {
      return page.href;
    }
    return `${ctx.reportsPrefix}/${page.href}`;
  }

  function iconUrl(ctx, fileName) {
    return `${ctx.assetRoot}/photos/systemicon/${encodeURIComponent(fileName)}`;
  }

  function detectActiveKey(root) {
    const explicit = root.getAttribute('data-active');
    if (explicit && PAGES[explicit]) {
      return explicit;
    }

    const path = window.location.pathname.replace(/\\/g, '/').toLowerCase();
    return Object.keys(PAGES).find((key) => {
      const page = PAGES[key];
      const slug = page.href.replace(/\.php$/i, '');
      return path.endsWith(`/reports/${page.href}`)
        || path.endsWith(`/${page.href}`)
        || path.endsWith(`/reports/${slug}`)
        || path.endsWith(`/${slug}`);
    }) || getReportsHomeKey();
  }

  function renderDropdownItems(ctx, activeKey) {
    const sections = getVisibleSections();
    if (!sections.length) {
      return '<div class="rpn-dropdown-body"><p class="rpn-dropdown-meta" style="padding:.5rem 0">No reports available for your role.</p></div>';
    }

    const sectionsHtml = sections.map((section) => {
      const items = section.keys.map((key) => {
        const page = PAGES[key];
        const href = resolvePageHref(ctx, key);
        const active = key === activeKey;
        const activeClass = active ? ' active' : '';
        const ariaCurrent = active ? ' aria-current="page"' : '';

        return `
          <a href="${href}" class="rpn-dropdown-item${activeClass}" role="menuitem"${ariaCurrent}>
            <span class="rpn-dropdown-icon"><img src="${iconUrl(ctx, page.icon)}" alt=""></span>
            <span class="rpn-dropdown-text">
              <span class="rpn-dropdown-title">${page.label}</span>
              <span class="rpn-dropdown-meta">${page.meta}</span>
            </span>
            ${active ? '<i class="fas fa-check rpn-dropdown-check" aria-hidden="true"></i>' : '<i class="fas fa-chevron-right rpn-dropdown-arrow" aria-hidden="true"></i>'}
          </a>
        `;
      }).join('');

      return `
        <div class="rpn-dropdown-section">
          <span class="rpn-dropdown-label">${section.label}</span>
          <div class="rpn-dropdown-grid">${items}</div>
        </div>
      `;
    }).join('');

    const visibleKeys = getVisibleReportKeys();
    return `
      <div class="rpn-dropdown-header">
        <div>
          <span class="rpn-dropdown-heading">Reports Module</span>
          <span class="rpn-dropdown-sub">Generate reports and analyse budget variance</span>
        </div>
        <span class="rpn-dropdown-badge">${visibleKeys.length} pages</span>
      </div>
      <div class="rpn-dropdown-body">${sectionsHtml}</div>
      <div class="rpn-dropdown-footer">
        <a href="${ctx.dashboardHref}" class="rpn-dropdown-footer-link">
          <i class="fas fa-house" aria-hidden="true"></i>
          <span>Back to dashboard</span>
        </a>
      </div>
    `;
  }

  function bindMenu(root) {
    const ctx = getContext();
    const options = { title: 'Reports Menu' };

    const boot = () => {
      if (window.RPNNav && typeof window.RPNNav.bindMenu === 'function') {
        window.RPNNav.bindMenu(root, options);
      }
    };

    if (window.RPNNav && window.RPNNav.bindMenu) {
      boot();
      return;
    }

    const script = document.createElement('script');
    script.src = `${ctx.assetRoot}/js/rpn-nav-shared.js?v=2`;
    script.onload = boot;
    document.head.appendChild(script);
  }

  function render() {
    const root = document.getElementById('rdomms-reports-nav');
    if (!root) return;

    const ctx = getContext();
    const activeKey = detectActiveKey(root);
    const visibleKeys = getVisibleReportKeys();
    const current = PAGES[activeKey] || PAGES[visibleKeys[0]] || PAGES['generate-reports'];
    const reportsHomeHref = resolvePageHref(ctx, getReportsHomeKey());
    const logoSrc = `${ctx.assetRoot}/photos/RDO.png`;

    document.documentElement.classList.add('has-reports-nav');

    root.className = 'rdomms-project-nav rdomms-reports-nav';
    root.innerHTML = `
      <div class="rpn-topbar">
        <a href="${ctx.dashboardHref}" class="rpn-brand" title="Back to RDO|MMS Dashboard">
          <img src="${logoSrc}" alt="RDO|MMS">
          RDO|MMS
        </a>
        <i class="fas fa-chevron-right rpn-sep" aria-hidden="true"></i>
        <a href="${reportsHomeHref}" class="rpn-crumb rpn-crumb-link" title="Reports module">Reports</a>
        <i class="fas fa-chevron-right rpn-sep" aria-hidden="true"></i>
        <span class="rpn-crumb-active">${current.label}</span>
        <div class="rpn-actions">
          <div class="rpn-menu-wrap">
            <button type="button" class="rpn-menu-btn" aria-haspopup="true" aria-expanded="false" aria-controls="rpn-reports-menu">
              <i class="fas fa-chart-pie" aria-hidden="true"></i>
              <span>Reports Menu</span>
              <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="rpn-dropdown rpn-dropdown-mega" id="rpn-reports-menu" role="menu" aria-label="Reports navigation">
              ${renderDropdownItems(ctx, activeKey)}
            </div>
          </div>
          <a href="${ctx.dashboardHref}" class="rpn-dashboard-link">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            <span>Dashboard</span>
          </a>
        </div>
      </div>
    `;

    bindMenu(root);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
