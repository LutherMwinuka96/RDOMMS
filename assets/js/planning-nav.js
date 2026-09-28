/**
 * Shared Planning module navbar — integrated with RDOMMS.html sidebar Planning menu.
 * All planning pages use PHP as the canonical route; legacy .html URLs redirect to .php.
 */
(function () {
  const PLANNING_PAGE_KEYS = [
    'monthly-plans',
    'quarterly-plans',
    'annual-plans',
    'targets-indicators',
    'progress-monitoring',
    'all-plans',
    'upload-evidence'
  ];

  function redirectLegacyPlanningHtml() {
    const path = window.location.pathname.replace(/\\/g, '/');
    const match = path.match(/\/planning\/([a-z-]+)\.html$/i);
    if (!match) return false;
    const slug = match[1].toLowerCase();
    if (!PLANNING_PAGE_KEYS.includes(slug)) return false;
    const target = path.replace(/\.html$/i, '.php') + window.location.search + window.location.hash;
    window.location.replace(target);
    return true;
  }

  if (redirectLegacyPlanningHtml()) {
    return;
  }

  const PAGES = {
    'monthly-plans': {
      label: 'Monthly Plans',
      meta: 'Plan activities month by month',
      href: 'monthly-plans.php',
      icon: 'monthlyplan.png'
    },
    'quarterly-plans': {
      label: 'Quarterly Plans',
      meta: 'Quarterly cycles and milestones',
      href: 'quarterly-plans.php',
      icon: 'quartely planning.png'
    },
    'annual-plans': {
      label: 'Annual Plans',
      meta: 'Full-year strategic planning',
      href: 'annual-plans.php',
      icon: 'calendar.png'
    },
    'targets-indicators': {
      label: 'Targets & Indicators',
      meta: 'KPIs and performance targets',
      href: 'targets-indicators.php',
      icon: 'targetindicator.png'
    },
    'progress-monitoring': {
      label: 'Progress Monitoring',
      meta: 'Track plan execution status',
      href: 'progress-monitoring.php',
      icon: 'progress monitor.png'
    },
    'all-plans': {
      label: 'All Plans',
      meta: 'Review and approve submissions',
      href: 'all-plans.php',
      icon: 'allplans.png'
    },
    'upload-evidence': {
      label: 'Upload Evidence',
      meta: 'Attach proof and documents',
      href: 'upload-evidence.php',
      icon: 'projectdocuments.png'
    }
  };

  const SECTIONS = [
    { label: 'Periodic Plans', keys: ['monthly-plans', 'quarterly-plans', 'annual-plans'] },
    { label: 'Monitor', keys: ['targets-indicators', 'progress-monitoring'] },
    { label: 'Review', keys: ['all-plans'] },
    { label: 'Evidence', keys: ['upload-evidence'] }
  ];

  /** Show quick navigation chips for every planning navbar page. */
  const SHOW_QUICKLINKS = true;

  const ADMIN_PLANNING_KEYS = ['all-plans'];

  function getPlanningRole() {
    if (window.RDOMMSRBAC && typeof window.RDOMMSRBAC.getCurrentRole === 'function') {
      return window.RDOMMSRBAC.getCurrentRole();
    }

    try {
      return String(JSON.parse(localStorage.getItem('currentUser') || '{}').role || '').toLowerCase();
    } catch (_) {
      return '';
    }
  }

  function isAdminPlanningViewer() {
    if (window.RDOMMSRBAC && typeof window.RDOMMSRBAC.isAdminRole === 'function') {
      return window.RDOMMSRBAC.isAdminRole(getPlanningRole());
    }

    const role = getPlanningRole();
    return role === 'admin' || role === 'administrator' || role === 'mkurugenzi_mtendaji';
  }

  function shouldShowPlanningMenu() {
    return !isAdminPlanningViewer();
  }

  function shouldShowPlanningQuicklinks() {
    return SHOW_QUICKLINKS && !isAdminPlanningViewer();
  }

  function getVisibleKeys() {
    if (isAdminPlanningViewer()) {
      return ADMIN_PLANNING_KEYS;
    }
    return Object.keys(PAGES);
  }

  function getVisibleSections() {
    const visible = new Set(getVisibleKeys());
    return SECTIONS
      .map((section) => ({
        ...section,
        keys: section.keys.filter((key) => visible.has(key))
      }))
      .filter((section) => section.keys.length > 0);
  }

  function getContext() {
    const path = window.location.pathname.replace(/\\/g, '/').toLowerCase();
    const inPlanning = path.includes('/planning/');

    return {
      assetRoot: inPlanning ? '../assets' : 'assets',
      dashboardHref: inPlanning ? '../RDOMMS.html' : 'RDOMMS.html',
      planningPrefix: inPlanning ? '.' : 'planning'
    };
  }

  function resolvePageHref(ctx, pageKey) {
    const page = PAGES[pageKey];
    if (!page) return '#';
    if (ctx.planningPrefix === '.') {
      return page.href;
    }
    return `${ctx.planningPrefix}/${page.href}`;
  }

  function iconUrl(ctx, fileName) {
    return `${ctx.assetRoot}/photos/systemicon/${encodeURIComponent(fileName)}`;
  }

  function pageMatchesPath(path, page) {
    const slug = page.href.replace(/\.php$/i, '');
    return path.endsWith(`/planning/${page.href}`)
      || path.endsWith(`/${page.href}`)
      || path.endsWith(`/planning/${slug}`)
      || path.endsWith(`/${slug}`);
  }

  function detectActiveKey(root) {
    const explicit = root.getAttribute('data-active');
    if (explicit && PAGES[explicit]) {
      return explicit;
    }

    const path = window.location.pathname.replace(/\\/g, '/').toLowerCase();
    return Object.keys(PAGES).find((key) => pageMatchesPath(path, PAGES[key]))
      || (isAdminPlanningViewer() ? 'all-plans' : 'monthly-plans');
  }

  function renderDropdownItems(ctx, activeKey) {
    const visibleKeys = getVisibleKeys();
    const sectionsHtml = getVisibleSections().map((section) => {
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

    return `
      <div class="rpn-dropdown-header">
        <div>
          <span class="rpn-dropdown-heading">Planning Module</span>
          <span class="rpn-dropdown-sub">Synced with RDO|MMS planning register</span>
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

  function renderQuickLinks(ctx, activeKey) {
    if (!shouldShowPlanningQuicklinks()) {
      return '';
    }

    return getVisibleSections().map((section) => {
      const chips = section.keys.map((key) => {
        const page = PAGES[key];
        const href = resolvePageHref(ctx, key);
        const active = key === activeKey ? ' active' : '';
        return `
          <a href="${href}" class="rpn-quicklink${active}" title="${page.meta}">
            <img src="${iconUrl(ctx, page.icon)}" alt="">
            <span>${page.label}</span>
          </a>
        `;
      }).join('');

      return `<div class="rpn-quicklink-group" aria-label="${section.label}">${chips}</div>`;
    }).join('');
  }

  function bindMenu(root) {
    const ctx = getContext();
    const options = { title: 'Planning Menu' };

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
    const root = document.getElementById('rdomms-planning-nav');
    if (!root) return;

    const ctx = getContext();
    const activeKey = detectActiveKey(root);
    const current = PAGES[activeKey] || (isAdminPlanningViewer() ? PAGES['all-plans'] : PAGES['monthly-plans']);
    const logoSrc = `${ctx.assetRoot}/photos/RDO.png`;

    document.documentElement.classList.add('has-planning-nav');

    root.className = 'rdomms-project-nav rdomms-planning-nav'
      + (isAdminPlanningViewer() ? ' rpn-nav--admin-minimal' : '');
    const quicklinksHtml = renderQuickLinks(ctx, activeKey);
    const quicklinksBlock = quicklinksHtml
      ? `<div class="rpn-quicklinks rpn-quicklinks-grouped" aria-label="Planning quick navigation">${quicklinksHtml}</div>`
      : '';

    const planningHomeHref = resolvePageHref(ctx, isAdminPlanningViewer() ? 'all-plans' : 'monthly-plans');
    const actionsHtml = shouldShowPlanningMenu()
      ? `
          <div class="rpn-menu-wrap">
            <button type="button" class="rpn-menu-btn" aria-haspopup="true" aria-expanded="false" aria-controls="rpn-planning-menu">
              <i class="fas fa-calendar-check" aria-hidden="true"></i>
              <span>Planning Menu</span>
              <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="rpn-dropdown rpn-dropdown-mega" id="rpn-planning-menu" role="menu" aria-label="Planning navigation">
              ${renderDropdownItems(ctx, activeKey)}
            </div>
          </div>
          <a href="${ctx.dashboardHref}" class="rpn-dashboard-link">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            <span>Dashboard</span>
          </a>
        `
      : `
          <a href="${ctx.dashboardHref}" class="rpn-dashboard-link">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            <span>Dashboard</span>
          </a>
        `;

    root.innerHTML = `
      <div class="rpn-topbar">
        <a href="${ctx.dashboardHref}" class="rpn-brand" title="Back to RDO|MMS Dashboard">
          <img src="${logoSrc}" alt="RDO|MMS">
          RDO|MMS
        </a>
        <i class="fas fa-chevron-right rpn-sep" aria-hidden="true"></i>
        <a href="${planningHomeHref}" class="rpn-crumb rpn-crumb-link" title="Planning module">Planning</a>
        <i class="fas fa-chevron-right rpn-sep" aria-hidden="true"></i>
        <span class="rpn-crumb-active">${current.label}</span>
        <div class="rpn-actions">
          ${actionsHtml}
        </div>
      </div>
      ${quicklinksBlock}
    `;

    if (shouldShowPlanningMenu()) {
      bindMenu(root);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
