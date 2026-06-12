/**
 * Mobile year-pill docking.
 * On narrow viewports the gold year heading is sticky against the top
 * year bar. When it reaches the bar this module marks it as docked and
 * measures the translate/scale needed for it to rest exactly over the
 * active chip (which the scrollspy keeps centered), so the vertical
 * scroll and the horizontal bar stay visually in sync. Desktop keeps
 * its own sticky behaviour and is left untouched.
 */

(() => {
	const desktopRail = window.matchMedia("(min-width: 1150px)");
	const nav = document.querySelector(".year-nav");
	const timeline = document.getElementById("timeline");
	if (!nav || !timeline) return;

	let ticking = false;

	const headings = () => timeline.querySelectorAll(".timeline-year > h2");

	const clearAll = () => {
		headings().forEach((h2) => {
			delete h2.dataset.docked;
			h2.style.removeProperty("--dock-shift");
			h2.style.removeProperty("--dock-scale");
		});
	};

	/** Vertically center the pill within the bar via --dock-top. */
	const setDockTop = () => {
		if (desktopRail.matches) return;
		const pill = headings()[0];
		if (!pill) return;
		const top = Math.max(0, (nav.offsetHeight - pill.offsetHeight) / 2);
		document.documentElement.style.setProperty("--dock-top", `${top}px`);
	};

	const update = () => {
		ticking = false;
		if (desktopRail.matches) {
			clearAll();
			return;
		}

		const chip = nav.querySelector('.year-chip[aria-current="location"]');
		const dockTop =
			parseFloat(document.documentElement.style.getPropertyValue("--dock-top")) || 0;

		headings().forEach((h2) => {
			const section = h2.parentElement.getBoundingClientRect();
			// offsetTop/offsetLeft give the un-transformed flow position, so
			// the docking decision is immune to the dock transform itself.
			const naturalTop = section.top + h2.offsetTop;
			const docked =
				naturalTop <= dockTop + 1 &&
				section.bottom >= dockTop + h2.offsetHeight;

			if (docked && chip) {
				const chipRect = chip.getBoundingClientRect();
				const naturalCenter =
					section.left + h2.offsetLeft + h2.offsetWidth / 2;
				const shift = chipRect.left + chipRect.width / 2 - naturalCenter;
				const scale = Math.min(1, chipRect.height / h2.offsetHeight);
				h2.style.setProperty("--dock-shift", `${shift}px`);
				h2.style.setProperty("--dock-scale", String(scale));
				h2.dataset.docked = "true";
			} else if (h2.dataset.docked) {
				delete h2.dataset.docked;
				h2.style.removeProperty("--dock-shift");
				h2.style.removeProperty("--dock-scale");
			}
		});
	};

	const schedule = () => {
		if (ticking) return;
		ticking = true;
		window.requestAnimationFrame(update);
	};

	window.addEventListener("scroll", schedule, { passive: true });
	nav.addEventListener("scroll", schedule, { passive: true });
	window.addEventListener("resize", () => {
		setDockTop();
		schedule();
	});
	desktopRail.addEventListener("change", () => {
		setDockTop();
		schedule();
	});

	// The timeline renders asynchronously: measure once headings exist.
	const observer = new MutationObserver(() => {
		if (headings().length === 0) return;
		observer.disconnect();
		setDockTop();
		schedule();
	});
	observer.observe(timeline, { childList: true });
	setDockTop();
	schedule();
})();
