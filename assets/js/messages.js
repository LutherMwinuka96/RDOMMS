/**
 * RDOMMS Messages — database-backed notification messaging.
 */
(function () {
  const PAGE = window.RDOMMS_MESSAGES_PAGE || {};
  const API = PAGE.apiMessages || '../api/messages.php';

  let threads = [];
  let activeThreadId = null;
  let activeMessages = [];

  function $(id) { return document.getElementById(id); }

  function setLoading(active) {
    $('pageLoading')?.classList.toggle('hidden', !active);
  }

  function notify(msg, type) {
    if (window.RDOMMSToast) {
      RDOMMSToast.show(msg, type || 'info', { title: 'Messages', duration: 4000, allowMultiple: true });
    } else {
      alert(msg);
    }
  }

  function escapeHtml(v) {
    return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function timeAgo(iso) {
    if (!iso) return '';
    const diff = Date.now() - new Date(iso).getTime();
    const m = Math.floor(diff / 60000);
    if (m < 1) return 'Just now';
    if (m < 60) return `${m}m ago`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h ago`;
    return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
  }

  async function fetchThreads() {
    const response = await fetch(API, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load conversations');
    }
    return Array.isArray(payload.data) ? payload.data : [];
  }

  async function fetchThread(threadId) {
    const response = await fetch(`${API}?thread_id=${encodeURIComponent(threadId)}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to load conversation');
    }
    return payload.data;
  }

  async function createThread(data) {
    const response = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to create conversation');
    }
    return payload.data;
  }

  async function sendMessage(threadId, text) {
    const response = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ thread_id: threadId, text, sender: 'me' })
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to send message');
    }
  }

  async function markRead(threadId) {
    await fetch(API, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      body: JSON.stringify({ thread_id: threadId, action: 'mark_read' })
    });
  }

  async function deleteThread(threadId) {
    const response = await fetch(`${API}?thread_id=${encodeURIComponent(threadId)}`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.status !== 'success') {
      throw new Error(payload?.message || 'Failed to delete conversation');
    }
  }

  function renderContacts() {
    const q = ($('contactSearch')?.value || '').trim().toLowerCase();
    const filtered = threads.filter((t) => t.name.toLowerCase().includes(q));
    const list = $('contactsList');
    if (!list) return;

    if (!filtered.length) {
      list.innerHTML = '<div class="empty-contact">No conversations yet.</div>';
      return;
    }

    list.innerHTML = filtered.map((t) => `
      <button type="button" class="contact-item ${activeThreadId === t.id ? 'active' : ''}" data-id="${escapeHtml(t.id)}">
        <div class="contact-name">${escapeHtml(t.name)}</div>
        <div class="contact-preview">${escapeHtml(t.lastSender === 'me' ? `You: ${t.lastMessage || ''}` : (t.lastMessage || 'No messages yet'))}</div>
        <div class="contact-meta">${escapeHtml(timeAgo(t.lastAt || t.updatedAt))}${t.unread ? `<span class="unread-badge">${t.unread}</span>` : ''}</div>
      </button>
    `).join('');
  }

  function renderChat() {
    const area = $('chatArea');
    if (!area) return;

    if (!activeThreadId) {
      area.innerHTML = '<div class="empty-chat"><i class="fas fa-paper-plane"></i><p>Select a conversation or start a new one.</p></div>';
      return;
    }

    const thread = threads.find((t) => t.id === activeThreadId);
    area.innerHTML = `
      <div class="chat-header">
        <div><strong>${escapeHtml(thread?.name || 'Conversation')}</strong><div class="muted">${escapeHtml(thread?.role || '')}</div></div>
        <button type="button" class="btn btn-light btn-sm" id="deleteConvoBtn"><i class="fas fa-trash"></i></button>
      </div>
      <div class="messages-scroll" id="messagesScroll">
        ${activeMessages.map((m) => `
          <div class="bubble-row ${m.from === 'me' ? 'mine' : 'theirs'}">
            <div class="bubble">${escapeHtml(m.text)}</div>
            <div class="bubble-time">${escapeHtml(timeAgo(m.ts))}</div>
          </div>
        `).join('')}
      </div>
      <div class="composer">
        <textarea id="composerInput" rows="1" placeholder="Type a message…"></textarea>
        <button type="button" class="btn btn-primary" id="sendBtn"><i class="fas fa-paper-plane"></i></button>
      </div>`;

    $('deleteConvoBtn')?.addEventListener('click', async () => {
      if (!confirm('Delete this conversation?')) return;
      setLoading(true);
      try {
        await deleteThread(activeThreadId);
        activeThreadId = null;
        activeMessages = [];
        await refresh();
        notify('Conversation deleted.', 'success');
      } catch (error) {
        notify(error.message, 'error');
      } finally {
        setLoading(false);
      }
    });

    $('sendBtn')?.addEventListener('click', handleSend);
    $('composerInput')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
      }
    });
  }

  async function openThread(id) {
    setLoading(true);
    try {
      const data = await fetchThread(id);
      activeThreadId = id;
      activeMessages = Array.isArray(data.messages) ? data.messages : [];
      await markRead(id);
      threads = await fetchThreads();
      renderContacts();
      renderChat();
      $('messagesScroll')?.scrollTo(0, $('messagesScroll').scrollHeight);
    } catch (error) {
      notify(error.message, 'error');
    } finally {
      setLoading(false);
    }
  }

  async function handleSend() {
    const text = ($('composerInput')?.value || '').trim();
    if (!text || !activeThreadId) return;
    setLoading(true);
    try {
      await sendMessage(activeThreadId, text);
      const data = await fetchThread(activeThreadId);
      activeMessages = Array.isArray(data.messages) ? data.messages : [];
      threads = await fetchThreads();
      renderContacts();
      renderChat();
    } catch (error) {
      notify(error.message, 'error');
    } finally {
      setLoading(false);
    }
  }

  async function refresh() {
    threads = await fetchThreads();
    renderContacts();
    renderChat();
  }

  function bindUi() {
    $('contactsList')?.addEventListener('click', (e) => {
      const item = e.target.closest('.contact-item[data-id]');
      if (!item) return;
      openThread(item.dataset.id);
    });

    $('contactSearch')?.addEventListener('input', renderContacts);

    $('newConvoBtn')?.addEventListener('click', () => $('newConvoModal')?.classList.add('open'));
    $('closeNewConvoBtn')?.addEventListener('click', () => $('newConvoModal')?.classList.remove('open'));
    $('createConvoBtn')?.addEventListener('click', async () => {
      const name = ($('newConvoName')?.value || '').trim();
      if (!name) {
        notify('Enter a contact name.', 'error');
        return;
      }
      setLoading(true);
      try {
        const created = await createThread({
          name,
          role: ($('newConvoRole')?.value || '').trim(),
          text: ($('newConvoMessage')?.value || '').trim()
        });
        $('newConvoModal')?.classList.remove('open');
        ['newConvoName', 'newConvoRole', 'newConvoMessage'].forEach((id) => { if ($(id)) $(id).value = ''; });
        await refresh();
        if (created?.thread_id) await openThread(created.thread_id);
        notify('Conversation created.', 'success');
      } catch (error) {
        notify(error.message, 'error');
      } finally {
        setLoading(false);
      }
    });
  }

  async function boot() {
    bindUi();
    setLoading(true);
    try {
      await refresh();
    } catch (error) {
      notify(error.message || 'Unable to load messages.', 'error');
      threads = [];
      renderContacts();
      renderChat();
    } finally {
      setLoading(false);
    }
  }

  document.addEventListener('DOMContentLoaded', boot);
})();
