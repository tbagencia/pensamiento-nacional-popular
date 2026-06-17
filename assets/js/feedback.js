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
						¿Querés hacer un comentario, una consulta o una sugerencia,
						o reportar un error? Escribinos.
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
							aria-describedby="feedback-email-hint"
						/>
						<p class="field-hint" id="feedback-email-hint">Solo si querés que te respondamos. No se publica.</p>
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
	const messageInput = panel.querySelector("#feedback-message");
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
		emailInput.removeAttribute("aria-invalid"); // fresh state on kind change
	};

	form.querySelector("#feedback-kind").addEventListener("change", (e) => {
		applyEmailToggle(e.target.value);
	});

	// Clear a field's error styling as soon as its value becomes valid.
	const clearOnFix = (input) => {
		input.addEventListener("input", () => {
			if (input.getAttribute("aria-invalid") === "true" && input.checkValidity()) {
				input.removeAttribute("aria-invalid");
				const errEl = panel.querySelector(`.field-error[data-for="${input.name}"]`);
				if (errEl) errEl.textContent = "";
			}
		});
	};
	clearOnFix(messageInput);
	clearOnFix(emailInput);

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
		messageInput.removeAttribute("aria-invalid");
		emailInput.removeAttribute("aria-invalid");

		// Validate every field up front so all errors surface at once and
		// the user is not fixed one by one. aria-invalid keeps each error
		// accessible beyond the red colour alone. Focus the first offender.
		const errors = {};
		let firstInvalid = null;

		// The message is always required.
		if (!messageInput.checkValidity()) {
			messageInput.setAttribute("aria-invalid", "true");
			errors.message = "El mensaje es obligatorio.";
			firstInvalid ??= messageInput;
		}

		// For a consulta the email is required and must be well-formed;
		// checkValidity() covers both (required + type="email"). The red
		// border + red hint carry the message, so no extra text is shown.
		if (form.kind.value === "consulta" && !emailInput.checkValidity()) {
			emailInput.setAttribute("aria-invalid", "true");
			firstInvalid ??= emailInput;
		}

		if (firstInvalid) {
			showErrors(errors);
			firstInvalid.focus();
			return;
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
			const errors = data.errors ?? {
				general: "No se pudo enviar. Probá de nuevo.",
			};
			showErrors(errors);
			if (errors.email) emailInput.setAttribute("aria-invalid", "true");
		} catch {
			showErrors({ general: "No se pudo enviar. Probá de nuevo." });
		} finally {
			submitBtn.disabled = false;
			submitBtn.textContent = "Enviar";
		}
	});
})();
