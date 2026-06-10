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
let spyObserver = null;
let revealObserver = null;

const EXCERPT_COLLAPSE_LENGTH = 320;
const YEAR_PATH = /^\/linea\/(\d{4})\/?$/;
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

		buildTypeNav();
		setupYearNavClicks();
		setupNavMetrics();
		setupReadMore();
		setupShare();
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
	return activeType
		? allResources.filter((r) => r.type === activeType)
		: allResources;
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
		timelineEl.innerHTML =
			'<li class="empty">No hay documentos para este filtro.</li>';
		return;
	}

	for (const [year, items] of byYear) {
		const li = document.createElement("li");
		li.className = "timeline-year";
		li.id = `year-${year}`;
		li.dataset.year = String(year);
		li.innerHTML = `<h2>${year}</h2>`;

		for (const item of items) {
			const isLong = (item.excerpt ?? "").length > EXCERPT_COLLAPSE_LENGTH;
			const card = document.createElement("article");
			card.className = "card";
			card.id = `doc-${item.id}`;
			card.dataset.state = "hidden";
			card.innerHTML = `
        <button type="button" class="share-btn" aria-label="Compartir este documento" aria-expanded="false">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="10.49" x2="8.59" y2="6.51"/></svg>
        </button>
        <span class="badge badge-type">${escapeHtml(item.type)}</span>
        <h3>${escapeHtml(item.title)}</h3>
        <p class="author">${escapeHtml(item.author)}</p>
        <p class="excerpt" ${isLong ? 'data-state="collapsed"' : ""}>${escapeHtml(item.excerpt)}</p>
        ${isLong ? '<button type="button" class="read-more" aria-expanded="false">Leer más</button>' : ""}
        ${
					item.source_url
						? `<p><a class="source-link" href="${escapeHtml(item.source_url)}" target="_blank" rel="noopener noreferrer">Ver fuente &rarr;</a></p>`
						: ""
				}`;
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
		history.pushState(null, "", `/linea/${year}`);
		scrollToYear(year, prefersReducedMotion.matches ? "auto" : "smooth");
	});
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
		pill.setAttribute("aria-pressed", String(type === "all"));
		pill.textContent = type === "all" ? "Todos" : TYPE_LABELS[type];
		typeNav.appendChild(pill);
	}
	typeNav.hidden = false;

	typeNav.addEventListener("click", (e) => {
		const pill = e.target.closest(".type-pill");
		if (!pill) return;

		// Clicking the active filter (or "Todos") returns to the unfiltered view.
		const picked = pill.dataset.type === "all" ? null : pill.dataset.type;
		activeType = activeType === picked ? null : picked;
		for (const p of typeNav.querySelectorAll(".type-pill")) {
			p.setAttribute(
				"aria-pressed",
				String(p.dataset.type === (activeType ?? "all")),
			);
		}
		renderAll();
		history.replaceState(null, "", "/");
		window.scrollTo({ top: 0, behavior: "auto" });
	});
}

function scrollToYear(year, behavior) {
	setActiveChip(year);
	if (year === "all") {
		window.scrollTo({ top: 0, behavior });
		return;
	}
	document
		.getElementById(`year-${year}`)
		?.scrollIntoView({ behavior, block: "start" });
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
	window.addEventListener("popstate", () => goToYearFromUrl(true));
}

/** Mirrors the year being viewed into the address bar (shareable URLs). */
function syncUrl(year) {
	const target = year === "all" ? "/" : `/linea/${year}`;
	if (location.pathname !== target) {
		history.replaceState(null, "", target);
	}
}

/* ---------- Navigation interactivity ---------- */

// macOS-dock-like magnification: the active pill is largest and
// neighbours shrink with distance.
const CHIP_SCALES = [1.15, 1.08, 1.03, 1];

function setActiveChip(year) {
	const chips = [...yearNav.querySelectorAll(".year-chip")];
	const activeIndex = chips.findIndex((c) => c.dataset.year === String(year));

	chips.forEach((chip, i) => {
		const distance = activeIndex === -1 ? Infinity : Math.abs(i - activeIndex);
		chip.style.scale = prefersReducedMotion.matches
			? ""
			: String(CHIP_SCALES[Math.min(distance, CHIP_SCALES.length - 1)]);

		if (i === activeIndex) {
			chip.setAttribute("aria-current", "location");
			// Keep the active chip centered in the scrollable nav
			// (vertical rail on desktop, horizontal bar on mobile).
			const behavior = prefersReducedMotion.matches ? "auto" : "smooth";
			if (desktopRail.matches) {
				yearNav.scrollTo({
					top:
						chip.offsetTop - yearNav.clientHeight / 2 + chip.offsetHeight / 2,
					behavior,
				});
			} else {
				yearNav.scrollTo({
					left:
						chip.offsetLeft - yearNav.clientWidth / 2 + chip.offsetWidth / 2,
					behavior,
				});
			}
		} else {
			chip.removeAttribute("aria-current");
		}
	});
}

