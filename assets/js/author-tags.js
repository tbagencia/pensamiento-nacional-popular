/**
 * Author tags input, shared by the submission form and the moderation
 * panel. The original input is hidden and keeps the comma-separated
 * value the server expects; visually each author is a removable pill.
 * Typing suggests existing authors (case- and accent-insensitive) in a
 * listbox under the field; a suggestion click, Enter or a comma turns
 * the text into a pill, so brand-new authors remain possible.
 */

const AUTHOR_SUGGESTIONS_MAX = 8;

function initAuthorTags(input, options) {
  const field = input.closest('.field') ?? input.parentElement;
  field.classList.add('has-autocomplete');

  // Commas split authors, except inside parentheses (legacy strings
  // like "FORJA (Jauretche, Scalabrini Ortiz y otros)" are one tag).
  const tags = input.value
    .split(/,(?![^()]*\))/)
    .map((part) => part.trim())
    .filter(Boolean);

  const isRequired = input.required;
  const placeholder = input.placeholder;
  input.type = 'hidden';
  input.required = false;

  const box = document.createElement('div');
  box.className = 'author-tags';
  const inner = document.createElement('input');
  inner.type = 'text';
  inner.className = 'author-tags-input';
  inner.maxLength = 120;
  inner.autocomplete = 'off';
  // The visible input inherits the label: clicking it must focus here.
  inner.id = input.id;
  input.removeAttribute('id');
  box.appendChild(inner);
  input.insertAdjacentElement('afterend', box);

  const list = document.createElement('ul');
  list.className = 'autocomplete-list';
  list.setAttribute('role', 'listbox');
  list.hidden = true;
  box.insertAdjacentElement('afterend', list);

  let activeIndex = -1;

  const normalize = (text) =>
    text.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

  function sync() {
    input.value = tags.join(', ');
    inner.required = isRequired && tags.length === 0;
    inner.placeholder = tags.length === 0 ? placeholder : '';
  }

  function renderTags() {
    for (const pill of box.querySelectorAll('.author-tag')) pill.remove();
    for (const [i, name] of tags.entries()) {
      const pill = document.createElement('span');
      pill.className = 'author-tag';
      pill.textContent = name;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.setAttribute('aria-label', `Quitar ${name}`);
      remove.textContent = '×';
      remove.addEventListener('click', () => {
        tags.splice(i, 1);
        sync();
        renderTags();
        inner.focus();
      });
      pill.appendChild(remove);
      box.insertBefore(pill, inner);
    }
    sync();
  }

  function closeList() {
    list.hidden = true;
    list.textContent = '';
    activeIndex = -1;
  }

  function addTag(name) {
    const clean = name.trim();
    if (clean !== '' && !tags.includes(clean)) {
      tags.push(clean);
      renderTags();
    }
    inner.value = '';
    closeList();
  }

  function openList(matches) {
    list.textContent = '';
    for (const name of matches) {
      const item = document.createElement('li');
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', 'false');
      item.textContent = name;
      // mousedown, not click: it fires before the input loses focus.
      item.addEventListener('mousedown', (e) => {
        e.preventDefault();
        addTag(name);
      });
      list.appendChild(item);
    }
    list.style.insetBlockStart = `${box.offsetTop + box.offsetHeight + 4}px`;
    list.hidden = false;
  }

  inner.addEventListener('input', () => {
    const q = normalize(inner.value.trim());
    if (q === '') return closeList();
    const matches = options
      .filter((name) => normalize(name).includes(q) && !tags.includes(name))
      .slice(0, AUTHOR_SUGGESTIONS_MAX);
    matches.length ? openList(matches) : closeList();
  });

  inner.addEventListener('keydown', (e) => {
    const items = [...list.children];
    if (!list.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      e.preventDefault();
      const step = e.key === 'ArrowDown' ? 1 : -1;
      activeIndex = (activeIndex + step + items.length) % items.length;
      items.forEach((el, i) =>
        el.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false'),
      );
      items[activeIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' && activeIndex >= 0) {
      e.preventDefault();
      addTag(items[activeIndex].textContent);
    } else if ((e.key === 'Enter' || e.key === ',') && inner.value.trim() !== '') {
      e.preventDefault();
      addTag(inner.value);
    } else if (e.key === 'Escape') {
      closeList();
    } else if (e.key === 'Backspace' && inner.value === '' && tags.length > 0) {
      tags.pop();
      renderTags();
    }
  });

  inner.addEventListener('blur', () => {
    if (inner.value.trim() !== '') addTag(inner.value);
    closeList();
  });

  box.addEventListener('click', () => inner.focus());

  renderTags();
}
