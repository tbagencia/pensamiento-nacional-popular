/**
 * Feedback panel functionality.
 * Injects a slide-in dialog (same shell as the credits panel) with a short
 * form that posts to /api/feedback.php. Any element with
 * [data-feedback-trigger] opens it; focus is trapped while open and
 * returned to the trigger that opened it.
 */

(() => {
	const triggers = document.querySelectorAll("[data-feedback-trigger]");
	if (triggers.length === 0) return;

	const panel = document.createElement("aside");
	panel.id = "feedback-panel";
	panel.className = "credits-panel";
	panel.setAttribute("role", "dialog");
	panel.setAttribute("aria-modal", "true");
	panel.setAttribute("aria-labelledby", "feedback-title");
	panel.setAttribute("aria-hidden", "true");
	panel.innerHTML = `
		<div class="credits-overlay" data-feedback-overlay></div>
		<div class="credits-content">
			<header class="credits-head">
				<button type="button" class="credits-close" data-feedback-close aria-label="Cerrar">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="18" y1="6" x2="6" y2="18" />
						<line x1="6" y1="6" x2="18" y2="18" />
					</svg>
				</button>
				<p class="eyebrow">Archivo colaborativo</p>
				<h2 id="feedback-title">Contacto</h2>
			</header>
			<div class="credits-body">
				<form id="feedback-form" novalidate>
					<p>
						¿Tenés un comentario, encontraste un error, una sugerencia
						o una consulta? Escribinos.
					</p>
					<div class="field">
						<label for="feedback-kind">Tipo *</label>
						<select id="feedback-kind" name="kind">
							<option value="comentario">Comentario</option>
							<option value="error">Error del sitio</option>
							<option value="sugerencia">Sugerencia</option>
							<option value="consulta">Consulta</option>
						</select>
					</div>
					<div class="field">
						<label for="feedback-message">Mensaje *</label>
						<textarea
							id="feedback-message"
							name="message"
							rows="6"
							maxlength="3000"
							required
							placeholder="Contanos qué encontraste o qué te gustaría mejorar."
						></textarea>
						<p class="field-error" data-for="message"></p>
					</div>
					<div class="field">
						<label for="feedback-email" id="feedback-email-label">Tu email (opcional)</label>
						<input
							type="email"
							id="feedback-email"
							name="email"
							placeholder="tu@email.com"
						/>
						<p class="field-hint" id="feedback-email-hint">Solo si querés que te respondamos. No se publica.</p>
						<p class="field-error" data-for="email"></p>
					</div>
					<!-- Honeypot field: hidden from humans, bots tend to fill it -->
					<div class="hp-field" aria-hidden="true">
						<label for="feedback-website">Sitio web</label>
						<input type="text" id="feedback-website" name="website" tabindex="-1" autocomplete="off" />
					</div>
					<button type="submit" class="btn btn-primary btn-block" id="feedback-submit">Enviar</button>
					<p class="field-error" data-for="general"></p>
				</form>
				<div id="feedback-success" hidden>
					<p class="status-mark" data-tone="ok" aria-hidden="true">✓</p>
					<p><strong>¡Gracias por tu mensaje!</strong></p>
					<p>Lo vamos a revisar a la brevedad.</p>
				</div>
			</div>
		</div>
	`;
	document.body.appendChild(panel);

	const form = panel.querySelector("#feedback-form");
	const success = panel.querySelector("#feedback-success");
	const closeBtn = panel.querySelector("[data-feedback-close]");
	const overlay = panel.querySelector("[data-feedback-overlay]");
	const submitBtn = panel.querySelector("#feedback-submit");
	const emailInput = panel.querySelector("#feedback-email");
	const emailLabel = panel.querySelector("#feedback-email-label");
	const emailHint = panel.querySelector("#feedback-email-hint");

	const applyEmailToggle = (kind) => {
		if (kind === "consulta") {
			emailLabel.textContent = "Tu email *";
			emailInput.required = true;
			emailHint.textContent = "Necesitamos un email para poder responderte.";
		} else {
			emailLabel.textContent = "Tu email (opcional)";
			emailInput.required = false;
			emailHint.textContent = "Solo si querés que te respondamos. No se publica.";
		}
	};

	form.querySelector("#feedback-kind").addEventListener("change", (e) => {
		applyEmailToggle(e.target.value);
	});

	let lastTrigger = null;

	const setExpanded = (value) => {
		triggers.forEach((t) => t.setAttribute("aria-expanded", String(value)));
	};

	/** The credits panel may be open (trigger lives inside it): close it first. */
	const closeCreditsPanel = () => {
		const credits = document.getElementById("credits-panel");
		if (credits?.dataset.state === "open") {
			credits.dataset.state = "";
			credits.setAttribute("aria-hidden", "true");
			document
				.querySelectorAll("[data-credits-trigger]")
				.forEach((t) => t.setAttribute("aria-expanded", "false"));
		}
	};

	const openPanel = (trigger) => {
		lastTrigger = trigger;
		closeCreditsPanel();
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

	const showErrors = (errors) => {
		panel.querySelectorAll(".field-error").forEach((el) => {
			el.textContent = errors[el.dataset.for] ?? "";
		});
	};

	form.addEventListener("submit", async (e) => {
		e.preventDefault();
		showErrors({});

		if (form.kind.value === "consulta") {
			if (form.email.value.trim() === "") {
				showErrors({ email: "Ingrese un email para que podamos responderte." });
				return;
			}
			// novalidate disables form-level validation, but checkValidity()
			// on the type="email" input still flags a malformed address.
			if (!form.email.checkValidity()) {
				showErrors({ email: "Ingrese un email válido." });
				return;
			}
		}

		submitBtn.disabled = true;
		submitBtn.textContent = "Enviando…";

		try {
			const res = await fetch("/api/feedback.php", {
				method: "POST",
				headers: { "Content-Type": "application/json" },
				body: JSON.stringify({
					kind: form.kind.value,
					message: form.message.value,
					email: form.email.value,
					website: form.website.value,
					page: window.location.pathname,
				}),
			});
			const data = await res.json();

			if (res.ok) {
				form.hidden = true;
				success.hidden = false;
				return;
			}
			showErrors(
				data.errors ?? { general: "No se pudo enviar. Probá de nuevo." },
			);
		} catch {
			showErrors({ general: "No se pudo enviar. Probá de nuevo." });
		} finally {
			submitBtn.disabled = false;
			submitBtn.textContent = "Enviar";
		}
	});
})();
