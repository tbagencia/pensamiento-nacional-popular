/**
 * Timeline page.
 * - Renders approved resources grouped by year.
 * - Year chips smooth-scroll to their section.
 * - Scrollspy keeps the active chip in sync (and in view) while scrolling.
 * - Cards reveal on scroll; long excerpts collapse with a "read more" toggle.
 */

const timelineEl = document.getElementById("timeline");
const statusEl = document.getElementById("status");
const yearNav = document.querySelector(".year-nav");
const typeNav = document.getElementById("type-nav");
const toTopBtn = document.getElementById("to-top");
const searchBar = document.querySelector(".search-bar");
const searchInput = document.getElementById("search-input");
const searchClear = document.getElementById("search-clear");
const searchCount = document.getElementById("search-count");

const TYPE_LABELS = {
	discurso: "Discursos",
	carta: "Cartas",
	manifiesto: "Manifiestos",
	libro: "Libros",
	ensayo: "Ensayos",
	poema: "Poemas",
	entrevista: "Entrevistas",
	texto: "Otros textos",
};

let allResources = [];
let activeType = null;
let searchWords = [];
let spyLine = 0;
let spyTicking = false;
let spyBound = false;
let jumping = false;
let jumpTimer = null;
let revealObserver = null;

const EXCERPT_COLLAPSE_LENGTH = 320;
const YEAR_PATH = /^\/linea\/(\d{4})\/?$/;
const TYPE_PATH = /^\/tipo\/([a-z]+)\/?$/;
const prefersReducedMotion = window.matchMedia(
	"(prefers-reduced-motion: reduce)",
);
// Must match the structural breakpoint in styles.css where the year nav
// becomes a vertical rail in the left gutter.
const desktopRail = window.matchMedia("(min-width: 1150px)");

init();

async function init() {
	try {
		const res = await fetch("/api/resources.php");
		if (!res.ok) throw new Error(`HTTP ${res.status}`);
		({ resources: allResources } = await res.json());

		activeType = typeFromUrl();
		buildTypeNav();
		setupSearch();
		setupYearNavClicks();
		setupNavMetrics();
		setupReadMore();
		initShare(timelineEl, (card) =>
			allResources.find((r) => `doc-${r.id}` === card.id),
		);
		setupToTop();
		setupHistory();
		renderAll();
		statusEl.hidden = true;
		if (!goToDocFromUrl()) goToYearFromUrl(false);
	} catch (err) {
		statusEl.textContent =
			"No se pudo cargar la línea de tiempo. Intente nuevamente más tarde.";
		console.error("[timeline]", err);
	}
}

function currentResources() {
	let list = activeType
		? allResources.filter((r) => r.type === activeType)
		: allResources;

	if (searchWords.length > 0) {
		list = list.filter((r) => {
			const haystack = fold(`${r.title} ${r.author} ${r.excerpt} ${r.year}`);
			return searchWords.every((word) => haystack.includes(word));
		});
	}
	return list;
}

/** Re-renders timeline and year chips; safe to call on every filter change. */
function renderAll() {
	renderTimeline(currentResources());
	renderYearChips(currentResources());
	applyNavMetrics();
	setupScrollSpy();
	setupCardReveal();
}

/* ---------- Rendering ---------- */

