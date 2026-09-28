/**
 * Shared Finance module navbar — mirrors RDOMMS.html sidebar Finance menu.
 */
(function () {
  const PAGES = {
    'budget-allocation': {
      label: 'Budget Allocation',
      meta: 'Plan departmental budgets',
      href: 'budget-allocation.php',
      icon: 'budgetallocation.png'
    },
    'project-budget-approvals': {
      label: 'Project Budget Approvals',
      meta: 'Approve pending project budgets',
      href: 'project-budget-approvals.php',
      icon: 'statustrack.png'
    },
    'expense-tracking': {
      label: 'Expense Tracking',
      meta: 'Record actual spending',
      href: 'expense-tracking.php',
      icon: 'expense.png'
    },
    'financial-reports': {
      label: 'Financial Reports',
      meta: 'Summary and ledger views',
      href: 'financial-reports.php',
      icon: 'financialreport.png'
    },
    'variance-analysis': {
      label: 'Variance Analysis',
      meta: 'Budget vs actual',
      href: '../reports/variance-analysis.php',
      icon: 'varianceanalysis.png',
      external: true
    },
    'all-projects': {
      label: 'All Projects',
      meta: 'Project portfolio',
      href: '../projects/all-projects.php',
      icon: 'allproject.png',
      projectKey: true
    },
    'project-health': {
      label: 'Project Health',
      meta: 'Validation and compliance',
      href: '../projects/project-health.php',
      icon: 'statustrack.png',
      projectKey: true
    },
    'status-tracking': {
      label: 'Status Tracking',
      meta: 'Progress updates',
      href: '../projects/status-tracking.php',
      icon: 'statustrack.png',
      projectKey: true
    }
  };

  const SECTIONS = [
    { label: 'Finance', keys: ['project-budget-approvals', 'budget-allocation', 'expense-tracking', 'financial-reports'] },
    { label: 'Projects', keys: ['all-projects', 'project-health', 'status-tracking'] },
    { label: 'Reports', keys: ['variance-analysis'] }
  ];

  function getFinanceRole() {
    try {
      const user = JSON.parse(localStorage.getItem('currentUser') || '{}');
      return String(user.role || '').toLowerCase();
    } catch (_) {
      return '';
    }
  }

  function getVisibleFinanceKeys() {
    const role = getFinanceRole();
    if (role === 'accountant') {
      return new Set(['project-budget-approvals', 'budget-allocation', 'expense-tracking', 'financial-reports', 'variance-analysis', 'all-projects', 'project-health', 'status-tracking']);
    }
    if (role === 'admin') {
      return new Set(['project-budget-approvals', 'financial-reports', 'variance-analysis', 'all-projects', 'project-health', 'status-tracking']);
    }
    if (role === 'head_of_organization_center' || role === 'head_of_department') {
      return new Set(['project-budget-approvals', 'financial-reports', 'all-projects', 'project-health', 'status-tracking']);
    }
    return new Set(['financial-reports', 'all-projects', 'project-health', 'status-tracking']);
  }

  function getVisibleSections() {
    const visible = getVisibleFinanceKeys();
    return SECTIONS
      .map((section) => ({
        ...section,
        keys: section.keys.filter((key) => visible.has(key))
      }))
      .filter((section) => section.keys.length > 0);
  }

  function getContext() {
    const path = window.location.pathname.replace(/\\/g, '/').toLowerCase();
    const inFinance = path.includes('/finance/');
    const inReports = path.includes('/reports/');

    return {
      assetRoot: inFinance || inReports ? '../assets' : 'assets',
      dashboardHref: inFinance || inReports ? '../RDOMMS.html' : 'RDOMMS.html',
      financePrefix: inFinance ? '.' : 'finance',
      reportsPrefix: inReports ? '.' : 'reports'
    };
  }

  function resolvePageHref(ctx, pageKey) {
    const page = PAGES[pageKey];
    if (!page) return '#';
    if (page.external) {
      if (ctx.financePrefix === '.') {
        return page.href;
      }
      return `${ctx.reportsPrefix}/${page.href.replace('../reports/', '')}`;
    }
    if (page.projectKey) {
      return page.href;
    }
    if (ctx.financePrefix === '.') {
      return page.href;
    }
    return `${ctx.financePrefix}/${page.href}`;
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
    if (path.includes('variance-analysis')) {
      return 'variance-analysis';
    }
    return Object.keys(PAGES).find((key) => {
      const page = PAGES[key];
      if (page.external) return false;
      return path.endsWith(`/finance/${page.href}`) || path.endsWith(page.href);
    }) || 'budget-allocation';
  }

  function renderDropdownItems(ctx, activeKey) {
    return getVisibleSections().map((section) => {
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
  }

  function bindMenu(root) {
    const ctx = getContext();
    const options = { title: 'Finance Menu' };

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
    const root = document.getElementById('rdomms-finance-nav');
    if (!root) return;

    const ctx = getContext();
    const visible = getVisibleFinanceKeys();
    const activeKey = visible.has(detectActiveKey(root)) ? detectActiveKey(root) : (visible.has('financial-reports') ? 'financial-reports' : Array.from(visible)[0] || 'financial-reports');
    const current = PAGES[activeKey] || PAGES['financial-reports'];
    const logoSrc = `${ctx.assetRoot}/photos/RDO.png`;

    root.className = 'rdomms-project-nav rdomms-finance-nav';
    root.innerHTML = `
      <div class="rpn-topbar">
        <a href="${ctx.dashboardHref}" class="rpn-brand" title="Back to RDO|MMS Dashboard">
          <img src="${logoSrc}" alt="RDO|MMS">
          RDO|MMS
        </a>
        <i class="fas fa-chevron-right rpn-sep" aria-hidden="true"></i>
        <span class="rpn-crumb">Finance</span>
        <i class="fas fa-chevron-right rpn-sep" aria-hidden="true"></i>
        <span class="rpn-crumb-active">${current.label}</span>
        <div class="rpn-actions">
          <div class="rpn-menu-wrap">
            <button type="button" class="rpn-menu-btn" aria-haspopup="true" aria-expanded="false" aria-controls="rpn-finance-menu">
              <i class="fas fa-coins" aria-hidden="true"></i>
              <span>Finance Menu</span>
              <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="rpn-dropdown" id="rpn-finance-menu" role="menu" aria-label="Finance navigation">
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

  document.addEventListener('DOMContentLoaded', render);
})();
