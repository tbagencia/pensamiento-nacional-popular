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
const YEAR_PATH = /^\/linea\/(\d{4})\/?$/;
const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
// Must match the structural breakpoint in styles.css where the year nav
// becomes a vertical rail in the left gutter.
const desktopRail = window.matchMedia('(min-width: 1150px)');

init();

async function init() {
  try {
    const res = await fetch('/api/resources.php');
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const { resources } = await res.json();

    renderTimeline(resources);
    buildYearNav(resources);
    syncNavHeight();
    setupScrollSpy();
    setupCardReveal();
    setupReadMore();
    setupToTop();
    setupHistory();
    statusEl.hidden = true;
    goToYearFromUrl(false);
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
    timelineEl.innerHTML = '<li class="empty">Todavía no hay documentos publicados.</li>';
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

    const addLink = document.createElement('a');
    addLink.className = 'add-to-year';
    addLink.href = `/cargar/${year}`;
    addLink.textContent = `+ Sumar un documento de ${year}`;
    li.appendChild(addLink);

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

    const year = chip.dataset.year;
    history.pushState(null, '', `/linea/${year}`);
    scrollToYear(year, prefersReducedMotion.matches ? 'auto' : 'smooth');
  });
}

function scrollToYear(year, behavior) {
  setActiveChip(year);
  if (year === 'all') {
    window.scrollTo({ top: 0, behavior });
    return;
  }
  document.getElementById(`year-${year}`)?.scrollIntoView({ behavior, block: 'start' });
}

/* ---------- Friendly URLs (/linea/1945) ---------- */

function goToYearFromUrl(smooth) {
  const year = location.pathname.match(YEAR_PATH)?.[1];
  if (year && document.getElementById(`year-${year}`)) {
    scrollToYear(year, smooth && !prefersReducedMotion.matches ? 'smooth' : 'auto');
  } else if (!year) {
    scrollToYear('all', smooth && !prefersReducedMotion.matches ? 'smooth' : 'auto');
  }
}

function setupHistory() {
  window.addEventListener('popstate', () => goToYearFromUrl(true));
}

/** Mirrors the year being viewed into the address bar (shareable URLs). */
function syncUrl(year) {
  const target = year === 'all' ? '/' : `/linea/${year}`;
  if (location.pathname !== target) {
    history.replaceState(null, '', target);
  }
}

/* ---------- Navigation interactivity ---------- */

// macOS-dock-like magnification: the active pill is largest and
// neighbours shrink with distance.
const CHIP_SCALES = [1.15, 1.08, 1.03, 1];

function setActiveChip(year) {
  const chips = [...yearNav.querySelectorAll('.year-chip')];
  const activeIndex = chips.findIndex((c) => c.dataset.year === String(year));

  chips.forEach((chip, i) => {
    const distance = activeIndex === -1 ? Infinity : Math.abs(i - activeIndex);
    chip.style.scale = prefersReducedMotion.matches
      ? ''
      : String(CHIP_SCALES[Math.min(distance, CHIP_SCALES.length - 1)]);

    if (i === activeIndex) {
      chip.setAttribute('aria-current', 'location');
      // Keep the active chip centered in the scrollable nav
      // (vertical rail on desktop, horizontal bar on mobile).
      const behavior = prefersReducedMotion.matches ? 'auto' : 'smooth';
      if (desktopRail.matches) {
        yearNav.scrollTo({
          top: chip.offsetTop - yearNav.clientHeight / 2 + chip.offsetHeight / 2,
          behavior,
        });
      } else {
        yearNav.scrollTo({
          left: chip.offsetLeft - yearNav.clientWidth / 2 + chip.offsetWidth / 2,
          behavior,
        });
      }
    } else {
      chip.removeAttribute('aria-current');
    }
  });
}

function setupScrollSpy() {
  const sections = timelineEl.querySelectorAll('.timeline-year');
  if (!('IntersectionObserver' in window) || sections.length === 0) return;

  const spy = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          setActiveChip(entry.target.dataset.year);
          syncUrl(entry.target.dataset.year);
        }
      }
      // Above the first year section: highlight "Todos".
      if (window.scrollY < timelineEl.offsetTop - yearNav.offsetHeight) {
        setActiveChip('all');
        syncUrl('all');
      }
    },
    { rootMargin: '-25% 0px -65% 0px' }
  );
  sections.forEach((s) => spy.observe(s));
}

function syncNavHeight() {
  const apply = () => {
    // On desktop the nav is a side rail and takes no top space.
    const navHeight = desktopRail.matches ? 0 : yearNav.offsetHeight;
    document.documentElement.style.setProperty('--nav-h', `${navHeight}px`);

    // Year sections stop below the sticky nav when scrolled into view.
    for (const section of timelineEl.querySelectorAll('.timeline-year')) {
      section.style.scrollMarginTop = `${navHeight + 8}px`;
    }
  };
  apply();
  window.addEventListener('resize', apply);
  desktopRail.addEventListener('change', apply);
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
