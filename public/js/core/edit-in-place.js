/* Package E */
// Click-to-edit (point 28, <x-edit-in-place>): plain text until clicked or Enter, then a field of the same size.
// Blur or Ctrl+Enter saves (POST field + value, JSON answer), Escape restores the old text. Works in windows
// loaded later too (one listener for the page). Also: [data-focus="id"] menu entries focus that field.
(() => {
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const note = (text, failed) => {
        const el = document.createElement('div');
        el.className = failed ? 'toast toast-error' : 'toast';
        el.setAttribute('role', failed ? 'alert' : 'status');
        el.textContent = text;
        document.body.append(el);
        const leave = () => { el.classList.add('is-leaving'); setTimeout(() => el.remove(), 300); };
        el.addEventListener('click', leave);
        setTimeout(leave, failed ? 5000 : 2000);
    };
    const grow = field => {
        if (field.tagName === 'TEXTAREA') {
            field.style.height = 'auto';
            field.style.height = `${field.scrollHeight + 2}px`;
        }
    };
    const finish = (box, field) => {
        field.remove();
        box.hidden = false;
    };
    const save = (box, field) => {
        const old = box.textContent;
        const value = field.value;
        finish(box, field);
        if (value.trim() === old.trim()) {
            return;
        }
        box.textContent = value.trim();
        box.classList.add('is-saving');
        const body = new FormData();
        body.append('field', box.dataset.field);
        body.append('value', value);
        fetch(box.dataset.editInPlace, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async response => {
                const json = await response.json().catch(() => ({}));
                // A refused value (422) names why, e.g. "Das ist kein Datum" (properties, point 122.4).
                return response.ok ? json : Promise.reject(new Error(response.status === 422 && json.message ? json.message : ''));
            })
            .then(data => {
                box.textContent = typeof data.value === 'string' ? data.value : value.trim();
                note(box.dataset.saved, false);
                window.DocHamster?.emit('edit-in-place:saved', { box, json: data });
            })
            .catch(error => {
                box.textContent = old;
                note(error?.message || box.dataset.failed, true);
            })
            .finally(() => box.classList.remove('is-saving'));
    };
    const start = box => {
        if (box.hidden || box.classList.contains('is-saving')) {
            return;
        }
        const multiline = box.classList.contains('is-multiline');
        const field = document.createElement(multiline ? 'textarea' : 'input');
        field.className = `edit-in-place-field ${box.className.replace(/\bedit-in-place\b/, '')}`.trim();
        field.value = box.textContent;
        field.placeholder = box.dataset.placeholder ?? '';
        if (box.dataset.maxlength) {
            field.maxLength = Number(box.dataset.maxlength);
        }
        const labelledBy = box.getAttribute('aria-labelledby');
        if (labelledBy) {
            field.setAttribute('aria-labelledby', labelledBy);
        }
        const height = box.offsetHeight;
        box.hidden = true;
        box.after(field);
        if (multiline) {
            field.style.minHeight = `${height}px`;
            grow(field);
        }
        field.focus();
        field.addEventListener('input', () => grow(field));
        field.addEventListener('blur', () => { if (field.isConnected && !field.dataset.cancelled) save(box, field); });
        field.addEventListener('keydown', event => {
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey || !multiline)) {
                event.preventDefault();
                field.blur();
                box.focus();
            }
        });
    };
    document.addEventListener('click', event => {
        const box = event.target.closest('[data-edit-in-place]');
        if (box) {
            start(box);
            return;
        }
        const jump = event.target.closest('[data-focus]');
        const target = jump && document.getElementById(jump.dataset.focus);
        if (target?.matches('[data-edit-in-place]')) {
            event.preventDefault();
            start(target);
        } else if (target) {
            event.preventDefault();
            target.scrollIntoView({ block: 'center', behavior: 'smooth' });
            target.focus({ preventScroll: true });
            target.select?.();
        }
    });
    document.addEventListener('keydown', event => {
        const box = event.target.closest?.('[data-edit-in-place]');
        if (box && event.key === 'Enter') {
            event.preventDefault();
            start(box);
        }
    });
    // Escape first, before windows, columns and dialogs react to the same key (window capture runs before document).
    window.addEventListener('keydown', event => {
        const field = event.target.closest?.('.edit-in-place-field');
        if (field && event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            const box = field.previousElementSibling;
            field.dataset.cancelled = '1';
            finish(box, field);
            box.focus();
        }
    }, { capture: true });
})();