function renderTimeline(resources) {
	timelineEl.innerHTML = "";

	const byYear = new Map();
	for (const r of resources) {
		if (!byYear.has(r.year)) byYear.set(r.year, []);
		byYear.get(r.year).push(r);
	}

	if (byYear.size === 0) {
		timelineEl.innerHTML = renderEmptyState();
		return;
	}

	for (const [year, items] of byYear) {
		const li = document.createElement("li");
		li.className = "timeline-year";
		li.id = `year-${year}`;
		li.dataset.year = String(year);
		li.innerHTML = `<h2>${year}</h2>`;

		for (const item of items) {
			// While searching, excerpts render expanded: a match hidden
			// behind the "read more" fold would look like a false positive.
			const isLong =
				searchWords.length === 0 &&
				(item.excerpt ?? "").length > EXCERPT_COLLAPSE_LENGTH;
			const card = document.createElement("article");
			card.className = "card";
			card.id = `doc-${item.id}`;
			card.dataset.state = "hidden";
			card.innerHTML = `
        <button type="button" class="share-btn" aria-label="Compartir este documento" aria-expanded="false" data-tip="Compartir">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="8.59" y1="10.49" x2="15.42" y2="6.51"/></svg>
        </button>
        ${
					item.source_url
						? `<a class="source-btn" href="${escapeHtml(item.source_url)}" target="_blank" rel="noopener noreferrer" aria-label="Ver la fuente externa" data-tip="Ver fuente">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        </a>`
						: ""
				}
        <span class="badge badge-type">${escapeHtml(item.type)}</span>
        <h3>${markMatches(item.title)}</h3>
        <p class="author">${markMatches(item.author)}</p>
        <p class="excerpt" ${isLong ? 'data-state="collapsed"' : ""}>${markMatches(item.excerpt)}</p>
        ${isLong ? '<button type="button" class="read-more" aria-expanded="false">Leer más</button>' : ""}`;
			li.appendChild(card);
		}

		const addLink = document.createElement("a");
		addLink.className = "add-to-year";
		addLink.href = `/cargar/${year}`;
		addLink.textContent = `+ Aportar un documento de ${year}`;
		li.appendChild(addLink);

		timelineEl.appendChild(li);
	}
}

/** Friendly no-results view with one-tap ways out of the dead end. */
function renderEmptyState() {
	const hint = searchWords.length
		? activeType
			? "Probá con otras palabras o quitá el filtro de tipo."
			: "Probá con menos palabras o con un término distinto."
		: "Todavía no hay documentos de este tipo en el archivo.";
	return `
		<li class="empty-state">
			<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
				<circle cx="11" cy="11" r="7"/>
				<line x1="20.5" y1="20.5" x2="16" y2="16"/>
				<circle cx="11" cy="11" r="2.2" fill="var(--gold)" stroke="none"/>
			</svg>
			<p class="empty-state-title">${searchWords.length ? "Sin resultados" : "Nada por acá"}</p>
			<p class="empty-state-hint">${hint}</p>
			<div class="empty-state-actions">
				${searchWords.length ? '<button type="button" class="btn btn-primary" data-action="clear-search">Limpiar búsqueda</button>' : ""}
				${activeType ? '<button type="button" class="btn btn-ghost" data-action="clear-type">Ver todos los tipos</button>' : ""}
			</div>
		</li>`;
}

function renderYearChips(resources) {
	yearNav.innerHTML = "";
	const years = [...new Set(resources.map((r) => r.year))].sort(
		(a, b) => a - b,
	);

	for (const year of years) {
		const chip = document.createElement("button");
		chip.type = "button";
		chip.className = "year-chip";
		chip.dataset.year = String(year);
		chip.textContent = year;
		yearNav.appendChild(chip);
	}
}

function setupYearNavClicks() {
	yearNav.addEventListener("click", (e) => {
		const chip = e.target.closest(".year-chip");
		if (!chip) return;

		const year = chip.dataset.year;
		history.pushState(
			null,
			"",
			activeType ? `/tipo/${activeType}` : `/linea/${year}`,
		);
		scrollToYear(year, prefersReducedMotion.matches ? "auto" : "smooth");
	});
}

/* ---------- Search ---------- */

const SEARCH_DEBOUNCE_MS = 180;

