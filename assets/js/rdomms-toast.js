/**
 * RDOMMS top toast notification system
 */
(function (global) {
  const ICONS = {
    success: 'fa-circle-check',
    error: 'fa-circle-exclamation',
    info: 'fa-circle-info',
    warning: 'fa-triangle-exclamation'
  };

  const TITLES = {
    success: 'Success',
    error: 'Alert',
    info: 'Notification',
    warning: 'Warning'
  };

  const DURATIONS = {
    success: 4200,
    info: 5500,
    warning: 7200,
    error: 8200
  };

  function normalizeType(type) {
    const value = String(type || 'info').toLowerCase();
    if (value === 'alert') return 'error';
    if (value === 'danger') return 'error';
    if (value === 'warn') return 'warning';
    return ICONS[value] ? value : 'info';
  }

  function ensurePortal() {
    let portal = document.getElementById('rdomms-toast-portal');
    if (!portal) {
      portal = document.createElement('div');
      portal.id = 'rdomms-toast-portal';
      portal.setAttribute('aria-live', 'polite');
      portal.setAttribute('aria-atomic', 'true');
      document.body.appendChild(portal);
    }
    return portal;
  }

  function createIconNode(iconName) {
    const iconWrap = document.createElement('div');
    iconWrap.className = 'rdomms-toast-icon';

    const icon = document.createElement('i');
    icon.className = `fas ${iconName}`;
    icon.setAttribute('aria-hidden', 'true');
    iconWrap.appendChild(icon);

    return iconWrap;
  }

  function getDuration(type, options) {
    if (typeof options.duration === 'number') return options.duration;
    return DURATIONS[type] || DURATIONS.info;
  }

  function dismiss(card) {
    if (!card || card.classList.contains('toast-exit')) return;
    card.classList.add('toast-exit');
    window.setTimeout(() => {
      if (card && card.parentNode) {
        card.parentNode.removeChild(card);
      }
    }, 220);
  }

  function show(message, type, options) {
    const normalizedType = normalizeType(type);
    const config = options || {};
    const portal = ensurePortal();
    const title = config.title || TITLES[normalizedType] || 'Notification';
    const icon = config.icon || ICONS[normalizedType] || ICONS.info;
    const duration = getDuration(normalizedType, config);
    const actionLabel = Object.prototype.hasOwnProperty.call(config, 'actionLabel') ? config.actionLabel : '';
    const secondaryLabel = config.secondaryLabel || '';
    const onAction = typeof config.onAction === 'function' ? config.onAction : null;
    const onSecondary = typeof config.onSecondary === 'function' ? config.onSecondary : null;
    const showClose = config.showClose !== false;
    const allowMultiple = config.allowMultiple !== false;

    if (!allowMultiple) {
      portal.querySelectorAll('.rdomms-toast-card').forEach(dismiss);
    }

    const card = document.createElement('div');
    card.className = `rdomms-toast-card ${normalizedType}`;
    card.setAttribute('role', normalizedType === 'error' ? 'alert' : 'status');

    const body = document.createElement('div');
    body.className = 'rdomms-toast-body-wrap';

    const iconWrap = createIconNode(icon);

    const textWrap = document.createElement('div');
    textWrap.className = 'rdomms-toast-body';
    textWrap.innerHTML =
      `<div class="rdomms-toast-title">${escapeHtml(title)}</div>` +
      `<div class="rdomms-toast-message">${escapeHtml(message)}</div>`;

    const metaWrap = document.createElement('div');
    metaWrap.className = 'rdomms-toast-meta';
    metaWrap.innerHTML = `<span class="rdomms-toast-chip ${normalizedType}">${escapeHtml(normalizedType)}</span>`;
    textWrap.appendChild(metaWrap);

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'rdomms-toast-close';
    closeBtn.setAttribute('aria-label', 'Close notification');
    closeBtn.innerHTML = '&times;';
    closeBtn.addEventListener('click', () => dismiss(card));

    body.appendChild(iconWrap);
    body.appendChild(textWrap);
    if (showClose) body.appendChild(closeBtn);
    card.appendChild(body);

    const progress = document.createElement('div');
    progress.className = 'rdomms-toast-progress';
    progress.style.animationDuration = `${Math.max(duration, 1200)}ms`;
    card.appendChild(progress);

    const actions = document.createElement('div');
    actions.className = 'rdomms-toast-actions';

    if (secondaryLabel && onSecondary) {
      const secondary = document.createElement('button');
      secondary.type = 'button';
      secondary.className = 'rdomms-toast-button secondary';
      secondary.textContent = secondaryLabel;
      secondary.addEventListener('click', () => {
        onSecondary();
        dismiss(card);
      });
      actions.appendChild(secondary);
    }

    if (actionLabel || onAction) {
      const primary = document.createElement('button');
      primary.type = 'button';
      primary.className = 'rdomms-toast-button primary';
      primary.textContent = actionLabel || 'OK';
      primary.addEventListener('click', () => {
        if (onAction) onAction();
        dismiss(card);
      });
      actions.appendChild(primary);
    }

    if (actions.childNodes.length) {
      card.appendChild(actions);
    }

    portal.prepend(card);
    window.requestAnimationFrame(() => card.classList.add('show'));

    if (duration > 0) {
      window.setTimeout(() => dismiss(card), duration);
    }

    return card;
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  global.RDOMMSToast = { show, dismiss };
  global.showNotification = function (message, type, options) {
    return show(message, type, options);
  };

  if (!global.__rdommsNativeAlert) {
    global.__rdommsNativeAlert = global.alert;
    global.alert = function (message) {
      show(message, 'warning', {
        title: 'Alert',
        duration: 6500,
        actionLabel: 'OK',
        allowMultiple: false
      });
    };
  }
})(typeof window !== 'undefined' ? window : this);
