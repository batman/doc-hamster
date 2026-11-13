// -------------------------------------------------------------------------------------------------
// Text documents (point 21): "Neues Dokument" creates one and opens its editor as a window; the
// editor saves with its button or Ctrl+S in the background and shows whether something is unsaved.
// Formatted text: contenteditable with a small toolbar. Size, font, alignment and indent become
// classes (rt-*), because the CSP forbids inline styles; the server sanitizes again (RichText).
// -------------------------------------------------------------------------------------------------
(() => {
    const DocHamster = window.DocHamster;
    const json = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    // Create: the editor opens as a window right of the folder (window system), else as a page.
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-text-create]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: json })
            .then(response => (response.ok ? response.json() : Promise.reject(response)))
            .then(data => {
                form.closest('[data-dialog]')?.setAttribute('hidden', '');
                form.reset();
                const win = form.closest('[data-windows] > section[data-window]');
                if (!win) {
                    window.location.href = data.url;
                    return;
                }
                const link = document.createElement('a');
                link.href = data.url;
                link.dataset.window = '';
                link.hidden = true;
                win.append(link);
                link.click();
                link.remove();
            })
            .catch(() => form.submit())
            .finally(() => { button.disabled = false; });
    });
    document.addEventListener('click', event => {
        const opener = event.target.closest('[data-dialog-open^="new-text-"]');
        if (opener) {
            const scope = opener.closest('section[data-window]') ?? document;
            setTimeout(() => scope.querySelector(`[data-dialog="${opener.dataset.dialogOpen}"] input[name="name"]`)?.focus());
        }
    });

    const BLOCK = 'p, div, li';
    const FACES = { sans: 'sans-serif', serif: 'serif', mono: 'monospace' };
    const fontClass = face => {
        const f = (face || '').toLowerCase();
        if (f.includes('mono') || f.includes('courier')) {
            return 'rt-font-mono';
        }
        if (f.includes('sans') || f.includes('arial') || f.includes('helvetica')) {
            return 'rt-font-sans';
        }
        if (f.includes('serif') || f.includes('times') || f.includes('georgia')) {
            return 'rt-font-serif';
        }
        return null;
    };

    const setupRich = (form, area, changed) => {
        const toolbar = form.querySelector('.rt-toolbar');
        const exec = (command, value = null) => {
            area.focus();
            document.execCommand('styleWithCSS', false, false);
            document.execCommand('defaultParagraphSeparator', false, 'p');
            document.execCommand(command, false, value);
        };
        // Loose text at the top level gets a paragraph, so alignment and indent have a block.
        const wrapLoose = () => {
            let run = null;
            [...area.childNodes].forEach(node => {
                const loose = node.nodeType === Node.TEXT_NODE
                    ? (node.textContent.trim() !== '' || run !== null)
                    : node.nodeType === Node.ELEMENT_NODE && !node.matches('p, div, ul, ol');
                if (!loose) {
                    run = null;
                    return;
                }
                if (!run) {
                    run = document.createElement('p');
                    node.before(run);
                }
                run.append(node);
            });
            if (!area.firstChild) {
                area.innerHTML = '<p><br></p>';
            }
        };
        wrapLoose();

        const blockOf = node => {
            let el = node?.nodeType === Node.ELEMENT_NODE ? node : node?.parentElement;
            while (el && el !== area && !el.matches(BLOCK)) {
                el = el.parentElement;
            }
            return el && el !== area && area.contains(el) ? el : null;
        };
        const range = () => {
            const sel = getSelection();
            return sel.rangeCount && area.contains(sel.getRangeAt(0).commonAncestorContainer) ? sel.getRangeAt(0) : null;
        };
        const blocks = () => {
            let r = range();
            if (!r) {
                return [];
            }
            const nodes = () => {
                const out = [r.startContainer, r.endContainer];
                const walker = document.createTreeWalker(area, NodeFilter.SHOW_TEXT);
                while (walker.nextNode()) {
                    if (r.intersectsNode(walker.currentNode)) {
                        out.push(walker.currentNode);
                    }
                }
                return out;
            };
            if (nodes().some(node => node !== area && !blockOf(node))) {
                exec('formatBlock', 'p');
                r = range();
                if (!r) {
                    return [];
                }
            }
            return [...new Set(nodes().map(blockOf).filter(Boolean))];
        };
        const setClass = (el, prefix, value) => {
            [...el.classList].filter(name => name.startsWith(prefix)).forEach(name => el.classList.remove(name));
            if (value) {
                el.classList.add(value);
            }
        };
        const indentOf = el => Number([...el.classList].find(name => name.startsWith('rt-indent-'))?.slice(10) ?? 0);

        // The browser writes <font size|face>; we turn it into a span with our class and keep the selection.
        const fontsToClasses = () => {
            const spans = [...area.querySelectorAll('font')].map(font => {
                const span = document.createElement('span');
                const size = Number(font.getAttribute('size'));
                if (size >= 1 && size <= 7) {
                    span.classList.add(`rt-size-${size}`);
                }
                const face = fontClass(font.getAttribute('face'));
                if (face) {
                    span.classList.add(face);
                }
                span.append(...font.childNodes);
                font.replaceWith(span);
                return span;
            });
            if (spans.length) {
                const r = document.createRange();
                r.setStartBefore(spans[0]);
                r.setEndAfter(spans.at(-1));
                getSelection().removeAllRanges();
                getSelection().addRange(r);
            }
        };

        // Pressed buttons follow the text at the caret.
        const marks = () => {
            const r = range();
            if (!r) {
                return;
            }
            toolbar.querySelectorAll('[data-rt="bold"], [data-rt="italic"], [data-rt="underline"]').forEach(button => {
                button.setAttribute('aria-pressed', String(document.queryCommandState(button.dataset.rt)));
            });
            const block = blockOf(r.startContainer);
            const align = [...(block?.classList ?? [])].find(name => name.startsWith('rt-align-'))?.slice(9) ?? 'left';
            toolbar.querySelectorAll('[data-rt="align"]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.rtValue === align)));
        };

        const run = (command, value) => {
            switch (command) {
                case 'bold':
                case 'italic':
                case 'underline':
                    exec(command);
                    break;
                case 'bullets':
                    exec('insertUnorderedList');
                    break;
                case 'numbers':
                    exec('insertOrderedList');
                    break;
                case 'indent':
                case 'outdent': {
                    const found = blocks();
                    if (found.some(el => el.matches('li'))) {
                        exec(command);
                        break;
                    }
                    found.forEach(el => {
                        const level = Math.min(8, Math.max(0, indentOf(el) + (command === 'indent' ? 1 : -1)));
                        setClass(el, 'rt-indent-', level ? `rt-indent-${level}` : null);
                    });
                    break;
                }
                case 'align':
                    blocks().forEach(el => setClass(el, 'rt-align-', value === 'left' ? null : `rt-align-${value}`));
                    break;
                case 'font':
                    exec('fontName', FACES[value] ?? value);
                    fontsToClasses();
                    break;
                case 'size':
                    exec('fontSize', value);
                    fontsToClasses();
                    break;
                default:
                    return;
            }
            area.querySelectorAll('[style]').forEach(el => el.removeAttribute('style'));
            changed();
            marks();
        };

        document.addEventListener('selectionchange', marks);
        DocHamster.on('window:close', root => {
            if (root.contains(form)) {
                document.removeEventListener('selectionchange', marks);
            }
        });

        // A click on the toolbar keeps the selection in the text.
        toolbar.addEventListener('mousedown', event => {
            if (event.target.closest('button')) {
                event.preventDefault();
            }
        });
        toolbar.addEventListener('click', event => {
            const button = event.target.closest('button[data-rt]');
            if (button) {
                run(button.dataset.rt, button.dataset.rtValue);
            }
        });
        // The selection is lost while a select is open; it is put back before the command.
        let kept = null;
        toolbar.addEventListener('pointerdown', event => {
            if (event.target.closest('select')) {
                kept = range()?.cloneRange() ?? kept;
            }
        });
        toolbar.addEventListener('change', event => {
            const select = event.target.closest('select');
            if (!select || select.value === '') {
                return;
            }
            area.focus();
            if (kept) {
                getSelection().removeAllRanges();
                getSelection().addRange(kept);
            }
            run(select.matches('[data-rt-font]') ? 'font' : 'size', select.value);
            select.value = '';
        });
        // Pasted text comes in as plain text: no foreign styles, images or links.
        area.addEventListener('paste', event => {
            event.preventDefault();
            document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') ?? '');
        });
        area.addEventListener('drop', event => event.preventDefault());

        return () => area.innerHTML;
    };

    const dirty = new Set();
    const setupEditor = form => {
        if (form.dataset.textReady) {
            return;
        }
        form.dataset.textReady = '1';
        const win = form.closest('section[data-window]') ?? document;
        const state = win.querySelector('[data-text-state]');
        const show = (key, isDirty = false, isError = false) => {
            if (state) {
                state.textContent = key ? state.dataset[key] : '';
                state.classList.toggle('is-dirty', isDirty);
                state.classList.toggle('is-error', isError);
            }
        };
        const rich = form.querySelector('[data-rt-area]');
        const plain = form.querySelector('[data-text-area]');
        let current = () => plain?.value ?? '';
        let saved = '';
        let everSaved = false;
        const changed = () => {
            const isDirty = current() !== saved;
            if (isDirty) {
                dirty.add(form);
            } else {
                dirty.delete(form);
            }
            show(isDirty ? 'unsaved' : (everSaved ? 'saved' : ''), isDirty);
        };
        if (rich) {
            current = setupRich(form, rich, changed);
        }
        saved = current();
        form.addEventListener('input', changed);

        let busy = false;
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (busy) {
                return;
            }
            const sent = current();
            const field = form.querySelector('[data-rt-content]');
            if (field) {
                field.value = sent;
            }
            busy = true;
            show('saving', true);
            fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: json })
                .then(response => (response.ok ? response.json() : Promise.reject(response)))
                .then(() => {
                    saved = sent;
                    everSaved = true;
                    changed();
                })
                .catch(error => {
                    show('failed', true, true);
                    if (error instanceof Response) {
                        error.json().then(data => {
                            if (state && data?.message) {
                                state.textContent = data.message;
                            }
                        }).catch(() => { /* the short message stays */ });
                    }
                })
                .finally(() => { busy = false; });
        });
        form.addEventListener('keydown', event => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                form.requestSubmit();
            }
        });
        // Ctrl+S also while the save button in the window toolbar has the focus.
        if (win !== document) {
            win.querySelector('.win-tools')?.addEventListener('keydown', event => {
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                    event.preventDefault();
                    form.requestSubmit();
                }
            });
        }
        DocHamster.on('window:close', root => {
            if (root.contains(form)) {
                dirty.delete(form);
            }
        });
    };

    DocHamster.on('window:open', root => root.querySelectorAll('form[data-text-editor]').forEach(setupEditor));
    window.addEventListener('beforeunload', event => {
        if ([...dirty].some(form => form.isConnected)) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();