function setupSearch() {
	if (!searchInput) return;
	let timer;

	searchInput.addEventListener("input", () => {
		clearTimeout(timer);
		timer = setTimeout(applySearch, SEARCH_DEBOUNCE_MS);
	});

	// The collapsed/open exception only has visual effect on mobile;
	// the desktop rail keeps the input expanded regardless of state.
	searchInput.addEventListener("focus", () => {
		searchBar.dataset.state = "open";
		refreshSearchClear();
	});

	searchInput.addEventListener("blur", () => {
		if (!searchInput.value) {
			searchBar.dataset.state = "collapsed";
			refreshSearchClear();
		}
	});

	searchInput.addEventListener("keydown", (e) => {
		if (e.key !== "Escape") return;
		if (searchInput.value) {
			searchInput.value = "";
			applySearch();
		} else {
			searchInput.blur();
		}
	});

	searchClear.addEventListener("click", clearSearch);

	// Escape hatches rendered inside the empty state.
	timelineEl.addEventListener("click", (e) => {
		if (e.target.closest('[data-action="clear-search"]')) clearSearch();
		if (e.target.closest('[data-action="clear-type"]')) clearTypeFilter();
	});

	// The desktop rail is too narrow for the full hint.
	const syncPlaceholder = () => {
		searchInput.placeholder = desktopRail.matches
			? "Autor o término…"
			: "Ingresá un autor o término…";
	};
	syncPlaceholder();
	desktopRail.addEventListener("change", syncPlaceholder);

	refreshSearchContext();
	window.addEventListener("scroll", refreshSearchContext, { passive: true });
}

function clearSearch() {
	searchInput.value = "";
	applySearch();
	// On desktop the input lives in the rail and keeps focus for a
	// retype; on mobile clearing also closes the floating search.
	if (desktopRail.matches) {
		searchInput.focus();
	} else {
		searchBar.dataset.state = "collapsed";
		refreshSearchClear();
	}
}

function clearTypeFilter() {
	if (!activeType) return;
	activeType = null;
	refreshTypePills();
	renderAll();
	history.replaceState(null, "", "/");
}

/** Over the dark hero header the collapsed circle dresses like the
 *  "Acerca de" badge; over page content it needs a solid surface. */
function refreshSearchContext() {
	const overHero =
		document.querySelector(".site-header").getBoundingClientRect().bottom >
		searchBar.getBoundingClientRect().bottom;
	searchBar.dataset.context = overHero ? "hero" : "page";
}

/** The X clears the text, and on mobile it doubles as the close button,
 *  so it shows whenever the floating search is open. */
function refreshSearchClear() {
	const mobileOpen =
		!desktopRail.matches && searchBar.dataset.state === "open";
	searchClear.hidden = !searchInput.value && !mobileOpen;
}

function applySearch() {
	const hadWords = searchWords.length > 0;
	searchWords = fold(searchInput.value).split(/\s+/).filter(Boolean);
	refreshSearchClear();
	renderAll();

	// A fresh search jumps to the top so the results are in view:
	// the floating input means the user may be anywhere in the page.
	if (!hadWords && searchWords.length > 0) {
		window.scrollTo({ top: 0, behavior: "auto" });
	}

	if (searchWords.length === 0) {
		searchCount.hidden = true;
		return;
	}
	const total = currentResources().length;
	searchCount.textContent =
		total === 1 ? "1 documento encontrado" : `${total} documentos encontrados`;
	searchCount.hidden = false;
}

/* ---------- Type filter ---------- */

function buildTypeNav() {
	const present = new Set(allResources.map((r) => r.type));
	const types = Object.keys(TYPE_LABELS).filter((t) => present.has(t));
	if (types.length < 2) return;

	// "Todos" represents the no-filter state and is selected by default.
	for (const type of ["all", ...types]) {
		const pill = document.createElement("button");
		pill.type = "button";
		pill.className = "type-pill";
		pill.dataset.type = type;
		pill.textContent = type === "all" ? "Todos" : TYPE_LABELS[type];
		typeNav.appendChild(pill);
	}
	refreshTypePills();
	typeNav.hidden = false;

	typeNav.addEventListener("click", (e) => {
		const pill = e.target.closest(".type-pill");
		if (!pill) return;

		// Clicking the active filter (or "Todos") returns to the unfiltered view.
		const picked = pill.dataset.type === "all" ? null : pill.dataset.type;
		activeType = activeType === picked ? null : picked;
		refreshTypePills();
		renderAll();
		history.replaceState(null, "", activeType ? `/tipo/${activeType}` : "/");
		window.scrollTo({ top: 0, behavior: "auto" });
	});
}

function refreshTypePills() {
	for (const p of typeNav.querySelectorAll(".type-pill")) {
		p.setAttribute(
			"aria-pressed",
			String(p.dataset.type === (activeType ?? "all")),
		);
	}
}

