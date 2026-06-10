/**
 * Submission form: posts the new resource as JSON and shows the
 * email-validation message on success.
 */

const form = document.getElementById('submit-form');
const successCard = document.getElementById('success');
const submitBtn = document.getElementById('submit-btn');

// Pre-fill the year when arriving from a timeline section
// (/cargar/1945, with ?year=1945 as fallback).
const pathYear = location.pathname.match(/^\/cargar\/(\d{4})\/?$/)?.[1];
const yearParam = Number(pathYear ?? new URLSearchParams(location.search).get('year'));
if (Number.isInteger(yearParam) && yearParam >= 1800 && yearParam <= new Date().getFullYear()) {
  form.elements.year.value = yearParam;
}

// If this session already verified an email, pre-fill and lock the field.
// The server decides whether validation is skipped; this is just UX.
const emailInput = form.elements.email;
const unlockBtn = document.getElementById('email-unlock');
const emailHint = document.getElementById('email-hint');

// Character counter for the excerpt. The static maxlength is a fallback;
// the server-configured limit arrives with the session response.
const excerptInput = form.elements.excerpt;
const excerptCounter = document.getElementById('excerpt-counter');

function updateExcerptCounter() {
  const length = excerptInput.value.length.toLocaleString('es-AR');
  const max = excerptInput.maxLength.toLocaleString('es-AR');
  excerptCounter.textContent = `${length} / ${max} caracteres`;
}
excerptInput.addEventListener('input', updateExcerptCounter);
updateExcerptCounter();

fetch('/api/session.php')
  .then((res) => res.json())
  .then(({ verified_email: verifiedEmail, excerpt_max_length: excerptMax }) => {
    if (Number.isInteger(excerptMax) && excerptMax > 0) {
      excerptInput.maxLength = excerptMax;
      updateExcerptCounter();
    }
    if (!verifiedEmail) return;
    emailInput.value = verifiedEmail;
    emailInput.readOnly = true;
    unlockBtn.hidden = false;
    emailHint.textContent = 'Email ya validado en esta sesión: la carga pasa directo a moderación.';
  })
  .catch(() => {});

unlockBtn.addEventListener('click', () => {
  emailInput.readOnly = false;
  emailInput.value = '';
  emailInput.focus();
  unlockBtn.hidden = true;
  emailHint.textContent = 'Solo lo usamos para validar la carga. No se publica.';
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearErrors();

  if (!form.reportValidity()) return;

  submitBtn.disabled = true;
  submitBtn.textContent = 'Enviando…';

  const payload = Object.fromEntries(new FormData(form).entries());

  try {
    const res = await fetch('/api/submit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();

    if (res.ok && data.ok) {
      if (data.already_verified) {
        // No validation email this time: straight to moderation, or
        // already published when the submitter is a moderator.
        document.getElementById('success-mail-flow').hidden = true;
        document.getElementById(
          data.published ? 'success-published' : 'success-direct',
        ).hidden = false;
      } else {
        document.getElementById('success-email').textContent = payload.email;

        // Local development fallback when mail() is unavailable.
        if (data.verify_url) {
          const devLink = document.getElementById('dev-link');
          devLink.hidden = false;
          devLink.innerHTML =
            `<small>Modo desarrollo — el email no pudo enviarse. ` +
            `<a href="${data.verify_url}">Validar manualmente</a></small>`;
        }
      }

      form.hidden = true;
      successCard.hidden = false;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } else if (data.errors) {
      showErrors(data.errors);
    } else {
      showErrors({ general: 'Ocurrió un error inesperado. Intente nuevamente.' });
    }
  } catch (err) {
    console.error('[submit]', err);
    showErrors({ general: 'No se pudo conectar con el servidor. Intente nuevamente.' });
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = 'Enviar';
  }
});

function showErrors(errors) {
  for (const [field, message] of Object.entries(errors)) {
    const el = form.querySelector(`.field-error[data-for="${field}"]`)
      || form.querySelector('.field-error[data-for="general"]');
    if (el) el.textContent = message;
  }
}

function clearErrors() {
  form.querySelectorAll('.field-error').forEach((el) => (el.textContent = ''));
}
