/**
 * RDOMMS Data Backup — database-backed backup and restore.
 */
(function () {
  const PAGE = window.RDOMMS_DATA_BACKUP || {};
  const API = PAGE.apiBackup || '../api/settings/data-backup.php';

  let pendingRestore = null;

  function $(id) {
    return document.getElementById(id);
  }

  function setLoading(active) {
    $('pageLoading')?.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', {
        title: 'Data Backup',
        duration: 5000,
        allowMultiple: true
      });
    } else {
      alert(msg);
    }
  }

  function formatBytes(bytes) {
    const value = Number(bytes) || 0;
    if (value < 1024) return value + ' B';
    if (value < 1048576) return (value / 1024).toFixed(1) + ' KB';
    return (value / 1048576).toFixed(2) + ' MB';
  }

  function formatDate(value) {
    if (!value) return 'Unknown date';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
  }

  function renderSummary(summary) {
    if ($('statTables')) $('statTables').textContent = summary?.tables_available ?? 0;
    if ($('statRecords')) $('statRecords').textContent = summary?.records_available ?? 0;
    if ($('statBackups')) $('statBackups').textContent = summary?.stored_backups ?? 0;
    if ($('statBytes')) $('statBytes').textContent = formatBytes(summary?.stored_bytes ?? 0);
  }

  function fillSettings(settings) {
    if ($('autoEnabled')) $('autoEnabled').checked = !!settings.autoEnabled;
    if ($('autoFrequency')) $('autoFrequency').value = settings.autoFrequency || 'weekly';
    if ($('retentionCount')) $('retentionCount').value = settings.retentionCount || 10;
    if ($('lastAutoBackupLabel')) {
      $('lastAutoBackupLabel').textContent = settings.lastAutoBackup
        ? 'Last automatic backup: ' + formatDate(settings.lastAutoBackup)
        : 'No automatic backup has run yet.';
    }
  }

  function readSettingsForm() {
    return {
      autoEnabled: !!$('autoEnabled')?.checked,
      autoFrequency: $('autoFrequency')?.value || 'weekly',
      retentionCount: parseInt($('retentionCount')?.value || '10', 10)
    };
  }

  function renderBackups(backups) {
    const container = $('backupList');
    if (!container) return;

    if (!backups || !backups.length) {
      container.innerHTML = '<div class="empty-state">No server backups yet. Create your first database backup above.</div>';
      return;
    }

    container.innerHTML = backups.map((backup) => {
      const type = backup.backupType || backup.backup_type || 'manual';
      const records = backup.recordCount ?? backup.record_count ?? 0;
      const tables = backup.tablesCount ?? backup.tables_count ?? 0;
      const size = formatBytes(backup.fileSize ?? backup.file_size ?? 0);
      return `
        <div class="backup-item">
          <div class="backup-meta">
            <strong><i class="fas fa-archive"></i> ${formatDate(backup.createdAt || backup.created_at)}</strong>
            <span>${backup.id} · ${type} · ${tables} tables · ${records} records · ${size}</span>
          </div>
          <div class="backup-actions">
            <button type="button" class="btn btn-light" data-action="download" data-id="${backup.id}"><i class="fas fa-download"></i> Download</button>
            <button type="button" class="btn btn-light" data-action="restore" data-id="${backup.id}"><i class="fas fa-rotate-left"></i> Restore</button>
            <button type="button" class="btn btn-danger" data-action="delete" data-id="${backup.id}"><i class="fas fa-trash"></i> Delete</button>
          </div>
        </div>
      `;
    }).join('');
  }

  async function fetchState() {
    const response = await fetch(API, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      const fallback = response.status === 401
        ? 'Please sign in again as administrator.'
        : response.status === 403
          ? 'Only administrators can manage database backups.'
          : 'Failed to load backup center';
      throw new Error(payload?.message || fallback);
    }
    return payload.data || {};
  }

  async function init() {
    setLoading(true);
    try {
      const data = await fetchState();
      renderSummary(data.summary || {});
      fillSettings(data.settings || {});
      renderBackups(data.backups || []);
    } catch (error) {
      notify(error.message || 'Unable to load backup center.', 'error');
    } finally {
      setLoading(false);
    }
  }

  function openRestoreModal(mode, backupId, filename) {
    pendingRestore = { mode, backupId, filename: filename || null };
    if ($('restoreConfirmInput')) $('restoreConfirmInput').value = '';
    if ($('restoreModalText')) {
      $('restoreModalText').textContent = mode === 'upload'
        ? 'This will overwrite current database records with the uploaded backup file.'
        : 'This will overwrite current database records with backup ' + backupId + '.';
    }
    $('restoreModal')?.classList.add('show');
  }

  function closeRestoreModal() {
    pendingRestore = null;
    $('restoreModal')?.classList.remove('show');
  }

  async function performRestore() {
    const confirmText = ($('restoreConfirmInput')?.value || '').trim().toUpperCase();
    if (confirmText !== 'RESTORE') {
      notify('Type RESTORE to confirm.', 'warning');
      return;
    }

    const btn = $('confirmRestoreBtn');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring…';
    }

    try {
      if (pendingRestore?.mode === 'upload' && pendingRestore.file) {
        const formData = new FormData();
        formData.append('backup_file', pendingRestore.file);
        formData.append('confirm', 'RESTORE');
        const response = await fetch(API, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: formData
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.status !== 'success') {
          throw new Error(payload?.message || 'Restore failed');
        }
        notify(payload.message || 'Database restored successfully.', 'success');
      } else if (pendingRestore?.backupId) {
        const response = await fetch(API, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin',
          body: JSON.stringify({
            action: 'restore',
            backup_id: pendingRestore.backupId,
            confirm: 'RESTORE'
          })
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.status !== 'success') {
          throw new Error(payload?.message || 'Restore failed');
        }
        notify(payload.message || 'Database restored successfully.', 'success');
      }

      closeRestoreModal();
      await init();
    } catch (error) {
      notify(error.message || 'Restore failed.', 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = 'Restore Backup';
      }
    }
  }

  $('createBackupBtn')?.addEventListener('click', async () => {
    const btn = $('createBackupBtn');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating…';
    }

    try {
      const response = await fetch(API, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'create' })
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.status !== 'success') {
        throw new Error(payload?.message || 'Backup creation failed');
      }
      renderSummary(payload.data?.summary || {});
      renderBackups(payload.data?.backups || []);
      notify(payload.message || 'Database backup created successfully.', 'success');
    } catch (error) {
      notify(error.message || 'Backup creation failed.', 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus"></i> Create Database Backup';
      }
    }
  });

  $('saveSettingsBtn')?.addEventListener('click', async () => {
    const btn = $('saveSettingsBtn');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    }

    try {
      const response = await fetch(API, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify(readSettingsForm())
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.status !== 'success') {
        throw new Error(payload?.message || 'Failed to save backup settings');
      }
      fillSettings(payload.data?.settings || {});
      notify(payload.message || 'Backup settings saved successfully.', 'success');
    } catch (error) {
      notify(error.message || 'Failed to save backup settings.', 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Backup Settings';
      }
    }
  });

  $('importBackupBtn')?.addEventListener('click', () => {
    $('importFileInput')?.click();
  });

  $('importFileInput')?.addEventListener('change', (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    pendingRestore = { mode: 'upload', file };
    openRestoreModal('upload');
    event.target.value = '';
  });

  $('backupList')?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action]');
    if (!button) return;

    const action = button.getAttribute('data-action');
    const backupId = button.getAttribute('data-id');
    if (!backupId) return;

    if (action === 'download') {
      window.location.href = API + '?id=' + encodeURIComponent(backupId) + '&download=1';
      return;
    }

    if (action === 'restore') {
      openRestoreModal('server', backupId);
      return;
    }

    if (action === 'delete') {
      if (!window.confirm('Delete backup ' + backupId + '?')) return;
      try {
        const response = await fetch(API + '?id=' + encodeURIComponent(backupId), {
          method: 'DELETE',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.status !== 'success') {
          throw new Error(payload?.message || 'Delete failed');
        }
        renderSummary(payload.data?.summary || {});
        renderBackups(payload.data?.backups || []);
        notify(payload.message || 'Backup deleted successfully.', 'success');
      } catch (error) {
        notify(error.message || 'Delete failed.', 'error');
      }
    }
  });

  $('cancelRestoreBtn')?.addEventListener('click', closeRestoreModal);
  $('confirmRestoreBtn')?.addEventListener('click', performRestore);
  $('restoreModal')?.addEventListener('click', (event) => {
    if (event.target === $('restoreModal')) closeRestoreModal();
  });

  init();
})();
