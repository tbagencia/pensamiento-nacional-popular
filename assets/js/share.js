/**
 * Shared share-button behaviour, used by the timeline (app.js) and the
 * document page (documento.js). Call initShare(root, resolveItem):
 * - root: element that receives the click delegation and owns the cards
 * - resolveItem(card): returns {id, title, author, year, excerpt} for
 *   the card whose share button was pressed, or null/undefined.
 */

const SHARE_ICONS = {
	link: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
	whatsapp:
		'<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg>',
	x: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z"/></svg>',
	facebook:
		'<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
};

// Touch devices get the native share sheet; pointer devices always get
// the in-page menu, which is cleaner than the OS dialog on desktop.
const shareTouchOnly = window.matchMedia("(hover: none)");

const SHARE_QUOTE_LENGTH = 200;

function clampQuote(excerpt, max) {
	const text = (excerpt ?? "").trim();
	return text.length > max ? `${text.slice(0, max - 1).trimEnd()}…` : text;
}

/** The shared payload is the quote itself, not just a link. */
function shareText(item) {
	return `«${clampQuote(item.excerpt, SHARE_QUOTE_LENGTH)}»\n${item.author} — ${item.title} (${item.year})`;
}

/** X caps posts at 280 chars and the composer may count the URL raw
 *  (not t.co-wrapped), so the budget assumes a full-length URL and the
 *  quote stays a short teaser — the full text lives on the page. */
const X_QUOTE_LENGTH = 120;
const X_TEXT_BUDGET = 220;

function shareTextForX(item) {
	const attribution = `\n${item.author} — ${item.title} (${item.year})`;
	const maxQuote = Math.min(
		X_QUOTE_LENGTH,
		X_TEXT_BUDGET - 2 - attribution.length,
	);
	if (maxQuote < 20) return attribution.trimStart();
	return `«${clampQuote(item.excerpt, maxQuote)}»${attribution}`;
}

function initShare(root, resolveItem) {
	root.addEventListener("click", (e) => {
		const btn = e.target.closest(".share-btn");
		if (btn) {
			const card = btn.closest(".card");
			const item = resolveItem(card);
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

	function shareDocument(item, btn, card) {
		// Path-based URL: crawlers never see hash fragments, and this one
		// serves per-document Open Graph tags. The server provides the
		// canonical slugged path; the bare id is a fallback that 301s to it.
		const url = `${location.origin}${item.path ?? `/documento/${item.id}`}`;
		const text = shareText(item);

		if (navigator.share && shareTouchOnly.matches) {
			navigator.share({ title: item.title, text, url }).catch(() => {});
			return;
		}

		const wasOpen = card.querySelector(".share-menu") !== null;
		closeShareMenus();
		if (!wasOpen) openShareMenu(btn, card, item, text, url);
	}

	function openShareMenu(btn, card, item, text, url) {
		const menu = document.createElement("div");
		menu.className = "share-menu";
		menu.innerHTML = `
			<button type="button" class="share-option" data-action="copy">
				${SHARE_ICONS.link}<span>Copiar link</span>
			</button>
			<hr class="share-divider" />
			<a class="share-option" target="_blank" rel="noopener noreferrer"
				href="https://wa.me/?text=${encodeURIComponent(`${text}\n${url}`)}">
				${SHARE_ICONS.whatsapp}<span>Compartir en WhatsApp</span>
			</a>
			<a class="share-option" target="_blank" rel="noopener noreferrer"
				href="https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}">
				${SHARE_ICONS.facebook}<span>Compartir en Facebook</span>
			</a>
			<a class="share-option" target="_blank" rel="noopener noreferrer"
				href="https://twitter.com/intent/tweet?text=${encodeURIComponent(shareTextForX(item))}&amp;url=${encodeURIComponent(url)}">
				${SHARE_ICONS.x}<span>Compartir en X</span>
			</a>`;

		// Platform shares carry the quote; copying is just the link.
		const copy = menu.querySelector('[data-action="copy"]');
		copy.dataset.payload = url;

		card.appendChild(menu);
		btn.setAttribute("aria-expanded", "true");
		copy.focus();
	}

	function copyShareLink(option) {
		navigator.clipboard
			?.writeText(option.dataset.payload)
			.then(() => {
				option.querySelector("span").textContent = "¡Copiado!";
				setTimeout(closeShareMenus, 900);
			})
			.catch(() => {});
	}

	function closeShareMenus() {
		for (const menu of root.querySelectorAll(".share-menu")) menu.remove();
		for (const b of root.querySelectorAll(
			'.share-btn[aria-expanded="true"]',
		)) {
			b.setAttribute("aria-expanded", "false");
		}
	}
}