/** Reads the /tipo/{type} filter from the URL, ignoring unknown values. */
function typeFromUrl() {
	const tipo = location.pathname.match(TYPE_PATH)?.[1];
	return tipo && Object.hasOwn(TYPE_LABELS, tipo) ? tipo : null;
}

function scrollToYear(year, behavior) {
	// Chrome keeps a single programmatic smooth scroll alive, so the bar
	// aligns INSTANTLY before the document starts gliding: the chip is
	// already in place during the ride and no bar animation survives to
	// kill the document scroll. The spy stays silent mid-flight.
	setActiveChip(year, false);
	const chip = yearNav.querySelector('.year-chip[aria-current="location"]');
	if (chip) alignBarToChip(chip, "instant");
	jumping = true;
	clearTimeout(jumpTimer);
	const land = () => {
		jumping = false;
		clearTimeout(jumpTimer);
		window.removeEventListener("scrollend", land);
	};
	window.addEventListener("scrollend", land);
	jumpTimer = setTimeout(land, 1200); // browsers without scrollend

	if (year === "all") {
		window.scrollTo({ top: 0, behavior });
		return;
	}
	// Manual target math instead of scrollIntoView: Chrome miscalculates
	// the destination under non-default zoom levels.
	const section = document.getElementById(`year-${year}`);
	if (!section) return;
	let top;
	if (desktopRail.matches) {
		const margin = parseFloat(section.style.scrollMarginTop) || 0;
		top = window.scrollY + section.getBoundingClientRect().top - margin;
	} else {
		// Land just past the spy line: the gold heading tucks under the
		// opaque bar while the first card stays whole below it.
		top = window.scrollY + section.getBoundingClientRect().top - spyLine + 10;
	}
	window.scrollTo({ top: Math.max(0, top), behavior });
}

/* ---------- Friendly URLs (/linea/1945) ---------- */

function goToYearFromUrl(smooth) {
	const year = location.pathname.match(YEAR_PATH)?.[1];
	if (year && document.getElementById(`year-${year}`)) {
		scrollToYear(
			year,
			smooth && !prefersReducedMotion.matches ? "smooth" : "auto",
		);
	} else if (!year) {
		scrollToYear(
			"all",
			smooth && !prefersReducedMotion.matches ? "smooth" : "auto",
		);
	}
}

function setupHistory() {
	window.addEventListener("popstate", () => {
		const tipo = typeFromUrl();
		if (tipo !== activeType) {
			activeType = tipo;
			refreshTypePills();
			renderAll();
		}
		goToYearFromUrl(true);
	});
}

/** Mirrors the view into the address bar: the active type filter wins,
 *  otherwise the year being scrolled (one concept per URL). */
function syncUrl(year) {
	const target = activeType
		? `/tipo/${activeType}`
		: year === "all"
			? "/"
			: `/linea/${year}`;
	if (location.pathname !== target) {
		history.replaceState(null, "", target);
	}
}

/* ---------- Navigation interactivity ---------- */

// macOS-dock-like magnification: the active pill is largest and
// neighbours shrink with distance.
const CHIP_SCALES = [1.15, 1.08, 1.03, 1];

/** Desktop rail: keep the active chip centered. Mobile bar: pin it as
    the first pill right after the floating search circle. */
function alignBarToChip(chip, behaviorOverride = null) {
	const behavior =
		behaviorOverride ?? (prefersReducedMotion.matches ? "auto" : "smooth");
	if (desktopRail.matches) {
		yearNav.scrollTo({
			top: chip.offsetTop - yearNav.clientHeight / 2 + chip.offsetHeight / 2,
			behavior,
		});
	} else {
		const padLeft = parseFloat(getComputedStyle(yearNav).paddingLeft) || 0;
		yearNav.scrollTo({ left: chip.offsetLeft - padLeft, behavior });
	}
}

