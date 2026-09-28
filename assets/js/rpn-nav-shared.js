/**
 * Shared mobile/desktop behavior for module nav dropdowns (Projects, Finance, Reports, Planning).
 */
(function () {
  'use strict';

  const MOBILE_MAX = 992;

  function isMobileViewport() {
    return window.matchMedia(`(max-width: ${MOBILE_MAX}px)`).matches;
  }

  function ensureBackdrop(panel) {
    const backdropId = `${panel.id || 'rpn-dropdown'}-backdrop`;
    let backdrop = document.getElementById(backdropId);
    if (!backdrop) {
      backdrop = document.createElement('button');
      backdrop.type = 'button';
      backdrop.className = 'rpn-dropdown-backdrop';
      backdrop.id = backdropId;
      backdrop.setAttribute('aria-hidden', 'true');
      backdrop.tabIndex = -1;
      document.body.appendChild(backdrop);
    }
    return backdrop;
  }

  function ensureMobileBar(panel, title) {
    if (panel.querySelector('.rpn-dropdown-mobile-bar')) {
      const label = panel.querySelector('.rpn-dropdown-mobile-bar strong');
      if (label) {
        label.textContent = title;
      }
      return panel.querySelector('.rpn-dropdown-close');
    }

    const bar = document.createElement('div');
    bar.className = 'rpn-dropdown-mobile-bar';
    bar.innerHTML = `
      <strong></strong>
      <button type="button" class="rpn-dropdown-close" aria-label="Close menu">
        <i class="fas fa-xmark" aria-hidden="true"></i>
      </button>
    `;
    bar.querySelector('strong').textContent = title;
    panel.insertBefore(bar, panel.firstChild);
    return bar.querySelector('.rpn-dropdown-close');
  }

  function moveToBody(el) {
    if (el && el.parentElement !== document.body) {
      document.body.appendChild(el);
    }
  }

  function clearAnchoredStyles(panel) {
    ['position', 'top', 'right', 'left', 'bottom', 'width', 'max-width', 'z-index', 'max-height']
      .forEach((prop) => panel.style.removeProperty(prop));
  }

  function clearDropdownMode(panel) {
    panel.classList.remove('rpn-dropdown--sheet', 'rpn-dropdown--anchored');
    clearAnchoredStyles(panel);
  }

  function positionDropdown(panel, btn) {
    if (isMobileViewport()) {
      panel.classList.remove('rpn-dropdown--anchored');
      panel.classList.add('rpn-dropdown--sheet');
      clearAnchoredStyles(panel);
      return;
    }

    panel.classList.remove('rpn-dropdown--sheet');
    panel.classList.add('rpn-dropdown--anchored');
    clearAnchoredStyles(panel);

    const rect = btn.getBoundingClientRect();
    const gap = 8;
    const panelWidth = panel.offsetWidth || 320;
    const viewportPadding = 8;

    panel.style.position = 'fixed';
    panel.style.zIndex = '1300';

    const spaceBelow = window.innerHeight - rect.bottom - gap - viewportPadding;
    const spaceAbove = rect.top - gap - viewportPadding;
    const panelHeight = panel.offsetHeight || panel.scrollHeight || 360;
    const openBelow = spaceBelow >= panelHeight || spaceBelow >= spaceAbove;

    if (openBelow) {
      panel.style.top = `${Math.round(rect.bottom + gap)}px`;
      panel.style.maxHeight = `${Math.max(160, Math.floor(spaceBelow))}px`;
    } else {
      panel.style.top = `${Math.round(rect.top - gap - panelHeight)}px`;
      panel.style.maxHeight = `${Math.max(160, Math.floor(spaceAbove))}px`;
    }

    const rightOffset = Math.round(window.innerWidth - rect.right);
    if (rect.right - panelWidth < viewportPadding) {
      panel.style.right = 'auto';
      panel.style.left = `${viewportPadding}px`;
    } else {
      panel.style.left = 'auto';
      panel.style.right = `${rightOffset}px`;
    }
  }

  function closeOtherMenus(currentPanel) {
    document.querySelectorAll('.rpn-dropdown.open').forEach((panel) => {
      if (panel !== currentPanel) {
        panel.classList.remove('open');
        clearDropdownMode(panel);
      }
    });
    document.querySelectorAll('.rpn-dropdown-backdrop.show').forEach((backdrop) => {
      backdrop.classList.remove('show');
      backdrop.setAttribute('aria-hidden', 'true');
    });
    document.querySelectorAll('.rpn-menu-btn[aria-expanded="true"]').forEach((button) => {
      if (!currentPanel || !currentPanel.id || button.getAttribute('aria-controls') !== currentPanel.id) {
        button.setAttribute('aria-expanded', 'false');
      }
    });
    document.body.classList.remove('rpn-menu-open');
  }

  function bindMenu(root, options) {
    const opts = options || {};
    const btn = root.querySelector('.rpn-menu-btn');
    const panel = root.querySelector('.rpn-dropdown');
    if (!btn || !panel) {
      return;
    }

    const title = opts.title || btn.textContent.replace(/\s+/g, ' ').trim() || 'Menu';
    const backdrop = ensureBackdrop(panel);
    const closeBtn = ensureMobileBar(panel, title);

    const closeMenu = () => {
      panel.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      backdrop.classList.remove('show');
      backdrop.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('rpn-menu-open');
      clearDropdownMode(panel);
    };

    const openMenu = () => {
      closeOtherMenus(panel);
      moveToBody(panel);
      moveToBody(backdrop);

      if (isMobileViewport()) {
        panel.classList.add('rpn-dropdown--sheet');
        panel.classList.remove('open');
        backdrop.classList.remove('show');
        document.body.classList.add('rpn-menu-open');

        requestAnimationFrame(() => {
          panel.classList.add('open');
          backdrop.classList.add('show');
          backdrop.setAttribute('aria-hidden', 'false');
          btn.setAttribute('aria-expanded', 'true');
        });
        return;
      }

      panel.classList.remove('rpn-dropdown--sheet');
      panel.classList.add('open');
      btn.setAttribute('aria-expanded', 'true');
      backdrop.classList.remove('show');
      backdrop.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('rpn-menu-open');

      requestAnimationFrame(() => {
        positionDropdown(panel, btn);
        requestAnimationFrame(() => positionDropdown(panel, btn));
      });
    };

    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (panel.classList.contains('open')) {
        closeMenu();
      } else {
        openMenu();
      }
    });

    backdrop.addEventListener('click', closeMenu);
    if (closeBtn) {
      closeBtn.addEventListener('click', (event) => {
        event.preventDefault();
        closeMenu();
      });
    }

    panel.addEventListener('click', (event) => {
      event.stopPropagation();
    });

    document.addEventListener('click', (event) => {
      if (!panel.classList.contains('open')) {
        return;
      }
      if (btn.contains(event.target) || panel.contains(event.target)) {
        return;
      }
      closeMenu();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && panel.classList.contains('open')) {
        closeMenu();
        btn.focus();
      }
    });

    panel.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', closeMenu);
    });

    const handleViewportChange = () => {
      if (!panel.classList.contains('open')) {
        return;
      }

      moveToBody(panel);
      moveToBody(backdrop);

      if (isMobileViewport()) {
        panel.classList.add('rpn-dropdown--sheet');
        panel.classList.remove('rpn-dropdown--anchored');
        clearAnchoredStyles(panel);
        backdrop.classList.add('show');
        backdrop.setAttribute('aria-hidden', 'false');
        document.body.classList.add('rpn-menu-open');
        return;
      }

      backdrop.classList.remove('show');
      backdrop.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('rpn-menu-open');
      positionDropdown(panel, btn);
    };

    window.addEventListener('resize', handleViewportChange);
    window.addEventListener('scroll', handleViewportChange, true);
  }

  window.RPNNav = {
    bindMenu,
    isMobileViewport,
    _ready: true
  };
})();
