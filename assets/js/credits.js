/**
 * Credits panel functionality.
 * Reusable across all pages with a credits button.
 */

(() => {
	const panel = document.getElementById("credits-panel");
	const btn = document.getElementById("credits-btn");
	const closeBtn = document.getElementById("credits-close");
	const overlay = document.getElementById("credits-overlay");

	if (!panel || !btn) return;

	const openPanel = () => {
		panel.dataset.state = "open";
		panel.setAttribute("aria-hidden", "false");
		document.body.style.overflow = "hidden";
		closeBtn?.focus();
	};

	const closePanel = () => {
		panel.dataset.state = "";
		panel.setAttribute("aria-hidden", "true");
		document.body.style.overflow = "";
		btn.focus();
	};

	btn.addEventListener("click", openPanel);
	closeBtn?.addEventListener("click", closePanel);
	overlay?.addEventListener("click", closePanel);

	document.addEventListener("keydown", (e) => {
		if (e.key === "Escape" && panel.dataset.state === "open") {
			closePanel();
		}
	});
})();