function setActiveChip(year, scrollBar = true) {
	const chips = [...yearNav.querySelectorAll(".year-chip")];
	const activeIndex = chips.findIndex((c) => c.dataset.year === String(year));

	// Already the active chip: skip the redundant bar scroll, which would
	// cancel any document smooth scroll in flight.
	if (
		activeIndex !== -1 &&
		chips[activeIndex].getAttribute("aria-current") === "location"
	) {
		return;
	}

	chips.forEach((chip, i) => {
		// Distance magnification is a desktop-rail effect: on the mobile
		// bar the gold color alone marks the active chip.
		const distance = activeIndex === -1 ? Infinity : Math.abs(i - activeIndex);
		chip.style.scale =
			prefersReducedMotion.matches || !desktopRail.matches
				? ""
				: String(CHIP_SCALES[Math.min(distance, CHIP_SCALES.length - 1)]);

		if (i === activeIndex) {
			chip.setAttribute("aria-current", "location");
			if (scrollBar) alignBarToChip(chip);
		} else {
			chip.removeAttribute("aria-current");
		}
	});
}

function setupScrollSpy() {
	// Activation line: a section is current once its top crosses it.
	// Desktop keeps the upper-third behaviour. Mobile places it so the
	// gold chip lights up exactly when the section's gold heading hides
	// under the year bar — the two are never visible at once. Chip-click
	// landings (scrollToYear) target this same line.
	if (desktopRail.matches) {
		spyLine = window.innerHeight * 0.3;
	} else {
		// May be negative: the heading sits below the section top, so the
		// section must scroll past the viewport edge to fully hide it.
		const pill = timelineEl.querySelector(".timeline-year > h2");
		const pillBottom = pill ? pill.offsetTop + pill.offsetHeight : 0;
		spyLine = yearNav.offsetHeight - pillBottom + 4;
	}

	if (!spyBound) {
		spyBound = true;
		window.addEventListener(
			"scroll",
			() => {
				if (spyTicking) return;
				spyTicking = true;
				window.requestAnimationFrame(runSpy);
			},
			{ passive: true },
		);
	}
}

/** Deterministic scrollspy: the last section whose top crossed the line. */
function runSpy() {
	spyTicking = false;
	if (jumping) return;
	const sections = timelineEl.querySelectorAll(".timeline-year");
	if (sections.length === 0) return;

	let current = null;
	for (const section of sections) {
		if (section.getBoundingClientRect().top > spyLine) break;
		current = section;
	}
	for (const section of sections) {
		if (section !== current) delete section.dataset.current;
	}
	if (current) {
		current.dataset.current = "true";
		setActiveChip(current.dataset.year);
		syncUrl(current.dataset.year);
	} else {
		// Above the first year section: highlight "Todos".
		setActiveChip("all");
		syncUrl("all");
	}
}

function applyNavMetrics() {
	// On desktop the nav is a side rail and takes no top space.
	const navHeight = desktopRail.matches ? 0 : yearNav.offsetHeight;
	document.documentElement.style.setProperty("--nav-h", `${navHeight}px`);

	// Year sections stop below the sticky nav when scrolled into view.
	for (const section of timelineEl.querySelectorAll(".timeline-year")) {
		section.style.scrollMarginTop = `${navHeight + 8}px`;
	}

	// The spy activation line depends on the nav height: rebuild it.
	setupScrollSpy();
}

function setupNavMetrics() {
	window.addEventListener("resize", applyNavMetrics);
	desktopRail.addEventListener("change", applyNavMetrics);
}

/* ---------- Card reveal ---------- */

function setupCardReveal() {
	revealObserver?.disconnect();
	const cards = timelineEl.querySelectorAll(".card");

	if (!("IntersectionObserver" in window) || prefersReducedMotion.matches) {
		cards.forEach((c) => (c.dataset.state = "visible"));
		return;
	}

	const reveal = new IntersectionObserver(
		(entries) => {
			for (const entry of entries) {
				if (entry.isIntersecting) {
					entry.target.dataset.state = "visible";
					reveal.unobserve(entry.target);
				}
			}
		},
		{ rootMargin: "0px 0px -10% 0px", threshold: 0.05 },
	);
	// Cards already in the viewport show immediately: an above-the-fold
	// card waiting for a scroll reveal reads as a rendering bug.
	for (const c of cards) {
		if (c.getBoundingClientRect().top < window.innerHeight) {
			c.dataset.state = "visible";
		} else {
			reveal.observe(c);
		}
	}
	revealObserver = reveal;
}

