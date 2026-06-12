/**
 * First-visit onboarding tour.
 * Spotlight walkthrough of the timeline features: a dimmed overlay with a
 * cut-out over each target element and a tooltip with step copy. Runs once
 * per browser (localStorage), waits for the timeline to render before
 * starting, and can be replayed via [data-tour-trigger].
 */

(() => {
	const STORAGE_KEY = "pnp:tour-seen";
	const HIGHLIGHT_PADDING = 8;
	const TIP_GAP = 14;

	const steps = [
		{
			target: null,
			title: "¡Bienvenido al archivo!",
			text: "Esta es una línea de tiempo colaborativa de los discursos, cartas y textos del pensamiento nacional y popular argentino. Te mostramos cómo recorrerla en unos pasos.",
		},
		{
			target: ".year-nav",
			title: "Navegá por año",
			text: "La línea de tiempo va de 1810 hasta hoy. Con esta navegación saltás directo al año que te interese.",
		},
		{
			target: ".search-bar",
			title: "Buscá por autor o término",
			text: "Encontrá documentos por autor, título o una palabra del texto. La búsqueda ignora las tildes.",
		},
		{
			target: "#type-nav",
			title: "Filtrá por tipo",
			text: "Mirá solo discursos, cartas, manifiestos, libros y más.",
		},
		{
			target: ".site-header .btn-accent",
			title: "Aportá un documento",
			text: "El archivo se construye entre todos. Sumá ese discurso, carta o texto que te parece imperdible.",
		},
		{
			target: ".font-controls",
			title: "Ajustá el tamaño del texto",
			text: "Estos botones te acompañan mientras leés, abajo a la derecha: con A+ y A− agrandás o achicás toda la letra del sitio. Tu elección queda guardada para la próxima visita.",
		},
		{
			target: ".feedback-fab",
			title: "Contanos qué te parece",
			text: "¿Encontraste un error o tenés una sugerencia? El botón Feedback, abajo a la izquierda, siempre está a mano para enviarnos un comentario.",
		},
	];

	let root = null;
	let dims = null;
	let tip = null;
	let current = 0;

	const isVisible = (el) =>
		el && el.getClientRects().length > 0 && !el.closest("[hidden]");

	const resolveTarget = (step) =>
		step.target ? document.querySelector(step.target) : null;

	const build = () => {
		root = document.createElement("div");
		root.className = "tour";
		root.innerHTML = `
			<div class="tour-dim" aria-hidden="true"></div>
			<div class="tour-dim" aria-hidden="true"></div>
			<div class="tour-dim" aria-hidden="true"></div>
			<div class="tour-dim" aria-hidden="true"></div>
			<div class="tour-tip" role="dialog" aria-modal="false" aria-labelledby="tour-title" tabindex="-1">
				<h2 id="tour-title" class="tour-tip-title"></h2>
				<p class="tour-tip-text"></p>
				<div class="tour-actions">
					<button type="button" class="link-button" data-tour-skip>Saltar</button>
					<span class="tour-progress" aria-hidden="true"></span>
					<button type="button" class="btn btn-ghost" data-tour-prev>Anterior</button>
					<button type="button" class="btn btn-primary" data-tour-next>Siguiente</button>
				</div>
			</div>
		`;
		document.body.appendChild(root);
		dims = [...root.querySelectorAll(".tour-dim")];
		tip = root.querySelector(".tour-tip");

		root.querySelector("[data-tour-skip]").addEventListener("click", end);
		root.querySelector("[data-tour-prev]").addEventListener("click", () => move(-1));
		root.querySelector("[data-tour-next]").addEventListener("click", () => move(1));
	};

	/** Four panels around the cut-out: giant box-shadows mis-repaint on scroll. */
	const placeDim = (hole) => {
		const vw = window.innerWidth;
		const vh = window.innerHeight;
		const top = Math.max(0, hole.top);
		const bottom = Math.min(vh, hole.bottom);
		dims[0].style.cssText = `top: 0; left: 0; width: ${vw}px; height: ${top}px;`;
		dims[1].style.cssText = `top: ${bottom}px; left: 0; width: ${vw}px; height: ${Math.max(0, vh - bottom)}px;`;
		dims[2].style.cssText = `top: ${top}px; left: 0; width: ${Math.max(0, hole.left)}px; height: ${Math.max(0, bottom - top)}px;`;
		dims[3].style.cssText = `top: ${top}px; left: ${hole.right}px; width: ${Math.max(0, vw - hole.right)}px; height: ${Math.max(0, bottom - top)}px;`;
	};

	const position = () => {
		const step = steps[current];
		const target = resolveTarget(step);

		if (!target || !isVisible(target)) {
			// Centered welcome step: zero-size cut-out keeps the full dim.
			placeDim({ top: 0, bottom: 0, left: 0, right: 0 });
			tip.dataset.placement = "center";
			tip.style.top = "";
			tip.style.left = "";
			return;
		}

		const rect = target.getBoundingClientRect();
		const pad = HIGHLIGHT_PADDING;
		placeDim({
			top: rect.top - pad,
			bottom: rect.bottom + pad,
			left: rect.left - pad,
			right: rect.right + pad,
		});

		tip.dataset.placement = "anchored";
		const tipRect = tip.getBoundingClientRect();
		const margin = 12;
		let top = rect.bottom + pad + TIP_GAP;
		if (top + tipRect.height > window.innerHeight - margin) {
			top = rect.top - pad - TIP_GAP - tipRect.height;
		}
		if (top < margin) {
			top = Math.max(margin, (window.innerHeight - tipRect.height) / 2);
		}
		let left = rect.left + rect.width / 2 - tipRect.width / 2;
		left = Math.min(
			Math.max(margin, left),
			window.innerWidth - tipRect.width - margin,
		);
		tip.style.top = `${top}px`;
		tip.style.left = `${left}px`;
	};

	const show = () => {
		const step = steps[current];
		tip.querySelector(".tour-tip-title").textContent = step.title;
		tip.querySelector(".tour-tip-text").textContent = step.text;
		tip.querySelector(".tour-progress").textContent =
			`${current + 1} de ${steps.length}`;
		tip.querySelector("[data-tour-prev]").hidden = current === 0;
		tip.querySelector("[data-tour-next]").textContent =
			current === steps.length - 1 ? "¡Listo!" : "Siguiente";

		const target = resolveTarget(step);
		if (target && isVisible(target)) {
			target.scrollIntoView({ block: "center", behavior: "smooth" });
		}
		position();
		tip.focus({ preventScroll: true });
	};

	/** Advance in either direction, skipping steps whose target is hidden. */
	const move = (dir) => {
		let next = current + dir;
		while (next > 0 && next < steps.length) {
			const step = steps[next];
			if (!step.target || isVisible(resolveTarget(step))) break;
			next += dir;
		}
		if (next >= steps.length) {
			// Tour completed: bring the visitor back to the top of the page.
			end();
			window.scrollTo({ top: 0, behavior: "smooth" });
			return;
		}
		current = Math.max(0, next);
		show();
	};

	const onKeydown = (e) => {
		if (e.key === "Escape") end();
		if (e.key === "ArrowRight") move(1);
		if (e.key === "ArrowLeft") move(-1);
	};

	const onReposition = () => {
		window.requestAnimationFrame(position);
	};

	const start = () => {
		if (root) return;
		// The trigger may live inside the credits panel: close it first.
		const credits = document.getElementById("credits-panel");
		if (credits?.dataset.state === "open") {
			credits.dataset.state = "";
			credits.setAttribute("aria-hidden", "true");
			document.body.style.overflow = "";
			document
				.querySelectorAll("[data-credits-trigger]")
				.forEach((t) => t.setAttribute("aria-expanded", "false"));
		}
		window.scrollTo({ top: 0 });
		build();
		current = 0;
		show();
		document.addEventListener("keydown", onKeydown);
		window.addEventListener("resize", onReposition);
		window.addEventListener("scroll", onReposition, { passive: true });
	};

	const end = () => {
		localStorage.setItem(STORAGE_KEY, "1");
		document.removeEventListener("keydown", onKeydown);
		window.removeEventListener("resize", onReposition);
		window.removeEventListener("scroll", onReposition);
		root?.remove();
		root = null;
	};

	document.querySelectorAll("[data-tour-trigger]").forEach((trigger) => {
		trigger.addEventListener("click", start);
	});

	if (localStorage.getItem(STORAGE_KEY)) return;

	/** Start only once the timeline has rendered; never on a broken page. */
	const timeline = document.getElementById("timeline");
	if (!timeline) return;
	if (timeline.children.length > 0) {
		start();
		return;
	}
	const observer = new MutationObserver(() => {
		if (timeline.children.length === 0) return;
		observer.disconnect();
		clearTimeout(giveUp);
		start();
	});
	observer.observe(timeline, { childList: true });
	const giveUp = setTimeout(() => observer.disconnect(), 8000);
})();
