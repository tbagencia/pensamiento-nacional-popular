/**
 * Moderation panel interactions.
 * - Approve/reject/delete via fetch: the card leaves with an animation,
 *   tab counts update in place and a toast confirms the result.
 * - Delete asks for a second click before executing.
 * - Reject asks for a second click too, and offers an optional reason
 *   that travels in the email the submitter receives.
 * - Edit form saves via fetch and refreshes the card in place.
 * - Search box live-filters the cards of the current tab.
 * - Without JS, the forms fall back to a regular POST + redirect.
 */

const toastHost = document.getElementById('toasts');

const ACTION_MESSAGES = {
  approve: 'Documento aprobado',
  reject: 'Documento rechazado',
  delete: 'Documento eliminado',
};

const CONFIRM_RESET_MS = 3000;
const TOAST_VISIBLE_MS = 3200;

for (const form of document.querySelectorAll('.admin-actions form')) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    handleAction(form);
  });
}

async function handleAction(form) {
  const button = form.querySelector('button');
  const action = form.querySelector('input[name="action"]').value;

  // Destructive or outbound action: require a second click. Reject also
  // offers an optional reason that is emailed to the submitter.
  if (['delete', 'reject'].includes(action) && button.dataset.state !== 'confirming') {
    button.dataset.state = 'confirming';
    button.dataset.label = button.textContent;
    button.textContent = '¿Confirmar?';
    if (action === 'reject') {
      const reason = document.createElement('textarea');
      reason.name = 'reason';
      reason.className = 'reject-reason';
      reason.rows = 3;
      reason.placeholder = 'Motivo (opcional): se envía por email a quien cargó el documento.';
      form.appendChild(reason);
      reason.focus();
    }
    if (action === 'delete') {
      setTimeout(() => {
        if (button.dataset.state === 'confirming') {
          button.dataset.state = '';
          button.textContent = button.dataset.label;
        }
      }, CONFIRM_RESET_MS);
    }
    return;
  }

  button.disabled = true;
  try {
    const res = await fetch(location.href, {
      method: 'POST',
      headers: { 'X-Requested-With': 'fetch' },
      body: new FormData(form),
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || `HTTP ${res.status}`);

    updateTabCounts(data.counts);
    removeCard(form.closest('.admin-card'));
    showToast(ACTION_MESSAGES[action] ?? 'Listo');
  } catch (err) {
    console.error('[admin]', err);
    button.disabled = false;
    showToast('No se pudo completar la acción. Probá de nuevo.', true);
  }
}

function updateTabCounts(counts) {
  for (const link of document.querySelectorAll('.admin-tabs a')) {
    const tab = new URL(link.href).searchParams.get('tab');
    const badge = link.querySelector('.count');
    if (badge && counts[tab] !== undefined) {
      badge.textContent = counts[tab];
    }
  }
}

function removeCard(card) {
  if (!card) return;
  card.dataset.state = 'leaving';
  setTimeout(() => {
    const list = card.parentElement;
    card.remove();
    if (!list.querySelector('.admin-card')) {
      const empty = document.createElement('p');
      empty.className = 'empty';
      empty.textContent = 'No hay documentos en esta categoría.';
      list.appendChild(empty);
    }
  }, 250);
}

for (const form of document.querySelectorAll('.admin-edit-form')) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    handleEdit(form);
  });
}

async function handleEdit(form) {
  const button = form.querySelector('button[type="submit"]');
  const showErrors = (errors = {}) => {
    for (const el of form.querySelectorAll('.field-error')) {
      el.textContent = errors[el.dataset.for] ?? '';
    }
  };

  showErrors();
  button.disabled = true;
  try {
    const res = await fetch(location.href, {
      method: 'POST',
      headers: { 'X-Requested-With': 'fetch' },
      body: new FormData(form),
    });
    const data = await res.json();
    if (res.status === 422 && data.errors) {
      showErrors(data.errors);
      return;
    }
    if (!res.ok || !data.ok) throw new Error(data.error || `HTTP ${res.status}`);

    refreshCard(form.closest('.admin-card'), data.resource);
    form.closest('.admin-edit').open = false;
    showToast('Cambios guardados');
  } catch (err) {
    console.error('[admin]', err);
    showErrors({ general: 'No se pudo guardar. Probá de nuevo.' });
  } finally {
    button.disabled = false;
  }
}

function refreshCard(card, resource) {
  if (!card || !resource) return;
  card.querySelector('h2').textContent = resource.title;
  card.querySelector('.author').textContent = resource.author;
  card.querySelector('.excerpt').textContent = resource.excerpt;
  card.querySelector('.badge-year').textContent = resource.year;
  card.querySelector('.badge-type').textContent = resource.type;

  const source = card.querySelector('.admin-source');
  if (resource.source_url) {
    if (source) {
      source.querySelector('a').href = resource.source_url;
    } else {
      const p = document.createElement('p');
      p.className = 'admin-source';
      const a = document.createElement('a');
      a.href = resource.source_url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = 'Fuente';
      p.appendChild(a);
      card.querySelector('.meta').before(p);
    }
  } else {
    source?.remove();
  }
}

// Live search over the current tab. Matches title, author, year, type,
// excerpt and submitter line, ignoring case and accents. Only visible
// fields are matched: the whole card text would include every type name
// from the edit form's <select>, making e.g. "audio" match all cards.
const SEARCH_FIELDS = ['h2', '.author', '.excerpt', '.badge-year', '.badge-type', '.meta'];
const searchInput = document.getElementById('admin-search');

searchInput?.addEventListener('input', () => filterCards(searchInput.value));

function normalizeText(text) {
  return text.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

function filterCards(query) {
  const list = document.querySelector('.admin-list');
  const cards = list.querySelectorAll('.admin-card');
  const q = normalizeText(query.trim());
  let visible = 0;

  for (const card of cards) {
    const haystack = SEARCH_FIELDS
      .map((sel) => card.querySelector(sel)?.textContent ?? '')
      .join(' ');
    const match = q === '' || normalizeText(haystack).includes(q);
    card.hidden = !match;
    if (match) visible += 1;
  }

  let empty = list.querySelector('[data-search-empty]');
  if (visible === 0 && cards.length > 0) {
    if (!empty) {
      empty = document.createElement('p');
      empty.className = 'empty';
      empty.dataset.searchEmpty = '';
      empty.textContent = 'Ningún documento coincide con la búsqueda.';
      list.prepend(empty);
    }
  } else {
    empty?.remove();
  }
}

function showToast(message, isError = false) {
  const toast = document.createElement('div');
  toast.className = 'toast' + (isError ? ' toast-error' : '');
  toast.textContent = message;
  toastHost.appendChild(toast);

  requestAnimationFrame(() => (toast.dataset.state = 'visible'));
  setTimeout(() => {
    toast.dataset.state = 'hidden';
    setTimeout(() => toast.remove(), 300);
  }, TOAST_VISIBLE_MS);
}
