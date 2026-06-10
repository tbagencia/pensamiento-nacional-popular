/**
 * Timeline page.
 * - Renders approved resources grouped by year.
 * - Year chips smooth-scroll to their section.
 * - Scrollspy keeps the active chip in sync (and in view) while scrolling.
 * - Cards reveal on scroll; long excerpts collapse with a "read more" toggle.
 */

const timelineEl = document.getElementById('timeline');
const statusEl = document.getElementById('status');
const yearNav = document.querySelector('.year-nav');
const toTopBtn = document.getElementById('to-top');

const EXCERPT_COLLAPSE_LENGTH = 320;
const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

init();

async function init() {
  try {
    const res = await fetch('api/resources.php');
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const { resources } = await res.json();

    renderTimeline(resources);
    buildYearNav(resources);
    syncNavHeight();
    setupScrollSpy();
    setupCardReveal();
    setupReadMore();
    setupToTop();
    statusEl.hidden = true;
  } catch (err) {
    statusEl.textContent = 'No se pudo cargar la línea de tiempo. Intente nuevamente más tarde.';
    console.error('[timeline]', err);
  }
}

/* ---------- Rendering ---------- */

function renderTimeline(resources) {
  const byYear = new Map();
  for (const r of resources) {
    if (!byYear.has(r.year)) byYear.set(r.year, []);
    byYear.get(r.year).push(r);
  }

  if (byYear.size === 0) {
    timelineEl.innerHTML = '<li class="empty">Todavía no hay recursos publicados.</li>';
    return;
  }

  for (const [year, items] of byYear) {
    const li = document.createElement('li');
    li.className = 'timeline-year';
    li.id = `year-${year}`;
    li.dataset.year = String(year);
    li.innerHTML = `<h2>${year}</h2>`;

    for (const item of items) {
      const isLong = (item.excerpt ?? '').length > EXCERPT_COLLAPSE_LENGTH;
      const card = document.createElement('article');
      card.className = 'card';
      card.dataset.state = 'hidden';
      card.innerHTML = `
        <span class="badge badge-type">${escapeHtml(item.type)}</span>
        <h3>${escapeHtml(item.title)}</h3>
        <p class="author">${escapeHtml(item.author)}</p>
        <p class="excerpt" ${isLong ? 'data-state="collapsed"' : ''}>${escapeHtml(item.excerpt)}</p>
        ${isLong ? '<button type="button" class="read-more" aria-expanded="false">Leer más</button>' : ''}
        ${item.source_url
          ? `<p><a class="source-link" href="${escapeHtml(item.source_url)}" target="_blank" rel="noopener noreferrer">Ver fuente &rarr;</a></p>`
          : ''}`;
      li.appendChild(card);
    }
    timelineEl.appendChild(li);
  }
}

function buildYearNav(resources) {
  const years = [...new Set(resources.map((r) => r.year))].sort((a, b) => a - b);

  for (const year of years) {
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'year-chip';
    chip.dataset.year = String(year);
    chip.textContent = year;
    yearNav.appendChild(chip);
  }

  yearNav.addEventListener('click', (e) => {
    const chip = e.target.closest('.year-chip');
    if (!chip) return;

    setActiveChip(chip.dataset.year);
    const behavior = prefersReducedMotion.matches ? 'auto' : 'smooth';

    if (chip.dataset.year === 'all') {
      window.scrollTo({ top: 0, behavior });
      return;
    }
    document.getElementById(`year-${chip.dataset.year}`)
      ?.scrollIntoView({ behavior, block: 'start' });
  });
}

/* ---------- Navigation interactivity ---------- */

function setActiveChip(year) {
  for (const chip of yearNav.querySelectorAll('.year-chip')) {
    if (chip.dataset.year === String(year)) {
      chip.setAttribute('aria-current', 'location');
      // Keep the active chip centered in the scrollable nav bar.
      yearNav.scrollTo({
        left: chip.offsetLeft - yearNav.clientWidth / 2 + chip.offsetWidth / 2,
        behavior: prefersReducedMotion.matches ? 'auto' : 'smooth',
      });
    } else {
      chip.removeAttribute('aria-current');
    }
  }
}

function setupScrollSpy() {
  const sections = timelineEl.querySelectorAll('.timeline-year');
  if (!('IntersectionObserver' in window) || sections.length === 0) return;

  const spy = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          setActiveChip(entry.target.dataset.year);
        }
      }
      // Above the first year section: highlight "Todos".
      if (window.scrollY < timelineEl.offsetTop - yearNav.offsetHeight) {
        setActiveChip('all');
      }
    },
    { rootMargin: '-25% 0px -65% 0px' }
  );
  sections.forEach((s) => spy.observe(s));
}

function syncNavHeight() {
  const apply = () =>
    document.documentElement.style.setProperty('--nav-h', `${yearNav.offsetHeight}px`);
  apply();
  window.addEventListener('resize', apply);

  // Year sections stop below the sticky nav when scrolled into view.
  for (const section of timelineEl.querySelectorAll('.timeline-year')) {
    section.style.scrollMarginTop = `${yearNav.offsetHeight + 8}px`;
  }
}

/* ---------- Card reveal ---------- */

function setupCardReveal() {
  const cards = timelineEl.querySelectorAll('.card');

  if (!('IntersectionObserver' in window) || prefersReducedMotion.matches) {
    cards.forEach((c) => (c.dataset.state = 'visible'));
    return;
  }

  const reveal = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          entry.target.dataset.state = 'visible';
          reveal.unobserve(entry.target);
        }
      }
    },
    { rootMargin: '0px 0px -10% 0px', threshold: 0.05 }
  );
  cards.forEach((c) => reveal.observe(c));
}

/* ---------- Read more toggle ---------- */

function setupReadMore() {
  timelineEl.addEventListener('click', (e) => {
    const btn = e.target.closest('.read-more');
    if (!btn) return;

    const excerpt = btn.parentElement.querySelector('.excerpt');
    const collapsed = excerpt.dataset.state === 'collapsed';
    excerpt.dataset.state = collapsed ? 'expanded' : 'collapsed';
    btn.setAttribute('aria-expanded', String(collapsed));
    btn.textContent = collapsed ? 'Leer menos' : 'Leer más';
  });
}

/* ---------- Back to top ---------- */

function setupToTop() {
  if (!toTopBtn) return;

  window.addEventListener('scroll', () => {
    toTopBtn.dataset.state = window.scrollY > window.innerHeight ? 'visible' : 'hidden';
  }, { passive: true });

  toTopBtn.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: prefersReducedMotion.matches ? 'auto' : 'smooth' });
  });
}

/* ---------- Helpers ---------- */

function escapeHtml(value) {
  const div = document.createElement('div');
  div.textContent = value ?? '';
  return div.innerHTML;
}
