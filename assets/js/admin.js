/**
 * Moderation panel interactions.
 * - Approve/reject/delete via fetch: the card leaves with an animation,
 *   tab counts update in place and a toast confirms the result.
 * - Delete asks for a second click before executing.
 * - Without JS, the forms fall back to a regular POST + redirect.
 */

const toastHost = document.getElementById('toasts');

const ACTION_MESSAGES = {
  approve: 'Recurso aprobado',
  reject: 'Recurso rechazado',
  delete: 'Recurso eliminado',
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

  // Destructive action: require a second click within a few seconds.
  if (action === 'delete' && button.dataset.state !== 'confirming') {
    button.dataset.state = 'confirming';
    button.dataset.label = button.textContent;
    button.textContent = '¿Confirmar?';
    setTimeout(() => {
      if (button.dataset.state === 'confirming') {
        button.dataset.state = '';
        button.textContent = button.dataset.label;
      }
    }, CONFIRM_RESET_MS);
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
      empty.textContent = 'No hay recursos en esta categoría.';
      list.appendChild(empty);
    }
  }, 250);
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
