/**
 * Shared Projects module navbar — portfolio, monitoring, and records only.
 * Finance lives in the Finance module (single footer link when role allows).
 */
(function () {
  const PROJECT_PAGE_KEYS = [
    'create-project',
    'all-projects',
    'status-tracking',
    'project-health',
    'archived-projects'
  ];

  function redirectLegacyProjectHtml() {
    const path = window.location.pathname.replace(/\\/g, '/');
    const projectsMatch = path.match(/\/projects\/([a-z-]+)\.html$/i);
    if (projectsMatch) {
      const slug = projectsMatch[1].toLowerCase();
      if (PROJECT_PAGE_KEYS.includes(slug)) {
        window.location.replace(
          path.replace(/\.html$/i, '.php') + window.location.search + window.location.hash
        );
        return true;
      }
    }

    if (/\/documents\/project-documents\.html$/i.test(path)) {
      window.location.replace(
        path.replace(/\.html$/i, '.php') + window.location.search + window.location.hash
      );
      return true;
    }

    return false;
  }

  if (redirectLegacyProjectHtml()) {
    return;
  }

  const PAGES = {
    'create-project': {
      label: 'Create Project',
      meta: 'Register a new project',
      href: 'create-project.php',
      icon: 'projects.png'
    },
    'all-projects': {
      label: 'All Projects',
      meta: 'Browse your project portfolio',
      href: 'all-projects.php',
      icon: 'allproject.png'
    },
    'status-tracking': {
      label: 'Project Progress',
      meta: 'Update status and completion %',
      href: 'status-tracking.php',
      icon: 'statustrack.png'
    },
    'project-health': {
      label: 'Budget & Compliance',
      meta: 'Spending, flags and validation',
      href: 'project-health.php',
      icon: 'financialreport.png'
    },
    'archived-projects': {
      label: 'Archived Projects',
      meta: 'Completed and closed records',
      href: 'archived-projects.php',
      icon: 'allproject.png'
    },
    'project-documents': {
      label: 'Project Documents',
      meta: 'Files, contracts and uploads',
      href: 'project-documents.php',
      icon: 'projectdocuments.png',
      externalDir: 'documents'
    }
  };

  const SECTIONS = [
    { label: 'Portfolio', keys: ['create-project', 'all-projects'] },
    { label: 'Monitor', keys: ['status-tracking', 'project-health'] },
    { label: 'Archive', keys: ['archived-projects'] },
    { label: 'Documents', keys: ['project-documents'] }
  ];

  const ACCOUNTANT_PROJECT_KEYS = new Set(['all-projects', 'status-tracking', 'project-health']);
  const ADMIN_PROJECT_KEYS = new Set(['all-projects', 'archived-projects']);

  function getProjectRole() {
    try {
      return String(JSON.parse(localStorage.getItem('currentUser') || '{}').role || '').toLowerCase();
    } catch (_) {
      return '';
    }
  }

  function isAdminProjectViewer() {
    if (window.RDOMMSRBAC && typeof window.RDOMMSRBAC.isAdminRole === 'function') {
      return window.RDOMMSRBAC.isAdminRole(getProjectRole());
    }

    const role = getProjectRole();
    return role === 'admin' || role === 'administrator' || role === 'mkurugenzi_mtendaji';
  }

  function shouldShowProjectsMenu() {
    return !isAdminProjectViewer();
  }

  function getVisibleSections() {
    const role = getProjectRole();
    if (isAdminProjectViewer()) {
      return [
        { label: 'Portfolio', keys: ['all-projects'] },
        { label: 'Archive', keys: ['archived-projects'] }
      ];
    }
    if (role === 'accountant') {
      return SECTIONS
        .map((section) => ({
          ...section,
          keys: section.keys.filter((key) => ACCOUNTANT_PROJECT_KEYS.has(key))
        }))
        .filter((section) => section.keys.length > 0);
    }
    return SECTIONS.filter((section) => section.keys.length > 0);
  }

  function canOpenFinanceModule() {
    const role = getProjectRole();
    return role === 'accountant';
  }

  function renderInlineProjectNav(ctx, activeKey) {
    if (!isAdminProjectViewer()) {
      return '';
    }

    const links = ['all-projects', 'archived-projects'].map((key) => {
      const page = PAGES[key];
      const href = resolvePageHref(ctx, key);
      const active = key === activeKey;
      return `
        <a href="${href}" class="rpn-inline-link${active ? ' active' : ''}"${active ? ' aria-current="page"' : ''}>
          ${page.label}
        </a>
      `;
    }).join('');

    return `<nav class="rpn-inline-nav" aria-label="Admin project views">${links}</nav>`;
  }

  function getContext() {
    const path = window.location.pathname.replace(/\\/g, '/').toLowerCase();
    const inDocuments = path.includes('/documents/');
    const inProjects = path.includes('/projects/');
    const inFinance = path.includes('/finance/');
    const inPlanning = path.includes('/planning/');
    const inReports = path.includes('/reports/');
    const moduleRoot = inDocuments || inProjects || inFinance || inPlanning || inReports;

    return {
      assetRoot: moduleRoot ? '../assets' : 'assets',
      dashboardHref: moduleRoot ? '../RDOMMS.html' : 'RDOMMS.html',
      projectPrefix: inProjects ? '.' : '../projects',
      documentsPrefix: inDocuments ? '.' : '../documents',
      financePrefix: inFinance ? '.' : '../finance',
      planningPrefix: inPlanning ? '.' : '../planning',
      reportsPrefix: inReports ? '.' : '../reports'
    };
  }

  function resolvePageHref(ctx, pageKey) {
    const page = PAGES[pageKey];
    if (!page) return '#';

    if (page.externalDir === 'documents') {
      return `${ctx.documentsPrefix}/${page.href}`;
    }

    if (ctx.projectPrefix === '.') {
      return page.href;
    }

    return `${ctx.projectPrefix}/${page.href}`;
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
      if (page.externalDir === 'documents') {
        return path.endsWith(`/documents/${page.href}`) || path.endsWith(page.href);
      }
      return path.endsWith(`/projects/${page.href}`) || path.endsWith(page.href);
    }) || 'all-projects';
  }

  function renderFinanceFooter(ctx) {
    if (!canOpenFinanceModule()) {
      return '';
    }

    const href = `${ctx.financePrefix}/financial-reports.php`;
    return `
      <div class="rpn-dropdown-footer">
        <a href="${href}" class="rpn-dropdown-footer-link" role="menuitem">
          <i class="fas fa-coins" aria-hidden="true"></i>
          <span>Open Finance Module</span>
          <i class="fas fa-arrow-right" aria-hidden="true"></i>
        </a>
      </div>
    `;
  }

  function renderDropdownItems(ctx, activeKey) {
    const sections = getVisibleSections().map((section) => {
      const items = section.keys.map((key) => {
        const page = PAGES[key];
        const href = resolvePageHref(ctx, key);
        const active = key === activeKey ? ' active' : '';
        const ariaCurrent = key === activeKey ? ' aria-current="page"' : '';

        return `
          <a href="${href}" class="rpn-dropdown-item${active}" role="menuitem"${ariaCurrent}>
            <span class="rpn-dropdown-icon"><img src="${iconUrl(ctx, page.icon)}" alt=""></span>
            <span class="rpn-dropdown-text">
              <span class="rpn-dropdown-title">${page.label}</span>
              <span class="rpn-dropdown-meta">${page.meta}</span>
            </span>
          </a>
        `;
      }).join('');

      return `
        <span class="rpn-dropdown-label">${section.label}</span>
        ${items}
      `;
    }).join('');

    return sections + renderFinanceFooter(ctx);
  }

  function bindMenu(root) {
    const ctx = getContext();
    const options = { title: 'Projects Menu' };

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
    const root = document.getElementById('rdomms-project-nav');
    if (!root) return;

    const ctx = getContext();
    const activeKey = detectActiveKey(root);
    const current = PAGES[activeKey] || PAGES['all-projects'];
    const logoSrc = `${ctx.assetRoot}/photos/RDO.png`;

    root.className = 'rdomms-project-nav' + (isAdminProjectViewer() ? ' rpn-nav--admin-minimal' : '');
    const actionsHtml = shouldShowProjectsMenu()
      ? `
          <div class="rpn-menu-wrap">
            <button type="button" class="rpn-menu-btn" aria-haspopup="true" aria-expanded="false" aria-controls="rpn-projects-menu">
              <i class="fas fa-folder-tree" aria-hidden="true"></i>
              <span>Projects Menu</span>
              <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="rpn-dropdown" id="rpn-projects-menu" role="menu" aria-label="Projects navigation">
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
        <span class="rpn-crumb">Projects</span>
        <i class="fas fa-chevron-right rpn-sep" aria-hidden="true"></i>
        <span class="rpn-crumb-active">${current.label}</span>
        <div class="rpn-actions">
          ${actionsHtml}
        </div>
      </div>
      ${renderInlineProjectNav(ctx, activeKey)}
    `;

    if (shouldShowProjectsMenu()) {
      bindMenu(root);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