/* ---------- Read more toggle ---------- */

function setupReadMore() {
	timelineEl.addEventListener("click", (e) => {
		const btn = e.target.closest(".read-more");
		if (!btn) return;

		const excerpt = btn.parentElement.querySelector(".excerpt");
		const collapsed = excerpt.dataset.state === "collapsed";
		const anchor = btn.getBoundingClientRect().top;
		excerpt.dataset.state = collapsed ? "expanded" : "collapsed";
		btn.setAttribute("aria-expanded", String(collapsed));
		btn.textContent = collapsed ? "Leer menos" : "Leer más";

		// Collapsing shrinks everything above the button, dragging the
		// viewport to another year: compensate so the button stays put.
		if (!collapsed) {
			window.scrollBy(0, btn.getBoundingClientRect().top - anchor);
		}
	});
}

/* ---------- Back to top ---------- */

function setupToTop() {
	if (!toTopBtn) return;

	window.addEventListener(
		"scroll",
		() => {
			toTopBtn.dataset.state =
				window.scrollY > window.innerHeight ? "visible" : "hidden";
		},
		{ passive: true },
	);

	toTopBtn.addEventListener("click", () => {
		window.scrollTo({
			top: 0,
			behavior: prefersReducedMotion.matches ? "auto" : "smooth",
		});
	});
}

/** Scrolls to and highlights a card linked as /linea/{year}#doc-{id}. */
function goToDocFromUrl() {
	const id = location.hash.match(/^#doc-(\d+)$/)?.[1];
	if (!id) return false;
	const card = document.getElementById(`doc-${id}`);
	if (!card) return false;

	card.dataset.state = "visible";
	card.scrollIntoView({
		behavior: prefersReducedMotion.matches ? "auto" : "smooth",
		block: "center",
	});
	card.dataset.highlight = "true";
	setTimeout(() => delete card.dataset.highlight, 2600);
	return true;
}

/* ---------- Helpers ---------- */

function escapeHtml(value) {
	const div = document.createElement("div");
	div.textContent = value ?? "";
	return div.innerHTML;
}

/** Lowercases and strips diacritics so "peron" matches "Perón". */
function fold(value) {
	return (value ?? "")
		.toLowerCase()
		.normalize("NFD")
		.replace(/[\u0300-\u036f]/g, "");
}

/** Folds char by char, keeping a map from folded index back to the
 *  original index (one source char can fold into several, e.g. "ñ"). */
function buildFolded(text) {
	let folded = "";
	const map = [];
	for (let i = 0; i < text.length; i++) {
		for (const c of fold(text[i])) {
			folded += c;
			map.push(i);
		}
	}
	return { folded, map };
}

/** Escapes the text and wraps every search-word occurrence in <mark>,
 *  matching accent-insensitively against the original string. */
function markMatches(text) {
	const source = text ?? "";
	if (searchWords.length === 0) return escapeHtml(source);

	const { folded, map } = buildFolded(source);
	const ranges = [];
	for (const word of searchWords) {
		let from = 0;
		let idx = folded.indexOf(word, from);
		while (idx !== -1) {
			const start = map[idx];
			const end = map[idx + word.length - 1] + 1;
			ranges.push([start, end]);
			from = idx + word.length;
			idx = folded.indexOf(word, from);
		}
	}
	if (ranges.length === 0) return escapeHtml(source);

	ranges.sort((a, b) => a[0] - b[0]);
	const merged = [ranges[0]];
	for (const [start, end] of ranges.slice(1)) {
		const last = merged[merged.length - 1];
		if (start <= last[1]) last[1] = Math.max(last[1], end);
		else merged.push([start, end]);
	}

	let html = "";
	let cursor = 0;
	for (const [start, end] of merged) {
		html += `${escapeHtml(source.slice(cursor, start))}<mark>${escapeHtml(source.slice(start, end))}</mark>`;
		cursor = end;
	}
	return html + escapeHtml(source.slice(cursor));
}
