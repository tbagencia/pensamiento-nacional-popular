/**
 * Credits panel functionality.
 * Any element with [data-credits-trigger] opens the dialog; focus is
 * trapped while open and returned to the trigger that opened it.
 */

(() => {
	const panel = document.getElementById("credits-panel");
	const triggers = document.querySelectorAll("[data-credits-trigger]");
	const closeBtn = document.getElementById("credits-close");
	const overlay = document.getElementById("credits-overlay");

	if (!panel || triggers.length === 0) return;

	let lastTrigger = null;

	const setExpanded = (value) => {
		triggers.forEach((t) => t.setAttribute("aria-expanded", String(value)));
	};

	const openPanel = (trigger) => {
		lastTrigger = trigger;
		panel.dataset.state = "open";
		panel.setAttribute("aria-hidden", "false");
		setExpanded(true);
		document.body.style.overflow = "hidden";
		closeBtn?.focus();
	};

	const closePanel = () => {
		panel.dataset.state = "";
		panel.setAttribute("aria-hidden", "true");
		setExpanded(false);
		document.body.style.overflow = "";
		lastTrigger?.focus();
	};

	triggers.forEach((trigger) => {
		trigger.addEventListener("click", () => openPanel(trigger));
	});
	closeBtn?.addEventListener("click", closePanel);
	overlay?.addEventListener("click", closePanel);

	document.addEventListener("keydown", (e) => {
		if (panel.dataset.state !== "open") return;

		if (e.key === "Escape") {
			closePanel();
			return;
		}

		// Keep Tab focus inside the dialog while it is open.
		if (e.key === "Tab") {
			const focusables = panel.querySelectorAll(
				'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
			);
			if (focusables.length === 0) return;
			const first = focusables[0];
			const last = focusables[focusables.length - 1];
			if (e.shiftKey && document.activeElement === first) {
				e.preventDefault();
				last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		}
	});
})();