function setupScrollSpy() {
	spyObserver?.disconnect();
	const sections = timelineEl.querySelectorAll(".timeline-year");
	if (!("IntersectionObserver" in window) || sections.length === 0) return;

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
				setActiveChip("all");
				syncUrl("all");
			}
		},
		{ rootMargin: "-25% 0px -65% 0px" },
	);
	sections.forEach((s) => spy.observe(s));
	spyObserver = spy;
}

function applyNavMetrics() {
	// On desktop the nav is a side rail and takes no top space.
	const navHeight = desktopRail.matches ? 0 : yearNav.offsetHeight;
	document.documentElement.style.setProperty("--nav-h", `${navHeight}px`);

	// Year sections stop below the sticky nav when scrolled into view.
	for (const section of timelineEl.querySelectorAll(".timeline-year")) {
		section.style.scrollMarginTop = `${navHeight + 8}px`;
	}
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
	cards.forEach((c) => reveal.observe(c));
	revealObserver = reveal;
}

/* ---------- Read more toggle ---------- */

function setupReadMore() {
	timelineEl.addEventListener("click", (e) => {
		const btn = e.target.closest(".read-more");
		if (!btn) return;

		const excerpt = btn.parentElement.querySelector(".excerpt");
		const collapsed = excerpt.dataset.state === "collapsed";
		excerpt.dataset.state = collapsed ? "expanded" : "collapsed";
		btn.setAttribute("aria-expanded", String(collapsed));
		btn.textContent = collapsed ? "Leer menos" : "Leer más";
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

/* ---------- Share ---------- */

function setupShare() {
	timelineEl.addEventListener("click", (e) => {
		const btn = e.target.closest(".share-btn");
		if (btn) {
			const card = btn.closest(".card");
			const item = allResources.find((r) => `doc-${r.id}` === card.id);
			if (item) shareDocument(item, btn, card);
			return;
		}

		const option = e.target.closest(".share-option");
		if (option) {
			if (option.dataset.action === "copy") {
				copyShareLink(option);
			} else {
				// Let the link open its new tab before tearing the menu down.
				setTimeout(closeShareMenus, 0);
			}
		}
	});

	document.addEventListener("click", (e) => {
		if (!e.target.closest(".share-btn") && !e.target.closest(".share-menu")) {
			closeShareMenus();
		}
	});
	document.addEventListener("keydown", (e) => {
		if (e.key === "Escape") closeShareMenus();
	});
}

function shareDocument(item, btn, card) {
	const url = `${location.origin}/linea/${item.year}#doc-${item.id}`;
	const text = `«${item.title}» — ${item.author} (${item.year})`;

	// Native share sheet where available (mostly mobile).
	if (navigator.share) {
		navigator.share({ title: item.title, text, url }).catch(() => {});
		return;
	}

	const wasOpen = card.querySelector(".share-menu") !== null;
	closeShareMenus();
	if (!wasOpen) openShareMenu(btn, card, text, url);
}

function openShareMenu(btn, card, text, url) {
	const menu = document.createElement("div");
	menu.className = "share-menu";

	const copy = document.createElement("button");
	copy.type = "button";
	copy.className = "share-option";
	copy.dataset.action = "copy";
	copy.dataset.url = url;
	copy.textContent = "Copiar enlace";

	const whatsapp = document.createElement("a");
	whatsapp.className = "share-option";
	whatsapp.href = `https://wa.me/?text=${encodeURIComponent(`${text} ${url}`)}`;
	whatsapp.target = "_blank";
	whatsapp.rel = "noopener noreferrer";
	whatsapp.textContent = "WhatsApp";

	const x = document.createElement("a");
	x.className = "share-option";
	x.href = `https://twitter.com/intent/tweet?text=${encodeURIComponent(text)}&url=${encodeURIComponent(url)}`;
	x.target = "_blank";
	x.rel = "noopener noreferrer";
	x.textContent = "Compartir en X";

	menu.append(copy, whatsapp, x);
	card.appendChild(menu);
	btn.setAttribute("aria-expanded", "true");
	copy.focus();
}

function copyShareLink(option) {
	navigator.clipboard
		?.writeText(option.dataset.url)
		.then(() => {
			option.textContent = "¡Enlace copiado!";
			setTimeout(closeShareMenus, 900);
		})
		.catch(() => {});
}

function closeShareMenus() {
	for (const menu of timelineEl.querySelectorAll(".share-menu")) menu.remove();
	for (const b of timelineEl.querySelectorAll(
		'.share-btn[aria-expanded="true"]',
	)) {
		b.setAttribute("aria-expanded", "false");
	}
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
