/**
 * Move dashboard overlay panels to document.body so fixed positioning
 * is not clipped by .main { overflow: hidden } or topbar backdrop-filter.
 */
(function () {
  'use strict';

  const MOBILE_MAX = 992;
  const GAP = 10;
  const MIN_WIDTH = 260;
  const DATE_MIN_WIDTH = 320;
  const VIEWPORT_PADDING = 8;

  const OVERLAY_IDS = [
    'userDropdown',
    'userDropdownBackdrop',
    'dateRangeBackdrop',
    'dateRangeDropdown'
  ];

  const POSITION_PROPS = [
    'top',
    'right',
    'left',
    'bottom',
    'width',
    'min-width',
    'max-height',
    'z-index'
  ];

  function isMobileViewport() {
    return window.matchMedia(`(max-width: ${MOBILE_MAX}px)`).matches;
  }

  function moveToBody(id) {
    const el = document.getElementById(id);
    if (!el || el.parentElement === document.body) {
      return;
    }
    document.body.appendChild(el);
  }

  function clearOverlayPosition(el) {
    if (!el) {
      return;
    }

    POSITION_PROPS.forEach((prop) => el.style.removeProperty(prop));
    el.style.removeProperty('position');
    el.style.removeProperty('--dropdown-arrow-right');
    el.classList.remove('user-dropdown--sheet', 'user-dropdown--anchored', 'user-dropdown--flip', 'date-range-dropdown--anchored', 'date-range-dropdown--flip');
  }

  function applyAnchoredPosition(dropdown, btn, minWidth) {
    const rect = btn.getBoundingClientRect();

    dropdown.style.position = 'fixed';
    dropdown.style.bottom = 'auto';
    dropdown.style.minWidth = `${minWidth}px`;
    dropdown.style.width = 'auto';
    dropdown.style.zIndex = '1100';

    const rightOffset = Math.round(window.innerWidth - rect.right);
    if (rect.right - minWidth < VIEWPORT_PADDING) {
      dropdown.style.right = 'auto';
      dropdown.style.left = `${VIEWPORT_PADDING}px`;
    } else {
      dropdown.style.left = 'auto';
      dropdown.style.right = `${rightOffset}px`;
    }

    const dropdownHeight = dropdown.offsetHeight || dropdown.scrollHeight || 320;
    const spaceBelow = window.innerHeight - rect.bottom - GAP - VIEWPORT_PADDING;
    const spaceAbove = rect.top - GAP - VIEWPORT_PADDING;
    const openBelow = spaceBelow >= dropdownHeight || spaceBelow >= spaceAbove;

    if (openBelow) {
      dropdown.classList.remove('user-dropdown--flip', 'date-range-dropdown--flip');
      dropdown.style.top = `${Math.round(rect.bottom + GAP)}px`;
      dropdown.style.maxHeight = `${Math.max(160, Math.floor(spaceBelow))}px`;
      const arrowRight = Math.max(18, Math.round(rect.width / 2) - 7);
      dropdown.style.setProperty('--dropdown-arrow-right', `${arrowRight}px`);
    } else {
      dropdown.classList.add('user-dropdown--flip', 'date-range-dropdown--flip');
      dropdown.style.top = `${Math.round(rect.top - GAP - dropdownHeight)}px`;
      dropdown.style.maxHeight = `${Math.max(160, Math.floor(spaceAbove))}px`;
      dropdown.style.removeProperty('--dropdown-arrow-right');
    }
  }

  function clearUserDropdownPosition(dropdown) {
    clearOverlayPosition(dropdown);
  }

  function syncUserDropdownLayout() {
    const dropdown = document.getElementById('userDropdown');
    const btn = document.getElementById('userMenuBtn');
    if (!dropdown || !btn) {
      return;
    }

    clearUserDropdownPosition(dropdown);

    if (isMobileViewport()) {
      dropdown.classList.add('user-dropdown--sheet');
      document.body.classList.toggle('user-menu-mobile', dropdown.classList.contains('show'));
      document.body.classList.remove('user-menu-desktop');
      return;
    }

    dropdown.classList.add('user-dropdown--anchored');
    document.body.classList.toggle('user-menu-desktop', dropdown.classList.contains('show'));
    document.body.classList.remove('user-menu-mobile');
    applyAnchoredPosition(dropdown, btn, MIN_WIDTH);
  }

  function syncDateRangeDropdownLayout() {
    const dropdown = document.getElementById('dateRangeDropdown');
    const btn = document.getElementById('datePicker');
    if (!dropdown || !btn || !dropdown.classList.contains('show')) {
      return;
    }

    clearOverlayPosition(dropdown);

    if (isMobileViewport()) {
      return;
    }

    dropdown.classList.add('date-range-dropdown--anchored');
    applyAnchoredPosition(dropdown, btn, DATE_MIN_WIDTH);
  }

  function initDashboardOverlays() {
    if (!document.body.classList.contains('rdomms-dashboard')) {
      return;
    }
    OVERLAY_IDS.forEach(moveToBody);
  }

  function handleViewportChange() {
    const userDropdown = document.getElementById('userDropdown');
    if (userDropdown && userDropdown.classList.contains('show')) {
      syncUserDropdownLayout();
    }

    const dateDropdown = document.getElementById('dateRangeDropdown');
    if (dateDropdown && dateDropdown.classList.contains('show')) {
      syncDateRangeDropdownLayout();
    } else {
      document.body.classList.remove('user-menu-mobile', 'user-menu-desktop');
    }
  }

  window.RDOMMSMobileOverlays = {
    syncUserDropdownLayout,
    syncDateRangeDropdownLayout,
    clearUserDropdownPosition,
    positionUserDropdown: syncUserDropdownLayout,
    isMobileViewport
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboardOverlays);
  } else {
    initDashboardOverlays();
  }

  window.addEventListener('resize', handleViewportChange);
  window.addEventListener('scroll', handleViewportChange, true);
})();
