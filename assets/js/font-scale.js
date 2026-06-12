/**
 * Adjustable type scale: A− / A+ controls in the site header.
 * Loaded synchronously in <head> so the saved scale applies before first
 * paint. Every font-size in the stylesheet is rem-based, so scaling the
 * root font size scales text, navigation and controls proportionally.
 */

(() => {
	const STORAGE_KEY = "pnp:font-scale";
	const STEPS = [0.85, 1, 1.15, 1.3];
	const DEFAULT_INDEX = 1;

	const readSaved = () => {
		try {
			return Number(localStorage.getItem(STORAGE_KEY));
		} catch {
			return NaN;
		}
	};

	let index = STEPS.indexOf(readSaved());
	if (index === -1) index = DEFAULT_INDEX;

	const apply = () => {
		document.documentElement.style.setProperty(
			"--font-scale",
			String(STEPS[index]),
		);
	};

	const persist = () => {
		try {
			localStorage.setItem(STORAGE_KEY, String(STEPS[index]));
		} catch {
			// Private browsing: the scale still applies for this page view.
		}
	};

	apply();

	document.addEventListener("DOMContentLoaded", () => {
		const down = document.querySelector("[data-font-scale-down]");
		const up = document.querySelector("[data-font-scale-up]");
		if (!down || !up) return;

		const sync = () => {
			down.disabled = index === 0;
			up.disabled = index === STEPS.length - 1;
		};

		const step = (delta) => {
			index = Math.min(Math.max(index + delta, 0), STEPS.length - 1);
			apply();
			persist();
			sync();
		};

		down.addEventListener("click", () => step(-1));
		up.addEventListener("click", () => step(1));
		sync();
	});
})();
